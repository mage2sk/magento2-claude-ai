<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Activity;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Panth\ClaudeAi\Model\Config;
use Psr\Log\LoggerInterface;

class Logger
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AdminSession $adminSession,
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    public function log(array $row): void
    {
        try {
            if (!$this->config->isLoggingEnabled()) {
                return;
            }
            $conn  = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_claudeai_activity');

            $userId = null;
            try {
                $user = $this->adminSession->getUser();
                $userId = $user ? (int) $user->getId() : null;
            } catch (\Throwable) {
            }

            $data = array_merge([
                'conversation_id' => null,
                'actor_type'      => 'admin',
                'action'          => 'unknown',
                'prompt'          => null,
                'result'          => null,
                'status'          => 'success',
                'affected_count'  => 0,
                'duration_ms'     => null,
                'input_tokens'    => null,
                'output_tokens'   => null,
                'cache_read_tokens' => null,
                'admin_user_id'   => $userId,
            ], $row);

            $conn->insert($table, $data);
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_claudeai] activity logger failed: ' . $e->getMessage());
        }
    }
}
