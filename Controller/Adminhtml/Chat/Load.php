<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\ClaudeAi\Model\Config;
use Psr\Log\LoggerInterface;

class Load extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_chat';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ResourceConnection $resource,
        private readonly AdminSession $adminSession,
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        if (!$this->config->isEnabled()) {
            return $result->setData(['success' => false, 'error' => 'Claude AI is disabled.']);
        }
        $cid = (string) $this->getRequest()->getParam('cid', '');
        if ($cid === '') {
            $cid = (string) $this->getRequest()->getParam('id', '');
        }
        if ($cid === '') {
            return $result->setData(['success' => false, 'error' => 'conversation_id is required.']);
        }

        try {
            $conn  = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_claudeai_message');

            $u = $this->adminSession->getUser();
            $userId = $u ? (int) $u->getId() : 0;
            $rows = $conn->fetchAll(
                "SELECT role, content_json, sequence, created_at, input_tokens,
                        output_tokens, cost_usd, model
                   FROM {$table}
                  WHERE conversation_id = ?
                    AND (admin_user_id = ? OR admin_user_id IS NULL)
                  ORDER BY sequence ASC, message_id ASC",
                [$cid, $userId]
            );
            if (!$rows) {
                return $result->setData(['success' => false, 'error' => 'Conversation not found.']);
            }

            $messages = [];
            foreach ($rows as $r) {
                $role = (string) $r['role'];
                $decoded = json_decode((string) $r['content_json'], true);
                if (!is_array($decoded)) {
                    continue;
                }

                $content = isset($decoded['type']) ? [$decoded] : $decoded;

                $apiRole = match ($role) {
                    'assistant', 'tool_use' => 'assistant',
                    'tool_result', 'user'   => 'user',
                    default                  => null,
                };
                if ($apiRole === null) {
                    continue;
                }

                $last = $messages ? array_key_last($messages) : null;
                if ($last !== null && $messages[$last]['role'] === $apiRole) {
                    $messages[$last]['content'] = array_merge(
                        is_array($messages[$last]['content']) ? $messages[$last]['content'] : [['type' => 'text', 'text' => (string) $messages[$last]['content']]],
                        $content
                    );
                } else {
                    $messages[] = ['role' => $apiRole, 'content' => $content];
                }
            }

            $totals = [
                'turns'      => count($rows),
                'started_at' => (string) ($rows[0]['created_at'] ?? ''),
                'last_at'    => (string) ($rows[count($rows) - 1]['created_at'] ?? ''),
                'cost_usd'   => 0.0,
            ];
            foreach ($rows as $r) {
                $totals['cost_usd'] += (float) ($r['cost_usd'] ?? 0);
            }
            $totals['cost_usd'] = round($totals['cost_usd'], 6);

            return $result->setData([
                'success'         => true,
                'conversation_id' => $cid,
                'messages'        => $messages,
                'stats'           => $totals,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[panth_claudeai] chat/load: ' . $e->getMessage());
            return $result->setData(['success' => false, 'error' => 'Failed to load conversation.']);
        }
    }
}
