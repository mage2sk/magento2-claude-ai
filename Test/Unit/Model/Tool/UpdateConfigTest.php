<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\UpdateConfig;
use PHPUnit\Framework\TestCase;

class UpdateConfigTest extends TestCase
{
    private ScopeConfigInterface $scopeConfig;
    private WriterInterface $writer;
    private StoreManagerInterface $storeManager;
    private TypeListInterface $cacheTypes;
    private CheckpointService $checkpoints;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->writer = $this->createStub(WriterInterface::class);
        $this->cacheTypes = $this->createStub(TypeListInterface::class);
        $this->checkpoints = $this->createStub(CheckpointService::class);
        $this->checkpoints->method('snapshotConfig')->willReturn('cp_cfg');

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn(2);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturnCallback(function ($code) use ($store) {
            if ($code !== 'luma') {
                throw new \RuntimeException('unknown store');
            }
            return $store;
        });
        $this->storeManager->method('getWebsite')->willReturn($website);
    }

    private function tool(bool $confirmRequired = false, bool $dryRun = false): UpdateConfig
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfirmationRequired')->willReturn($confirmRequired);
        $config->method('isDryRun')->willReturn($dryRun);

        return new UpdateConfig($this->scopeConfig, $this->writer, $this->storeManager, $this->cacheTypes, $this->checkpoints, $config);
    }

    public function testDefinitionAndListAllowed(): void
    {
        $tool = $this->tool();
        $this->assertSame('update_config', $tool->name());

        $result = $tool->execute(['action' => 'list_allowed']);
        $this->assertContains('general/store_information/name', $result['allowed']);
        $this->assertNotContains('web/secure/base_url', $result['allowed']);
        $this->assertSame(count($result['allowed']) . ' paths the AI may read or write.', $result['summary']);
    }

    public function testPathRequiredAndUnknownAction(): void
    {
        $this->assertSame(['status' => 'error', 'message' => 'path is required.'], $this->tool()->execute(['action' => 'read']));
        $this->assertSame(
            ['status' => 'error', 'message' => 'Unknown action: purge'],
            $this->tool()->execute(['action' => 'purge', 'path' => 'a/b/c'])
        );
    }

    public function testReadResolvesStoreScope(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn($path, $scope, $id) => $path === 'general/locale/timezone' && $scope === 'store' && $id === 3 ? 'Europe/Berlin' : null
        );

        $result = $this->tool()->execute([
            'action' => 'read',
            'path' => 'general/locale/timezone',
            'scope' => 'store',
            'scope_code' => 'luma',
        ]);

        $this->assertSame('Europe/Berlin', $result['value']);
        $this->assertSame(3, $result['scope_id']);
        $this->assertSame('general/locale/timezone = Europe/Berlin (scope=store id=3)', $result['summary']);
    }

    public function testReadOfNonScalarIsJsonEncodedAndUnknownStoreFallsBackToZero(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(['a' => 1]);

        $result = $this->tool()->execute(['action' => 'read', 'path' => 'design/header/welcome', 'scope' => 'store', 'scope_code' => 'nope']);

        $this->assertSame(0, $result['scope_id']);
        $this->assertSame('design/header/welcome = {"a":1} (scope=store id=0)', $result['summary']);
    }

    public function testWebsiteScopeResolution(): void
    {
        $result = $this->tool()->execute(['action' => 'read', 'path' => 'design/header/welcome', 'scope' => 'website', 'scope_code' => 'base']);
        $this->assertSame(2, $result['scope_id']);
    }

    public function testWriteOutsideAllowListIsRefused(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');
        $this->writer = $writer;

        $result = $this->tool()->execute(['action' => 'write', 'path' => 'payment/checkmo/active', 'value' => '1']);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString("Path 'payment/checkmo/active' is not in the writable allow-list", $result['message']);
    }

    public function testWriteRequiresValueAndConfirmation(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => 'value is required for write.'],
            $this->tool()->execute(['action' => 'write', 'path' => 'design/header/welcome'])
        );

        $result = $this->tool(true)->execute(['action' => 'write', 'path' => 'design/header/welcome', 'value' => 'Hi']);
        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame('About to set design/header/welcome (scope=default, scope_id=0). Re-call with confirm=true to apply.', $result['message']);
    }

    public function testWriteSnapshotsSavesAndCleansCache(): void
    {
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->once())->method('snapshotConfig')
            ->with('update_config', [['path' => 'design/header/welcome', 'scope' => 'default', 'scope_id' => 0]], 'Set design/header/welcome to Hello', '')
            ->willReturn('cp_w');
        $this->checkpoints = $checkpoints;
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('save')->with('design/header/welcome', 'Hello', 'default', 0);
        $this->writer = $writer;
        $types = $this->createMock(TypeListInterface::class);
        $types->expects($this->once())->method('cleanType')->with('config');
        $this->cacheTypes = $types;

        $result = $this->tool(true)->execute(['action' => 'write', 'path' => 'design/header/welcome', 'value' => 'Hello', 'confirm' => true]);

        $this->assertSame('success', $result['status']);
        $this->assertSame('cp_w', $result['checkpoint_id']);
        $this->assertSame('Set design/header/welcome = Hello (scope=default id=0). Undo: restore_checkpoint with cp_w', $result['summary']);
    }

    public function testWriteDryRunCreatesNoCheckpointAndDoesNotSave(): void
    {
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->never())->method('snapshotConfig');
        $this->checkpoints = $checkpoints;
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');
        $this->writer = $writer;

        $result = $this->tool(false, true)->execute(['action' => 'write', 'path' => 'design/header/welcome', 'value' => 'Hi']);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame('', $result['checkpoint_id']);
    }

    public function testDeleteFlow(): void
    {
        $this->assertSame(
            "Path 'web/secure/base_url' is not in the allow-list.",
            $this->tool()->execute(['action' => 'delete', 'path' => 'web/secure/base_url'])['message']
        );
        $this->assertSame(
            'needs_confirmation',
            $this->tool(true)->execute(['action' => 'delete', 'path' => 'design/footer/copyright'])['status']
        );
        $this->assertSame(
            '[DRY RUN] Would clear design/footer/copyright.',
            $this->tool(false, true)->execute(['action' => 'delete', 'path' => 'design/footer/copyright'])['message']
        );

        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('delete')->with('design/footer/copyright', 'default', 0);
        $this->writer = $writer;

        $result = $this->tool()->execute(['action' => 'delete', 'path' => 'design/footer/copyright']);
        $this->assertSame('Cleared design/footer/copyright. Undo: restore_checkpoint with cp_cfg', $result['summary']);
    }

    public function testDeleteDryRunCreatesNoCheckpoint(): void
    {
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->never())->method('snapshotConfig');
        $this->checkpoints = $checkpoints;
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('delete');
        $this->writer = $writer;

        $result = $this->tool(false, true)->execute(['action' => 'delete', 'path' => 'design/footer/copyright']);

        $this->assertSame('dry_run', $result['status']);
    }

    public function testReadOutsideAllowListIsRefused(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->never())->method('getValue');
        $this->scopeConfig = $scopeConfig;

        $result = $this->tool()->execute(['action' => 'read', 'path' => 'payment/braintree/private_key']);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString("Path 'payment/braintree/private_key' is not in the allow-list", $result['message']);
    }

    public function testWebsiteScopeIsWrittenAsWebsitesScope(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('save')->with('design/header/welcome', 'Hi', 'websites', 2);
        $this->writer = $writer;

        $this->tool()->execute([
            'action' => 'write',
            'path' => 'design/header/welcome',
            'value' => 'Hi',
            'scope' => 'website',
            'scope_code' => 'base',
        ]);
    }

    public function testStoreScopeIsWrittenAsStoresScope(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('save')->with('design/header/welcome', 'Hi', 'stores', 3);
        $this->writer = $writer;

        $this->tool()->execute([
            'action' => 'write',
            'path' => 'design/header/welcome',
            'value' => 'Hi',
            'scope' => 'store',
            'scope_code' => 'luma',
        ]);
    }

    public function testWriterFailureIsReturnedAsError(): void
    {
        $this->writer->method('save')->willThrowException(new \RuntimeException('db locked'));

        $this->assertSame(
            ['status' => 'error', 'message' => 'db locked'],
            $this->tool()->execute(['action' => 'write', 'path' => 'design/header/welcome', 'value' => 'Hi'])
        );
    }
}
