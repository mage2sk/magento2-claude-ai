<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

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

            $cost = ($in + $out + $cacheRead + $cacheWrite) > 0
                ? $this->pricing->costFor($this->config->getModel(), $in, $out, $cacheRead, $cacheWrite)
                : null;

            $userId = null;
            if ($surface === 'admin') {
                try {
                    $u = $this->adminSession->getUser();
                    $userId = $u ? (int) $u->getId() : null;
                } catch (\Throwable) {
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
