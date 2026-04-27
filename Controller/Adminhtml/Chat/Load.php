<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Hydrates one previous conversation so the chat UI can resume it.
 *
 * Returns the message history in Anthropic Messages-API shape — array of
 * {role, content} where each content item is the original block(s) the
 * assistant/user/tool exchanged. The chat JS feeds this back into its
 * `conversation` array and visually replays the bubbles.
 *
 * Read-only — no CSRF mutation, just GET. Hidden behind ai_chat ACL.
 */
class Load extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_chat';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ResourceConnection $resource,
        private readonly AdminSession $adminSession,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
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

            // Scope to the current admin user — admin A must not be able to
            // read admin B's private chats by guessing the conversation_id.
            // Legacy rows (pre-1.6 backfill) carry NULL admin_user_id and
            // remain visible to anyone with ai_chat ACL — they were never
            // tied to a specific user.
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

            // Re-shape into Anthropic Messages-API format. We collapse adjacent
            // assistant text + tool_use blocks back into a single message, and
            // adjacent tool_result blocks into a single user message — that's
            // exactly the shape the orchestrator expects on the next turn.
            $messages = [];
            foreach ($rows as $r) {
                $role = (string) $r['role'];
                $decoded = json_decode((string) $r['content_json'], true);
                if (!is_array($decoded)) {
                    continue;
                }
                // record() stored either a single block or an array of blocks.
                $content = isset($decoded['type']) ? [$decoded] : $decoded;

                // Map our internal roles back to user/assistant.
                $apiRole = match ($role) {
                    'assistant', 'tool_use' => 'assistant',
                    'tool_result', 'user'   => 'user',
                    default                  => null,
                };
                if ($apiRole === null) {
                    continue; // 'system' is rebuilt on send, skip
                }

                // Merge into the previous message if same role.
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

            // Stats for the UI header.
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
