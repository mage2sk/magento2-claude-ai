<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Panth\ClaudeAi\Setup\Patch\Data\BackfillEmptyConversationIds;
use Panth\ClaudeAi\Setup\Patch\Data\MoveChatUploadsToPrivateStorage;
use Panth\ClaudeAi\Setup\Patch\Data\SeedTrainingExamples;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DataPatchesTest extends TestCase
{
    private array $updates = [];
    private array $inserts = [];
    private array $deletedFiles = [];
    private array $copiedFiles = [];

    private function connection(array $existingTables, array $fetchAll = [], string $fetchOne = '0'): AdapterInterface
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(fn($t) => in_array($t, $existingTables, true));
        $connection->method('fetchAll')->willReturnCallback(fn(string $sql) => $fetchAll);
        $connection->method('fetchOne')->willReturn($fetchOne);
        $connection->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[] = [$table, $data, $where];
            return 1;
        });
        $connection->method('insert')->willReturnCallback(function ($table, $data) {
            $this->inserts[] = [$table, $data];
            return 1;
        });
        return $connection;
    }

    public function testBackfillAssignsOneLegacyIdPerAdminAndTable(): void
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn(
            $this->connection(['panth_claudeai_message'], [['uid' => '0'], ['uid' => '4']])
        );
        $resource->method('getTableName')->willReturnArgument(0);

        $patch = new BackfillEmptyConversationIds($resource);
        $this->assertSame($patch, $patch->apply());

        $this->assertCount(2, $this->updates);
        $this->assertSame('panth_claudeai_message', $this->updates[0][0]);
        $this->assertMatchesRegularExpression('/^legacy_[0-9a-f]{12}$/', $this->updates[0][1]['conversation_id']);
        $this->assertNotSame($this->updates[0][1]['conversation_id'], $this->updates[1][1]['conversation_id']);
        $this->assertSame(0, $this->updates[0][2]['IFNULL(admin_user_id, 0) = ?']);
        $this->assertSame(4, $this->updates[1][2]['IFNULL(admin_user_id, 0) = ?']);
        $this->assertSame([], BackfillEmptyConversationIds::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    public function testSeedSkipsWhenTableMissingOrPopulated(): void
    {
        foreach ([[[], '0'], [['panth_claudeai_training'], '3']] as [$tables, $count]) {
            $setup = $this->createMock(ModuleDataSetupInterface::class);
            $setup->expects($this->once())->method('startSetup');
            $setup->expects($this->once())->method('endSetup');
            $setup->method('getConnection')->willReturn($this->connection($tables, [], $count));
            $setup->method('getTable')->willReturnArgument(0);

            (new SeedTrainingExamples($setup))->apply();
        }
        $this->assertSame([], $this->inserts);
    }

    public function testSeedInsertsOrderedActiveExamples(): void
    {
        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($this->connection(['panth_claudeai_training']));
        $setup->method('getTable')->willReturnArgument(0);

        (new SeedTrainingExamples($setup))->apply();

        $this->assertNotEmpty($this->inserts);
        foreach ($this->inserts as $i => [$table, $row]) {
            $this->assertSame('panth_claudeai_training', $table);
            $this->assertSame($i * 10, $row['sort_order']);
            $this->assertSame(1, $row['status']);
            $this->assertSame(0, $row['usage_count']);
            $this->assertNotSame('', $row['title']);
            $this->assertNotSame('', $row['user_message']);
            $this->assertNotSame('', $row['expected_outcome']);
        }
    }

    private function moveFixture(array $mediaFiles, array $existingTargets, ?string $failingCopy = null): Filesystem
    {
        $listing = array_map(fn($n) => 'panth/claudeai/' . $n, array_keys($mediaFiles));

        $target = $this->createStub(WriteInterface::class);
        $target->method('isExist')->willReturnCallback(fn($p) => in_array($p, $existingTargets, true));

        $media = $this->createStub(WriteInterface::class);
        $media->method('isDirectory')->willReturn(true);
        $media->method('read')->willReturnCallback(function () use (&$listing) {
            return $listing;
        });
        $media->method('isFile')->willReturnCallback(fn($p) => ($mediaFiles[basename($p)] ?? false) === true);
        $media->method('delete')->willReturnCallback(function ($p) use (&$listing) {
            $this->deletedFiles[] = $p;
            $listing = array_values(array_diff($listing, [$p]));
            return true;
        });
        $media->method('copyFile')->willReturnCallback(function ($from, $to) use ($failingCopy) {
            if ($from === $failingCopy) {
                throw new \RuntimeException('read only');
            }
            $this->copiedFiles[] = [$from, $to];
            return true;
        });

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturnCallback(
            fn($code) => $code === DirectoryList::MEDIA ? $media : $target
        );

        return $filesystem;
    }

    public function testMoveDoesNothingWithoutLegacyDirectory(): void
    {
        $media = $this->createStub(WriteInterface::class);
        $media->method('isDirectory')->willReturn(false);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects($this->once())->method('getDirectoryWrite')->with(DirectoryList::MEDIA)->willReturn($media);

        $patch = new MoveChatUploadsToPrivateStorage(
            $this->createStub(ModuleDataSetupInterface::class),
            $filesystem,
            $this->createStub(LoggerInterface::class)
        );
        $this->assertSame($patch, $patch->apply());
    }

    public function testMoveCopiesFilesRenamesCollisionsAndCleansUp(): void
    {
        $filesystem = $this->moveFixture(
            ['a.png' => true, 'b.pdf' => true, '.htaccess' => true, 'sub' => false],
            ['panth/claudeai/b.pdf']
        );

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($this->connection([]));
        $setup->method('getTable')->willReturnArgument(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('[panth_claudeai] moved 2 chat uploads to private storage');

        (new MoveChatUploadsToPrivateStorage($setup, $filesystem, $logger))->apply();

        $this->assertSame(['panth/claudeai/a.png', 'panth/claudeai/a.png'], $this->copiedFiles[0]);
        $this->assertSame('panth/claudeai/b.pdf', $this->copiedFiles[1][0]);
        $this->assertMatchesRegularExpression('#^panth/claudeai/b_[0-9a-f]{8}\.pdf$#', $this->copiedFiles[1][1]);
        $this->assertSame(['panth/claudeai/a.png', 'panth/claudeai/b.pdf', 'panth/claudeai/.htaccess'], $this->deletedFiles);

        $this->assertCount(1, $this->updates);
        $this->assertSame('panth_claudeai_attachment', $this->updates[0][0]);
        $this->assertSame(['stored_path' => $this->copiedFiles[1][1]], $this->updates[0][1]);
        $this->assertSame(['stored_path = ?' => 'panth/claudeai/b.pdf'], $this->updates[0][2]);
    }

    public function testMoveFailureIsLoggedAndDirectoryKept(): void
    {
        $filesystem = $this->moveFixture(['a.png' => true], [], 'panth/claudeai/a.png');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('read only'));

        (new MoveChatUploadsToPrivateStorage($this->createStub(ModuleDataSetupInterface::class), $filesystem, $logger))->apply();

        $this->assertSame([], $this->deletedFiles);
    }
}
