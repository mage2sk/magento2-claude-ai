<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Panth\ClaudeAi\Model\AttachmentStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttachmentStorageTest extends TestCase
{
    private function build(array $files = [], array $rows = [], ?WriteInterface $dir = null): AttachmentStorage
    {
        if ($dir === null) {
            $dir = $this->createStub(WriteInterface::class);
            $dir->method('isFile')->willReturnCallback(fn(string $p) => array_key_exists($p, $files));
            $dir->method('readFile')->willReturnCallback(fn(string $p) => $files[$p]);
            $dir->method('getAbsolutePath')->willReturnCallback(fn(string $p) => '/var/www/var/' . $p);
        }
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturnCallback(
            function (string $code) use ($dir) {
                $this->assertSame(DirectoryList::VAR_DIR, $code);
                return $dir;
            }
        );

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $wheres = [];
        $select->method('where')->willReturnCallback(function (string $cond, $value) use (&$wheres, $select) {
            $wheres[$cond] = $value;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(function () use (&$wheres, $rows) {
            $id = $wheres['attachment_id = ?'] ?? 0;
            $row = $rows[$id] ?? false;
            if ($row && (int) $row['admin_user_id'] !== (int) ($wheres['admin_user_id = ?'] ?? 0)) {
                return false;
            }
            return $row;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new AttachmentStorage($filesystem, $resource);
    }

    #[DataProvider('pathProvider')]
    public function testIsSafePath(string $path, bool $expected): void
    {
        $this->assertSame($expected, $this->build()->isSafePath($path));
    }

    public static function pathProvider(): array
    {
        return [
            'plain file' => ['panth/claudeai/logo_ab12.png', true],
            'wrong dir' => ['panth/other/logo.png', false],
            'prefix only' => ['panth/claudeai/', false],
            'subdir' => ['panth/claudeai/sub/x.png', false],
            'traversal' => ['panth/claudeai/..x', false],
            'backslash' => ['panth/claudeai/a\\b', false],
            'null byte' => ["panth/claudeai/a\0b", false],
            'dotfile' => ['panth/claudeai/.htaccess', false],
            'absolute' => ['/panth/claudeai/x.png', false],
        ];
    }

    public function testAbsolutePathOnlyForSafePaths(): void
    {
        $storage = $this->build();
        $this->assertSame('/var/www/var/panth/claudeai/a.png', $storage->getAbsolutePath('panth/claudeai/a.png'));
        $this->assertNull($storage->getAbsolutePath('../etc/passwd'));
    }

    public function testReadReturnsContentsOrNull(): void
    {
        $storage = $this->build(['panth/claudeai/a.txt' => 'hello']);

        $this->assertSame('hello', $storage->read('panth/claudeai/a.txt'));
        $this->assertNull($storage->read('panth/claudeai/missing.txt'));
        $this->assertNull($storage->read('panth/claudeai/../a.txt'));
    }

    public function testReadSwallowsFilesystemErrors(): void
    {
        $dir = $this->createStub(WriteInterface::class);
        $dir->method('isFile')->willThrowException(new \RuntimeException('io'));

        $this->assertNull($this->build([], [], $dir)->read('panth/claudeai/a.txt'));
    }

    public function testGetOwnedRequiresPositiveIdsAndOwnership(): void
    {
        $row = ['attachment_id' => 3, 'admin_user_id' => 7, 'stored_path' => 'panth/claudeai/a.txt'];
        $storage = $this->build([], [3 => $row]);

        $this->assertSame($row, $storage->getOwned(3, 7));
        $this->assertNull($storage->getOwned(3, 8));
        $this->assertNull($storage->getOwned(4, 7));
        $this->assertNull($storage->getOwned(0, 7));
        $this->assertNull($storage->getOwned(3, 0));
    }

    public function testIngestibleAndInlineMime(): void
    {
        $storage = $this->build();

        $this->assertTrue($storage->isIngestible('panth/claudeai/a.PNG'));
        $this->assertTrue($storage->isIngestible('panth/claudeai/a.csv'));
        $this->assertFalse($storage->isIngestible('panth/claudeai/a.zip'));
        $this->assertFalse($storage->isIngestible('panth/claudeai/noext'));
        $this->assertTrue($storage->isInlineMime('image/webp'));
        $this->assertFalse($storage->isInlineMime('image/svg+xml'));
        $this->assertFalse($storage->isInlineMime('application/pdf'));
    }

    public function testPathNoteMentionsNameAndPath(): void
    {
        $note = $this->build()->getPathNote(['original_name' => 'Logo.png', 'stored_path' => 'panth/claudeai/logo_1.png']);
        $this->assertStringContainsString('attached a file: Logo.png', $note);
        $this->assertStringContainsString('upload path: panth/claudeai/logo_1.png', $note);
    }

    public function testBuildBlockForUnreadableType(): void
    {
        $block = $this->build()->buildBlock([
            'stored_path' => 'panth/claudeai/a.zip',
            'original_name' => 'a.zip',
            'mime_type' => 'application/zip',
            'size_bytes' => 4096,
        ]);

        $this->assertSame('text', $block['type']);
        $this->assertStringContainsString("can't read inside it: a.zip (application/zip, 4 KB)", $block['text']);
        $this->assertStringContainsString('saved at panth/claudeai/a.zip', $block['text']);
    }

    public function testBuildBlockForTextImageAndPdf(): void
    {
        $storage = $this->build([
            'panth/claudeai/a.csv' => "sku,qty\nA,1",
            'panth/claudeai/a.png' => 'PNGDATA',
            'panth/claudeai/a.pdf' => 'PDFDATA',
            'panth/claudeai/b.png' => 'X',
        ]);

        $text = $storage->buildBlock(['stored_path' => 'panth/claudeai/a.csv', 'original_name' => 'a.csv', 'mime_type' => 'text/csv']);
        $this->assertSame(['type' => 'text', 'text' => "[Attached file: a.csv]\n\nsku,qty\nA,1"], $text);

        $image = $storage->buildBlock(['stored_path' => 'panth/claudeai/a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png']);
        $this->assertSame('image', $image['type']);
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('PNGDATA')], $image['source']);

        $pdf = $storage->buildBlock(['stored_path' => 'panth/claudeai/a.pdf', 'original_name' => 'a.pdf', 'mime_type' => 'application/pdf']);
        $this->assertSame('document', $pdf['type']);
        $this->assertSame(base64_encode('PDFDATA'), $pdf['source']['data']);

        $mismatch = $storage->buildBlock(['stored_path' => 'panth/claudeai/b.png', 'original_name' => 'b.png', 'mime_type' => 'text/plain']);
        $this->assertNull($mismatch);

        $missing = $storage->buildBlock(['stored_path' => 'panth/claudeai/gone.png', 'original_name' => 'g', 'mime_type' => 'image/png']);
        $this->assertNull($missing);
    }

    public function testBuildBlocksDedupesChecksOwnershipAndAddsPathNotes(): void
    {
        $rows = [
            1 => ['attachment_id' => 1, 'admin_user_id' => 7, 'stored_path' => 'panth/claudeai/a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png'],
            2 => ['attachment_id' => 2, 'admin_user_id' => 7, 'stored_path' => 'panth/claudeai/a.txt', 'original_name' => 'a.txt', 'mime_type' => 'text/plain'],
            3 => ['attachment_id' => 3, 'admin_user_id' => 9, 'stored_path' => 'panth/claudeai/c.txt', 'original_name' => 'c.txt', 'mime_type' => 'text/plain'],
            4 => ['attachment_id' => 4, 'admin_user_id' => 7, 'stored_path' => 'panth/claudeai/gone.txt', 'original_name' => 'gone.txt', 'mime_type' => 'text/plain'],
        ];
        $storage = $this->build(
            ['panth/claudeai/a.png' => 'IMG', 'panth/claudeai/a.txt' => 'notes', 'panth/claudeai/c.txt' => 'secret'],
            $rows
        );

        $blocks = $storage->buildBlocks(
            [['id' => 1], ['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 0], 'junk'],
            7
        );

        $this->assertCount(3, $blocks);
        $this->assertSame('image', $blocks[0]['type']);
        $this->assertStringContainsString('upload path: panth/claudeai/a.png', $blocks[1]['text']);
        $this->assertSame("[Attached file: a.txt]\n\nnotes", $blocks[2]['text']);
    }
}
