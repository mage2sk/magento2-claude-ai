<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\SetStoreLogo;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SetStoreLogoTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $root;
    private array $saved = [];
    private array $cleaned = [];
    private CheckpointService $checkpoints;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/panth_logo_test_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/panth/claudeai', 0777, true);
        mkdir($this->root . '/media', 0777, true);
        file_put_contents($this->root . '/var/panth/claudeai/My Logo.png', base64_decode(self::PNG));
        file_put_contents($this->root . '/var/panth/claudeai/fake.png', 'not an image');
        file_put_contents($this->root . '/var/panth/outside.png', base64_decode(self::PNG));

        $this->checkpoints = $this->createStub(CheckpointService::class);
        $this->checkpoints->method('snapshotConfig')->willReturn('cp_logo');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } elseif (is_file($item->getPathname())) {
                unlink($item->getPathname());
            }
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    private function tool(bool $confirmRequired = false, bool $dryRun = false): SetStoreLogo
    {
        $root = $this->root;
        $uploads = $this->createStub(ReadInterface::class);
        $uploads->method('getAbsolutePath')->willReturnCallback(fn($p = null) => $root . '/var/' . $p);

        $media = $this->createStub(WriteInterface::class);
        $media->method('getAbsolutePath')->willReturnCallback(fn($p = null) => $root . '/media/' . $p);
        $media->method('create')->willReturnCallback(function ($p) use ($root) {
            if (!is_dir($root . '/media/' . $p)) {
                mkdir($root . '/media/' . $p, 0777, true);
            }
            return true;
        });
        $media->method('getDriver')->willReturn(new File());

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturnCallback(function ($code) use ($media) {
            $this->assertSame(DirectoryList::MEDIA, $code);
            return $media;
        });
        $filesystem->method('getDirectoryRead')->willReturnCallback(function ($code) use ($uploads) {
            $this->assertSame(DirectoryList::VAR_DIR, $code);
            return $uploads;
        });

        $writer = $this->createStub(WriterInterface::class);
        $writer->method('save')->willReturnCallback(function ($path, $value, $scope, $scopeId) {
            $this->saved[] = [$path, $value, $scope, $scopeId];
        });
        $types = $this->createStub(TypeListInterface::class);
        $types->method('cleanType')->willReturnCallback(function ($type) {
            $this->cleaned[] = $type;
        });

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(Config::class);
        $config->method('isConfirmationRequired')->willReturn($confirmRequired);
        $config->method('isDryRun')->willReturn($dryRun);

        return new SetStoreLogo(
            $filesystem,
            $this->createStub(ScopeConfigInterface::class),
            $writer,
            $storeManager,
            $types,
            $this->checkpoints,
            $config,
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testDefinition(): void
    {
        $this->assertSame('set_store_logo', $this->tool()->name());
        $this->assertSame(['source_path'], $this->tool()->definition()['input_schema']['required']);
    }

    public function testSourcePathValidation(): void
    {
        $tool = $this->tool();

        $this->assertSame(['status' => 'error', 'message' => 'source_path is required.'], $tool->execute([]));
        $this->assertSame(
            'source_path must point to an upload under panth/claudeai/. Got: etc/passwd',
            $tool->execute(['source_path' => '/etc/passwd'])['message']
        );
        $this->assertSame(
            'Uploaded file not found: panth/claudeai/missing.png',
            $tool->execute(['source_path' => 'panth\\claudeai\\missing.png'])['message']
        );
        $this->assertSame(
            'source_path resolves outside the allowed upload directory.',
            $tool->execute(['source_path' => 'panth/claudeai/../outside.png'])['message']
        );
        $this->assertSame(
            'That file is not a recognized image (jpg/png/gif/webp).',
            $tool->execute(['source_path' => 'panth/claudeai/fake.png'])['message']
        );
        $this->assertSame([], $this->saved);
    }

    public function testConfirmationReportsDimensions(): void
    {
        $result = $this->tool(true)->execute(['source_path' => 'panth/claudeai/My Logo.png']);

        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame(
            'About to set the store logo from panth/claudeai/My Logo.png (scope=default id=0, 1x1). Re-call with confirm=true to apply.',
            $result['message']
        );
    }

    public function testDryRunCreatesNoCheckpointAndDoesNotCopyOrSave(): void
    {
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->never())->method('snapshotConfig');
        $this->checkpoints = $checkpoints;

        $result = $this->tool(false, true)->execute(['source_path' => 'panth/claudeai/My Logo.png']);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(['width' => 1, 'height' => 1], $result['preview']);
        $this->assertFileDoesNotExist($this->root . '/media/logo/stores/0/My_Logo.png');
        $this->assertSame([], $this->saved);
    }

    public function testLogoIsCopiedAndConfigured(): void
    {
        $result = $this->tool()->execute([
            'source_path' => 'panth/claudeai/My Logo.png',
            'alt' => 'Shop',
            'width' => '180',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertFileExists($this->root . '/media/logo/stores/0/My_Logo.png');
        $this->assertSame('stores/0/My_Logo.png', $result['logo_path']);
        $this->assertSame('media/logo/stores/0/My_Logo.png', $result['media_path']);
        $this->assertSame([
            ['design/header/logo_src', 'stores/0/My_Logo.png', 'default', 0],
            ['design/header/logo_alt', 'Shop', 'default', 0],
            ['design/header/logo_width', '180', 'default', 0],
        ], $this->saved);
        $this->assertSame(['config', 'full_page'], $this->cleaned);
        $this->assertStringEndsWith('Undo: restore_checkpoint with cp_logo', $result['summary']);
    }

    public function testScopeCodeDefaultsToStoreScope(): void
    {
        $result = $this->tool()->execute(['source_path' => 'panth/claudeai/My Logo.png', 'scope_code' => 'luma', 'height' => 40]);

        $this->assertSame('stores/3/My_Logo.png', $result['logo_path']);
        $this->assertSame(['design/header/logo_src', 'stores/3/My_Logo.png', 'stores', 3], $this->saved[0]);
        $this->assertSame(['design/header/logo_height', '40', 'stores', 3], $this->saved[1]);
    }
}
