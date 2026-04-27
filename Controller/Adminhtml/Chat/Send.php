<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Orchestrator;
use Panth\ClaudeAi\Model\Pricing;
use Psr\Log\LoggerInterface;

/**
 * AJAX endpoint that drives one user→reply turn of the agentic loop.
 *
 * Request body (JSON):
 *   - message:        the user's prompt
 *   - history:        prior conversation as JSON array of {role, content}
 *   - conversation_id: short opaque ID used for activity-log grouping
 *   - form_key:       Magento CSRF token (validated below)
 *
 * Response (JSON):
 *   - text, tool_calls, usage, iterations, conversation, cost_usd, cost_display
 *
 * Implements CsrfAwareActionInterface so the JSON body's form_key is
 * validated explicitly. Without this, Magento's default CSRF gate would
 * reject the POST and return the admin login HTML — which is what was
 * causing the "Unexpected token '<'" error in the chat UI.
 */
class Send extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_chat';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly Orchestrator $orchestrator,
        private readonly Pricing $pricing,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Return true so the admin URL-key (secret URL key in admin paths) is
     * NOT enforced for our AJAX endpoint — required when the chat JS calls
     * us with a plain backend URL.
     */
    public function _processUrlKeys()
    {
        return true;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $r = $this->jsonFactory->create();
        $r->setHttpResponseCode(403)->setData([
            'success' => false,
            'error' => 'Your admin session has expired. Please refresh the page and try again.',
        ]);
        return new InvalidRequestException($r);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Read form_key from JSON body OR form-encoded params (so this works
        // for application/json requests where getParam() can't see it).
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
        if (!$key) {
            return false;
        }
        $sessionKey = $this->_getSession()->getFormKey();
        return hash_equals((string) $sessionKey, (string) $key);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            if (!$this->config->isEnabled()) {
                throw new \RuntimeException(
                    'Claude AI is disabled. Enable it in Stores → Configuration → Panth Extensions → Claude AI.'
                );
            }

            $body = $this->readBody();
            $message = trim((string) ($body['message'] ?? ''));
            $attachments = is_array($body['attachments'] ?? null) ? $body['attachments'] : [];

            if ($message === '' && empty($attachments)) {
                throw new \InvalidArgumentException('Message or attachment required.');
            }

            $history = is_array($body['history'] ?? null) ? $body['history'] : [];
            // ?? only catches null — JS sends '' on the first turn before it
            // has an ID, which would store every message under an empty
            // conversation_id and make the transcript view unreachable.
            // Treat empty/whitespace as missing too.
            $conversationId = trim((string) ($body['conversation_id'] ?? ''));
            if ($conversationId === '') {
                $conversationId = bin2hex(random_bytes(8));
            }

            // Build user content. If attachments are present, each carries
            // a `claude_block` shape from /chat/upload that we can pass
            // straight through. Plain text => string, mixed => content array.
            $userContent = $this->buildUserContent($message, $attachments);

            $reply = $this->orchestrator->run($history, $userContent, $conversationId);

            $u = $reply['usage'] ?? [];
            $costUsd = $this->pricing->costFor(
                $this->config->getModel(),
                (int) ($u['input'] ?? 0),
                (int) ($u['output'] ?? 0),
                (int) ($u['cache_read'] ?? 0),
                (int) ($u['cache_write'] ?? 0)
            );

            return $result->setData([
                'success'         => true,
                'conversation_id' => $conversationId,
                'text'            => $reply['text'],
                'tool_calls'      => $reply['tool_calls'],
                'usage'           => $reply['usage'],
                'iterations'      => $reply['iterations'],
                'conversation'    => $reply['conversation'],
                'cost_usd'        => $costUsd,
                'cost_display'    => $this->pricing->format($costUsd),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[panth_claudeai] chat send failed: ' . $e->getMessage());
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convert (text, [attachment, ...]) into Anthropic content blocks.
     * Each attachment is expected to carry a `claude_block` payload from
     * /chat/upload (image, document, or text marker for archives).
     */
    private function buildUserContent(string $text, array $attachments): array|string
    {
        if (empty($attachments)) {
            return $text;
        }
        $blocks = [];
        foreach ($attachments as $a) {
            if (!is_array($a)) {
                continue;
            }
            // Trust only the claude_block shape produced server-side by Upload.
            // (We never let the client invent arbitrary content blocks.)
            $block = $a['claude_block'] ?? null;
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
            if (!in_array($type, ['image', 'document', 'text'], true)) {
                continue;
            }
            $blocks[] = $block;
        }
        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        } elseif (empty(array_filter($blocks, fn($b) => ($b['type'] ?? '') === 'text'))) {
            // Anthropic requires at least one text block alongside images
            $blocks[] = ['type' => 'text', 'text' => 'Please look at the attachment(s) above and help me.'];
        }
        return $blocks;
    }

    /** Read JSON body, falling back to form params */
    private function readBody(): array
    {
        $raw = (string) $this->getRequest()->getContent();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $this->getRequest()->getParams();
    }
}
