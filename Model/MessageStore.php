<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Persists every message in a conversation — user prompts, assistant replies,
 * tool_use blocks, tool_result blocks — to panth_claudeai_message in order.
 *
 * Why this is its own table (not just `panth_claudeai_activity`):
 *   - Activity is a HUMAN AUDIT view (one row per discrete action with a
 *     summary). Message store is the raw API exchange — useful for replay,
 *     debugging, and exporting a full conversation.
 *   - Decoupling means we can prune them on different retention windows.
 *
 * Defensive: failures here are logged but never thrown — persistence must
 * never crash the chat loop.
 */
class MessageStore
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Pricing $pricing,
        private readonly Config $config,
        private readonly AdminSession $adminSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Persist one message turn.
     *
     * @param string $surface  'admin' | 'storefront'
     * @param string $role     'user'|'assistant'|'tool_use'|'tool_result'|'system'
     * @param mixed  $content  string OR an array of content blocks
     * @param array  $usage    {input_tokens, output_tokens, cache_read_tokens}
     */
    public function record(
        string $conversationId,
        int $sequence,
        string $role,
        $content,
        string $surface = 'admin',
        array $usage = []
    ): void {
        try {
            $contentJson = is_string($content)
                ? json_encode(['type' => 'text', 'text' => $content], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($contentJson === false) {
                return;
            }

            $in        = (int) ($usage['input_tokens']      ?? 0);
            $out       = (int) ($usage['output_tokens']     ?? 0);
            $cacheRead = (int) ($usage['cache_read_tokens'] ?? 0);
            $cacheWrite = (int) ($usage['cache_write_tokens'] ?? 0);
            // Include cache_creation tokens (1.25x input rate) — without
            // this the per-conversation cost shown in admin under-counts
            // by ~half on cache-heavy chats.
            $cost = ($in + $out + $cacheRead + $cacheWrite) > 0
                ? $this->pricing->costFor($this->config->getModel(), $in, $out, $cacheRead, $cacheWrite)
                : null;

            $userId = null;
            if ($surface === 'admin') {
                try {
                    $u = $this->adminSession->getUser();
                    $userId = $u ? (int) $u->getId() : null;
                } catch (\Throwable) {
                    // CLI / no session
                }
            }

            $this->resource->getConnection()->insert(
                $this->resource->getTableName('panth_claudeai_message'),
                [
                    'conversation_id'   => $conversationId,
                    'sequence'          => $sequence,
                    'role'              => $role,
                    'surface'           => $surface,
                    'content_json'      => $contentJson,
                    'input_tokens'       => $in ?: null,
                    'output_tokens'      => $out ?: null,
                    'cache_read_tokens'  => $cacheRead ?: null,
                    'cache_write_tokens' => $cacheWrite ?: null,
                    'cost_usd'           => $cost,
                    'model'             => $this->config->getModel(),
                    'admin_user_id'     => $userId,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_claudeai] message store failed: ' . $e->getMessage());
        }
    }
}
