<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Panth\ClaudeAi\Model\Config;
use Psr\Log\LoggerInterface;

class Cleanup
{
    private const UPLOAD_DIR = 'panth/claudeai/';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem
    ) {
    }

    public function execute(): void
    {
        try {
            $conn = $this->resource->getConnection();

            $actDays = $this->config->getLogRetentionDays();
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$actDays} days"));
            $actTable = $this->resource->getTableName('panth_claudeai_activity');
            if ($conn->isTableExists($actTable)) {
                $deleted = $conn->delete($actTable, ['created_at < ?' => $cutoff]);
                $this->logger->info('[panth_claudeai] cleanup pruned ' . $deleted . ' activity rows older than ' . $actDays . ' days');
            }

            $msgTable = $this->resource->getTableName('panth_claudeai_message');
            if ($conn->isTableExists($msgTable)) {
                $deleted = $conn->delete($msgTable, ['created_at < ?' => $cutoff]);
                $this->logger->info('[panth_claudeai] cleanup pruned ' . $deleted . ' chat messages older than ' . $actDays . ' days');
            }

            $this->pruneAttachments($cutoff);

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

    private function pruneAttachments(string $cutoff): void
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_attachment');
        if (!$conn->isTableExists($table)) {
            return;
        }
        $rows = $conn->fetchAll(
            $conn->select()->from($table, ['attachment_id', 'stored_path'])->where('created_at < ?', $cutoff)
        );
        if (!$rows) {
            return;
        }
        $uploads = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $ids = [];
        foreach ($rows as $row) {
            $path = (string) $row['stored_path'];
            $ids[] = (int) $row['attachment_id'];
            if (!str_starts_with($path, self::UPLOAD_DIR)
                || str_contains($path, '..')
                || str_contains(substr($path, strlen(self::UPLOAD_DIR)), '/')
            ) {
                continue;
            }
            try {
                if ($uploads->isFile($path)) {
                    $uploads->delete($path);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[panth_claudeai] cleanup could not delete ' . $path . ': ' . $e->getMessage());
            }
        }
        $deleted = $conn->delete($table, ['attachment_id IN (?)' => $ids]);
        $this->logger->info('[panth_claudeai] cleanup pruned ' . $deleted . ' uploads older than the retention window');
    }
}
