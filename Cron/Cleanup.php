<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Cron;

use Magento\Framework\App\ResourceConnection;
use Panth\ClaudeAi\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Daily housekeeping: prune old activity rows + expired checkpoints.
 * Retention windows configured in admin. Defensive — never re-throws.
 */
class Cleanup
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $conn = $this->resource->getConnection();

            $actDays = $this->config->getLogRetentionDays();
            $actTable = $this->resource->getTableName('panth_claudeai_activity');
            if ($conn->isTableExists($actTable)) {
                $deleted = $conn->delete(
                    $actTable,
                    ['created_at < ?' => date('Y-m-d H:i:s', strtotime("-{$actDays} days"))]
                );
                $this->logger->info('[panth_claudeai] cleanup pruned ' . $deleted . ' activity rows older than ' . $actDays . ' days');
            }

            $chkDays = $this->config->getCheckpointRetentionDays();
            $chkTable = $this->resource->getTableName('panth_claudeai_checkpoint');
            if ($conn->isTableExists($chkTable)) {
                $deleted = $conn->delete(
                    $chkTable,
                    [
                        'status = ?' => 'active',
                        'created_at < ?' => date('Y-m-d H:i:s', strtotime("-{$chkDays} days")),
                    ]
                );
                $this->logger->info('[panth_claudeai] cleanup pruned ' . $deleted . ' checkpoints older than ' . $chkDays . ' days');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[panth_claudeai] cleanup cron failed: ' . $e->getMessage());
        }
    }
}
