<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\File;
use Panth\ClaudeAi\Model\AttachmentStorage;
use Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\ContextBuilderTrait;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    use ContextBuilderTrait;

    private array $headers = [];
    private ?int $code = null;
    private ?string $contents = null;

    private function raw(): RawFactory
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use ($raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->code = $code;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($contents) use ($raw) {
            $this->contents = $contents;
            return $raw;
        });
        $factory = $this->createStub(RawFactory::class);
        $factory->method('create')->willReturn($raw);
        return $factory;
    }

    private function storage(?array $row, ?string $bytes, int $expectedUser = 7): AttachmentStorage
    {
        $storage = $this->createStub(AttachmentStorage::class);
        $storage->method('getOwned')->willReturnCallback(function (int $id, int $user) use ($row, $expectedUser) {
            return $id === 5 && $user === $expectedUser ? $row : null;
        });
        $storage->method('read')->willReturn($bytes);
        $storage->method('isInlineMime')->willReturnCallback(fn(string $m) => $m === 'image/png');
        return $storage;
    }

    public function testAclResource(): void
    {
        $this->assertSame('Panth_ClaudeAi::ai_chat', File::ADMIN_RESOURCE);
    }

    public function testUnknownOrForeignAttachmentIs404(): void
    {
        $controller = new File($this->buildContext($this->request(['id' => '6'])), $this->raw(), $this->storage(['x' => 1], 'data'));
        $controller->execute();

        $this->assertSame(404, $this->code);
        $this->assertSame('File not found.', $this->contents);
        $this->assertSame('private, no-store', $this->headers['Cache-Control']);
        $this->assertSame('nosniff', $this->headers['X-Content-Type-Options']);
    }

    public function testMissingFileOnDiskIs404(): void
    {
        $row = ['stored_path' => 'panth/claudeai/a.png', 'mime_type' => 'image/png', 'original_name' => 'a.png'];
        (new File($this->buildContext($this->request(['id' => '5'])), $this->raw(), $this->storage($row, null)))->execute();

        $this->assertSame(404, $this->code);
    }

    public function testImageIsServedInline(): void
    {
        $row = ['stored_path' => 'panth/claudeai/a.png', 'mime_type' => 'image/png', 'original_name' => 'my logo.png'];
        (new File($this->buildContext($this->request(['id' => '5'])), $this->raw(), $this->storage($row, 'PNG')))->execute();

        $this->assertNull($this->code);
        $this->assertSame('PNG', $this->contents);
        $this->assertSame('image/png', $this->headers['Content-Type']);
        $this->assertSame('inline; filename="my_logo.png"', $this->headers['Content-Disposition']);
        $this->assertStringContainsString('sandbox', $this->headers['Content-Security-Policy']);
    }

    public function testOtherTypesAreForcedToDownload(): void
    {
        $row = ['stored_path' => 'panth/claudeai/a.svg', 'mime_type' => 'image/svg+xml', 'original_name' => '"><script>.svg'];
        (new File($this->buildContext($this->request(['id' => '5'])), $this->raw(), $this->storage($row, '<svg/>')))->execute();

        $this->assertSame('application/octet-stream', $this->headers['Content-Type']);
        $this->assertSame('attachment; filename="___script_.svg"', $this->headers['Content-Disposition']);
    }

    public function testAnonymousSessionCannotFetch(): void
    {
        $row = ['stored_path' => 'panth/claudeai/a.png', 'mime_type' => 'image/png', 'original_name' => 'a.png'];
        (new File($this->buildContext($this->request(['id' => '5']), null), $this->raw(), $this->storage($row, 'PNG')))->execute();

        $this->assertSame(404, $this->code);
    }
}
