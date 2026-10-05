<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Panth\ClaudeAi\Model\AttachmentStorage;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\ConversationHistory;
use Panth\ClaudeAi\Model\Orchestrator;
use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Model\RateLimiter;
use Panth\ClaudeAi\Model\WriteConfirmation;
use Psr\Log\LoggerInterface;

class Stream extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_chat';

    public function __construct(
        Context $context,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly Orchestrator $orchestrator,
        private readonly Pricing $pricing,
        private readonly LoggerInterface $logger,
        private readonly RateLimiter $rateLimiter,
        private readonly WriteConfirmation $writeConfirmation,
        private readonly ConversationHistory $conversationHistory,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly FormKey $formKey
    ) {
        parent::__construct($context);
    }

    public function _processUrlKeys()
    {
        $request = $this->getRequest();
        if ($this->validateForCsrf($request)) {
            return true;
        }
        $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
        $this->_actionFlag->set('', self::FLAG_NO_POST_DISPATCH, true);
        $result = $this->createCsrfValidationException($request)->getReplaceResult();
        $response = $this->getResponse();
        if ($result instanceof ResultInterface && $response instanceof HttpResponseInterface) {
            $result->renderResult($response);
        }
        return false;
    }

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
        return hash_equals((string) $this->formKey->getFormKey(), (string) $key);
    }

    public function execute()
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        @ini_set('implicit_flush', '1');

        $_SERVER['HTTP_ACCEPT'] = 'application/json, */*;q=0.1';

        @ignore_user_abort(false);
        while (ob_get_level() > 0) { @ob_end_clean(); }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $emit = static function (string $event, array $data = []): void {
            echo "event: {$event}\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
        };

        $emit('open', ['ts' => time()]);

        try {
            if (!$this->config->isEnabled()) {
                $emit('error', [
                    'message' => 'Claude AI is disabled. Enable it in Stores -> Configuration -> Panth Extensions -> Claude AI.',
                ]);
                return $this->rawFactory->create()->setContents('');
            }
            $body = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
            $message     = trim((string) ($body['message'] ?? ''));
            $attachments = is_array($body['attachments'] ?? null) ? $body['attachments'] : [];
            if ($message === '' && empty($attachments)) {
                $emit('error', ['message' => 'Message or attachment required.']);
                return $this->rawFactory->create()->setContents('');
            }
            if (!$this->rateLimiter->hit()) {
                $emit('error', ['message' => sprintf(
                    'Admin rate limit reached (%d questions per hour). Please try again later.',
                    $this->config->getAdminRateLimit()
                )]);
                return $this->rawFactory->create()->setContents('');
            }
            $this->writeConfirmation->setUserMessage($message);
            $adminUserId = $this->getAdminUserId();
            $resolved = $this->conversationHistory->resolve(
                (string) ($body['conversation_id'] ?? ''),
                $adminUserId
            );
            $cid = $resolved['conversation_id'];

            $userContent = $this->buildUserContent($message, $attachments, $adminUserId);

            $reply = $this->orchestrator->run(
                $resolved['history'],
                $userContent,
                $cid,
                $emit,
                $resolved['next_sequence']
            );

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

        $r = $this->rawFactory->create();
        return $r->setContents('');
    }

    private function getAdminUserId(): int
    {
        try {
            $user = $this->_auth->getUser();
            return $user ? (int) $user->getId() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function buildUserContent(string $text, array $attachments, int $adminUserId): array|string
    {
        if (empty($attachments)) {
            return $text;
        }
        $blocks = $this->attachmentStorage->buildBlocks($attachments, $adminUserId);
        if ($text !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        } elseif ($blocks === []) {
            $blocks[] = ['type' => 'text', 'text' => 'Please look at the attachment(s) above and help me.'];
        }
        return $blocks;
    }
}
