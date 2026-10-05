<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Panth\ClaudeAi\Cron\Cleanup;
use Panth\ClaudeAi\Model\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupTest extends TestCase
{
    private array $deletes = [];
    private array $deletedFiles = [];

    private function cron(
        array $existingTables,
        array $attachmentRows = [],
        ?LoggerInterface $logger = null,
        ?WriteInterface $dir = null,
        ?\Throwable $connectionFailure = null
    ): Cleanup {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(fn(string $t) => in_array($t, $existingTables, true));
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($attachmentRows);
        $connection->method('delete')->willReturnCallback(function (string $table, $where) {
            $this->deletes[] = [$table, $where];
            return 2;
        });

        $resource = $this->createStub(ResourceConnection::class);
        if ($connectionFailure !== null) {
            $resource->method('getConnection')->willThrowException($connectionFailure);
        } else {
            $resource->method('getConnection')->willReturn($connection);
        }
        $resource->method('getTableName')->willReturnArgument(0);

        $config = $this->createStub(Config::class);
        $config->method('getLogRetentionDays')->willReturn(90);
        $config->method('getCheckpointRetentionDays')->willReturn(30);

        if ($dir === null) {
            $dir = $this->createStub(WriteInterface::class);
            $dir->method('isFile')->willReturnCallback(fn(string $p) => $p !== 'panth/claudeai/gone.txt');
            $dir->method('delete')->willReturnCallback(function (string $p) {
                $this->deletedFiles[] = $p;
                return true;
            });
        }
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturnCallback(function ($code) use ($dir) {
            $this->assertSame(DirectoryList::VAR_DIR, $code);
            return $dir;
        });

        return new Cleanup($resource, $config, $logger ?? $this->createStub(LoggerInterface::class), $filesystem);
    }

    public function testPrunesAllTablesWithRetentionCutoffs(): void
    {
        $this->cron(['panth_claudeai_activity', 'panth_claudeai_message', 'panth_claudeai_checkpoint'])->execute();

        $this->assertSame(
            ['panth_claudeai_activity', 'panth_claudeai_message', 'panth_claudeai_checkpoint'],
            array_column($this->deletes, 0)
        );
        $activityCutoff = strtotime($this->deletes[0][1]['created_at < ?']);
        $this->assertEqualsWithDelta(strtotime('-90 days'), $activityCutoff, 5);
        $this->assertSame('active', $this->deletes[2][1]['status = ?']);
        $this->assertEqualsWithDelta(strtotime('-30 days'), strtotime($this->deletes[2][1]['created_at < ?']), 5);
    }

    public function testMissingTablesAreSkipped(): void
    {
        $this->cron([])->execute();
        $this->assertSame([], $this->deletes);
    }

    public function testOldAttachmentsAreDeletedOnlyWhenPathIsSafe(): void
    {
        $rows = [
            ['attachment_id' => '1', 'stored_path' => 'panth/claudeai/a.png'],
            ['attachment_id' => '2', 'stored_path' => 'panth/claudeai/../env.php'],
            ['attachment_id' => '3', 'stored_path' => 'panth/claudeai/sub/b.png'],
            ['attachment_id' => '4', 'stored_path' => 'other/c.png'],
            ['attachment_id' => '5', 'stored_path' => 'panth/claudeai/gone.txt'],
        ];

        $this->cron(['panth_claudeai_attachment'], $rows)->execute();

        $this->assertSame(['panth/claudeai/a.png'], $this->deletedFiles);
        $this->assertSame([['panth_claudeai_attachment', ['attachment_id IN (?)' => [1, 2, 3, 4, 5]]]], $this->deletes);
    }

    public function testNoOldAttachmentsMeansNoDelete(): void
    {
        $this->cron(['panth_claudeai_attachment'], [])->execute();
        $this->assertSame([], $this->deletes);
    }

    public function testFileDeleteFailureIsLoggedAndRowsStillPruned(): void
    {
        $dir = $this->createStub(WriteInterface::class);
        $dir->method('isFile')->willReturn(true);
        $dir->method('delete')->willThrowException(new \RuntimeException('permission denied'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('permission denied'));

        $this->cron(['panth_claudeai_attachment'], [['attachment_id' => 9, 'stored_path' => 'panth/claudeai/x.png']], $logger, $dir)
            ->execute();

        $this->assertSame([['panth_claudeai_attachment', ['attachment_id IN (?)' => [9]]]], $this->deletes);
    }

    public function testUnexpectedFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('[panth_claudeai] cleanup cron failed: db down');

        $this->cron([], [], $logger, null, new \RuntimeException('db down'))->execute();
    }
}
