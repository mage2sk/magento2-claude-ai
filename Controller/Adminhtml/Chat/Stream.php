<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Orchestrator;
use Panth\ClaudeAi\Model\Pricing;
use Psr\Log\LoggerInterface;

/**
 * Server-Sent Events variant of /chat/send.
 *
 * Why a second controller (vs. switching Send.php to streaming):
 *   - Send.php returns JsonResult — clean for callers that want the
 *     final payload as one blob (CLI scripts, future API clients).
 *   - Stream emits text/event-stream, which the browser fetch+reader
 *     loop consumes for live progress updates ("Reading your message",
 *     "Calling get_products", "Got 47 products", etc.).
 *
 * Event types (`event: <name>`):
 *   - thinking      — { iteration, message }
 *   - tool_start    — { name, input }
 *   - tool_done     — { name, status, summary, count, ms }
 *   - writing_reply — { chars } (last assistant turn has the final text)
 *   - done          — full payload (text, tool_calls, usage, conversation_id, cost)
 *   - error         — { message }
 */
class Stream extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_chat';

    public function __construct(
        Context $context,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly Orchestrator $orchestrator,
        private readonly Pricing $pricing,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function _processUrlKeys() { return true; }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $r = $this->rawFactory->create();
        $r->setHttpResponseCode(403)->setContents('event: error' . "\n" . 'data: {"message":"session expired"}' . "\n\n");
        return new InvalidRequestException($r);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $key = $request->getParam('form_key');
        if (!$key) {
            $raw = (string) $request->getContent();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['form_key'])) {
                    $key = (string) $decoded['form_key'];
                }
            }
        }
        if (!$key) { return false; }
        return hash_equals((string) $this->_getSession()->getFormKey(), (string) $key);
    }

    public function execute()
    {
        // Send headers immediately and bypass Magento's framework response
        // pipeline — SSE needs to flush incrementally, which the standard
        // Result objects don't support.
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) { @ob_end_clean(); }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no'); // tells nginx not to buffer
        header('Connection: keep-alive');

        $emit = static function (string $event, array $data = []): void {
            echo "event: {$event}\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
        };

        // Heartbeat so reverse proxies don't kill the connection between
        // tool calls. SSE comments (lines starting with `:`) are ignored
        // by the EventSource spec but keep the socket alive.
        $emit('open', ['ts' => time()]);

        try {
            $body = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
            $message     = trim((string) ($body['message'] ?? ''));
            $attachments = is_array($body['attachments'] ?? null) ? $body['attachments'] : [];
            if ($message === '' && empty($attachments)) {
                $emit('error', ['message' => 'Message or attachment required.']);
                return;
            }
            $history = is_array($body['history'] ?? null) ? $body['history'] : [];
            $cid     = trim((string) ($body['conversation_id'] ?? ''));
            if ($cid === '') {
                $cid = bin2hex(random_bytes(8));
            }

            // Reuse Send.php's content-builder via the same shape.
            $userContent = $this->buildUserContent($message, $attachments);

            $reply = $this->orchestrator->run($history, $userContent, $cid, $emit);

            $u = $reply['usage'] ?? [];
            $costUsd = $this->pricing->costFor(
                $this->config->getModel(),
                (int) ($u['input'] ?? 0),
                (int) ($u['output'] ?? 0),
                (int) ($u['cache_read'] ?? 0),
                (int) ($u['cache_write'] ?? 0)
            );

            $emit('done', [
                'success'         => true,
                'conversation_id' => $cid,
                'text'            => $reply['text'],
                'tool_calls'      => $reply['tool_calls'],
                'usage'           => $reply['usage'],
                'iterations'      => $reply['iterations'],
                'conversation'    => $reply['conversation'],
                'cost_usd'        => $costUsd,
                'cost_display'    => $this->pricing->format($costUsd),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[panth_claudeai] stream error: ' . $e->getMessage());
            $emit('error', ['message' => $e->getMessage()]);
        }

        // Returning a Raw result with empty body; we already flushed everything.
        $r = $this->rawFactory->create();
        return $r->setContents('');
    }

    /** Mirrors Send::buildUserContent (kept duplicated to keep streaming controller self-contained). */
    private function buildUserContent(string $text, array $attachments): array|string
    {
        if (empty($attachments)) {
            return $text;
        }
        $blocks = [];
        foreach ($attachments as $a) {
            if (!is_array($a)) { continue; }
            $block = $a['claude_block'] ?? null;
            if (!is_array($block)) { continue; }
            $type = $block['type'] ?? '';
            if (!in_array($type, ['image', 'document', 'text'], true)) { continue; }
            $blocks[] = $block;
            if (in_array($type, ['image', 'document'], true) && !empty($a['path_note'])) {
                $blocks[] = ['type' => 'text', 'text' => (string) $a['path_note']];
            }
        }
        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }
        return $blocks;
    }
}
