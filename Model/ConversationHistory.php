<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\App\ResourceConnection;

class ConversationHistory
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function resolve(string $requestedId, int $adminUserId): array
    {
        $requestedId = trim($requestedId);
        if ($adminUserId > 0
            && preg_match(self::ID_PATTERN, $requestedId) === 1
            && !$this->hasForeignMessages($requestedId, $adminUserId)
        ) {
            $loaded = $this->load($requestedId, $adminUserId);
            return [
                'conversation_id' => $requestedId,
                'history'         => $loaded['messages'],
                'next_sequence'   => $loaded['next_sequence'],
            ];
        }

        return [
            'conversation_id' => bin2hex(random_bytes(8)),
            'history'         => [],
            'next_sequence'   => 0,
        ];
    }

    public function hasForeignMessages(string $conversationId, int $adminUserId): bool
    {
        $conn = $this->resource->getConnection();
        $found = $conn->fetchOne(
            $conn->select()
                ->from($this->resource->getTableName('panth_claudeai_message'), ['message_id'])
                ->where('conversation_id = ?', $conversationId)
                ->where('admin_user_id IS NULL OR admin_user_id <> ?', $adminUserId)
                ->limit(1)
        );
        return $found !== false && $found !== null;
    }

    public function load(string $conversationId, int $adminUserId): array
    {
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchAll(
            $conn->select()
                ->from($this->resource->getTableName('panth_claudeai_message'), ['role', 'content_json', 'sequence'])
                ->where('conversation_id = ?', $conversationId)
                ->where('admin_user_id = ?', $adminUserId)
                ->order(['sequence ASC', 'message_id ASC'])
        );

        $messages = [];
        $nextSequence = 0;
        foreach ($rows as $row) {
            $nextSequence = max($nextSequence, (int) $row['sequence'] + 1);
            $apiRole = match ((string) $row['role']) {
                'assistant', 'tool_use' => 'assistant',
                'user', 'tool_result'   => 'user',
                default                 => null,
            };
            if ($apiRole === null) {
                continue;
            }
            $decoded = json_decode((string) $row['content_json'], true);
            if (!is_array($decoded) || $decoded === []) {
                continue;
            }
            $content = isset($decoded['type']) ? [$decoded] : array_values($decoded);

            $last = $messages === [] ? null : array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $apiRole) {
                $messages[$last]['content'] = array_merge($messages[$last]['content'], $content);
            } else {
                $messages[] = ['role' => $apiRole, 'content' => $content];
            }
        }

        $messages = $this->pairToolBlocks($messages);

        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        while ($messages !== []) {
            $last = $messages[array_key_last($messages)];
            if ($last['role'] === 'user' || $this->blockIds($last['content'], 'tool_use', 'id') !== []) {
                array_pop($messages);
                continue;
            }
            break;
        }

        return ['messages' => $messages, 'next_sequence' => $nextSequence];
    }

    private function pairToolBlocks(array $messages): array
    {
        $clean = [];
        foreach ($messages as $i => $message) {
            $content = $message['content'];
            if ($message['role'] === 'assistant') {
                $useIds = $this->blockIds($content, 'tool_use', 'id');
                $next = $messages[$i + 1] ?? null;
                $resultIds = $next !== null && $next['role'] === 'user'
                    ? $this->blockIds($next['content'], 'tool_result', 'tool_use_id')
                    : [];
                if ($useIds !== [] && array_diff($useIds, $resultIds) !== []) {
                    $content = $this->withoutType($content, 'tool_use');
                }
            } else {
                $previous = $clean === [] ? null : $clean[array_key_last($clean)];
                $allowed = $previous !== null && $previous['role'] === 'assistant'
                    ? $this->blockIds($previous['content'], 'tool_use', 'id')
                    : [];
                $content = array_values(array_filter(
                    $content,
                    fn ($block) => !is_array($block)
                        || ($block['type'] ?? null) !== 'tool_result'
                        || in_array((string) ($block['tool_use_id'] ?? ''), $allowed, true)
                ));
            }
            if ($content === []) {
                continue;
            }
            $last = $clean === [] ? null : array_key_last($clean);
            if ($last !== null && $clean[$last]['role'] === $message['role']) {
                $clean[$last]['content'] = array_merge($clean[$last]['content'], $content);
            } else {
                $clean[] = ['role' => $message['role'], 'content' => $content];
            }
        }
        return $clean;
    }

    private function blockIds(array $content, string $type, string $key): array
    {
        $ids = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === $type) {
                $ids[] = (string) ($block[$key] ?? '');
            }
        }
        return $ids;
    }

    private function withoutType(array $content, string $type): array
    {
        return array_values(array_filter(
            $content,
            static fn ($block) => !is_array($block) || ($block['type'] ?? null) !== $type
        ));
    }
}
