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
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\ClaudeAi\Model\AttachmentStorage;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\ConversationHistory;
use Panth\ClaudeAi\Model\Orchestrator;
use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Model\RateLimiter;
use Panth\ClaudeAi\Model\WriteConfirmation;
use Psr\Log\LoggerInterface;

class Send extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_chat';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
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
        $r = $this->jsonFactory->create();
        $r->setHttpResponseCode(403)->setData([
            'success' => false,
            'error' => 'Your admin session has expired. Please refresh the page and try again.',
        ]);
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
        if (!$key) {
            return false;
        }
        return hash_equals((string) $this->formKey->getFormKey(), (string) $key);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            if (!$this->config->isEnabled()) {
                throw new \RuntimeException(
                    'Claude AI is disabled. Enable it in Stores -> Configuration -> Panth Extensions -> Claude AI.'
                );
            }

            $body = $this->readBody();
            $message = trim((string) ($body['message'] ?? ''));
            $attachments = is_array($body['attachments'] ?? null) ? $body['attachments'] : [];

            if ($message === '' && empty($attachments)) {
                throw new \InvalidArgumentException('Message or attachment required.');
            }

            if (!$this->rateLimiter->hit()) {
                throw new \RuntimeException(sprintf(
                    'Admin rate limit reached (%d questions per hour). Please try again later.',
                    $this->config->getAdminRateLimit()
                ));
            }
            $this->writeConfirmation->setUserMessage($message);

            $adminUserId = $this->getAdminUserId();
            $resolved = $this->conversationHistory->resolve(
                (string) ($body['conversation_id'] ?? ''),
                $adminUserId
            );
            $conversationId = $resolved['conversation_id'];

            $userContent = $this->buildUserContent($message, $attachments, $adminUserId);

            $reply = $this->orchestrator->run(
                $resolved['history'],
                $userContent,
                $conversationId,
                null,
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
        } elseif (empty(array_filter($blocks, fn($b) => ($b['type'] ?? '') === 'text'))) {
            $blocks[] = ['type' => 'text', 'text' => 'Please look at the attachment(s) above and help me.'];
        }
        return $blocks;
    }

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
