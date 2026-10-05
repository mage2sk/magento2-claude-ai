<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use Panth\ClaudeAi\Model\Checkpoint;
use Panth\ClaudeAi\Model\CheckpointService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CheckpointServiceTest extends TestCase
{
    private array $inserts = [];
    private array $updates = [];
    private $row = false;

    private ProductRepositoryInterface $productRepository;
    private StockRegistryInterface $stockRegistry;
    private ScopeConfigInterface $scopeConfig;
    private WriterInterface $configWriter;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->productRepository = $this->createStub(ProductRepositoryInterface::class);
        $this->stockRegistry = $this->createStub(StockRegistryInterface::class);
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->configWriter = $this->createStub(WriterInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function service(?int $userId = 7): CheckpointService
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturnCallback(fn() => $this->row);
        $connection->method('insert')->willReturnCallback(function (string $table, array $data) {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            return 1;
        });
        $connection->method('update')->willReturnCallback(function (string $table, array $data, $where) {
            $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
            return 1;
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $user = null;
        if ($userId !== null) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($userId);
        }
        $session = $this->createStub(AdminSession::class);
        $session->method('__call')->willReturnCallback(fn(string $m) => $m === 'getUser' ? $user : null);

        return new CheckpointService(
            $resource,
            $this->productRepository,
            $this->stockRegistry,
            $session,
            $this->logger,
            $this->scopeConfig,
            $this->configWriter
        );
    }

    private function product(int $id, float $price, int $status): ProductInterface
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn($id);
        $product->method('getPrice')->willReturn($price);
        $product->method('getStatus')->willReturn($status);
        return $product;
    }

    public function testSnapshotConfigStoresCurrentValues(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn(string $path, string $scope, $scopeId) => $path . '|' . $scope . '|' . var_export($scopeId, true)
        );

        $id = $this->service()->snapshotConfig('update_config', [
            ['path' => 'design/header/welcome', 'scope' => 'default', 'scope_id' => 0],
            ['path' => 'general/store_information/name', 'scope' => 'stores', 'scope_id' => 2],
            ['path' => ''],
        ], 'Set things', 'conv1');

        $this->assertMatchesRegularExpression('/^cp_[0-9a-f]{16}$/', $id);
        $this->assertCount(1, $this->inserts);
        $data = $this->inserts[0]['data'];
        $this->assertSame('panth_claudeai_checkpoint', $this->inserts[0]['table']);
        $this->assertSame($id, $data['checkpoint_id']);
        $this->assertSame('conv1', $data['conversation_id']);
        $this->assertSame('config', $data['entity_type']);
        $this->assertSame(2, $data['record_count']);
        $this->assertSame(Checkpoint::STATUS_ACTIVE, $data['status']);
        $this->assertSame(7, $data['admin_user_id']);

        $state = json_decode($data['before_state'], true);
        $this->assertSame('design/header/welcome|default|NULL', $state['default:0:design/header/welcome']['value']);
        $this->assertSame('general/store_information/name|stores|2', $state['stores:2:general/store_information/name']['value']);
    }

    public function testSnapshotProductsCapturesStatePerEntityType(): void
    {
        $products = ['A' => $this->product(1, 10.5, 1), 'B' => $this->product(2, 20.25, 2)];
        $this->productRepository->method('get')->willReturnCallback(function (string $sku) use ($products) {
            if (!isset($products[$sku])) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $products[$sku];
        });
        $stock = $this->createStub(StockItemInterface::class);
        $stock->method('getQty')->willReturn(3.5);
        $stock->method('getIsInStock')->willReturn(true);
        $this->stockRegistry->method('getStockItem')->willReturn($stock);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('warning');
        $this->logger = $logger;

        $service = $this->service(null);
        $service->snapshotProducts('op', 'product_price', ['A', 'B', 'X'], 'd');
        $service->snapshotProducts('op', 'product_status', ['A', 'X'], 'd');
        $service->snapshotProducts('op', 'stock_qty', ['B', 'X'], 'd');

        $this->assertSame(['A' => ['price' => 10.5], 'B' => ['price' => 20.25]], json_decode($this->inserts[0]['data']['before_state'], true));
        $this->assertSame(2, $this->inserts[0]['data']['record_count']);
        $this->assertNull($this->inserts[0]['data']['admin_user_id']);
        $this->assertSame(['A' => ['status' => 1]], json_decode($this->inserts[1]['data']['before_state'], true));
        $this->assertSame(['B' => ['qty' => 3.5, 'is_in_stock' => 1]], json_decode($this->inserts[2]['data']['before_state'], true));
        $this->assertSame('stock_qty', $this->inserts[2]['data']['entity_type']);
    }

    public function testRestoreUnknownCheckpoint(): void
    {
        $this->row = false;
        $result = $this->service()->restore('cp_x');
        $this->assertSame('error', $result['status']);
        $this->assertSame('Checkpoint cp_x not found.', $result['message']);
    }

    public function testRestoreRefusesOtherAdminsCheckpoint(): void
    {
        $this->row = ['entity_id' => 1, 'admin_user_id' => '9', 'status' => 'active', 'entity_type' => 'config', 'before_state' => '{}'];
        $result = $this->service(7)->restore('cp_x');
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('belongs to another admin', $result['message']);
        $this->assertSame([], $this->updates);
    }

    public function testRestoreRefusesAlreadyRestoredCheckpoint(): void
    {
        $this->row = ['entity_id' => 1, 'admin_user_id' => null, 'status' => 'restored', 'entity_type' => 'config', 'before_state' => '{}'];
        $result = $this->service(7)->restore('cp_x');
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('is restored and cannot be restored again', $result['message']);
    }

    public function testRestorePricesSavesProductsAndMarksRestored(): void
    {
        $this->row = [
            'entity_id' => 11,
            'admin_user_id' => '7',
            'status' => 'active',
            'entity_type' => 'product_price',
            'before_state' => json_encode(['A' => ['price' => 9.99], 'B' => ['price' => 5], 'C' => []]),
        ];
        $saved = [];
        $productA = $this->createMock(ProductInterface::class);
        $productA->expects($this->once())->method('setPrice')->with(9.99);
        $productB = $this->createStub(ProductInterface::class);
        $productC = $this->createMock(ProductInterface::class);
        $productC->expects($this->never())->method('setPrice');
        $this->productRepository->method('get')->willReturnCallback(
            fn(string $sku) => match ($sku) {
                'A' => $productA,
                'B' => throw new \RuntimeException('B broke'),
                default => $productC,
            }
        );
        $this->productRepository->method('save')->willReturnCallback(function ($p) use (&$saved) {
            $saved[] = $p;
            return $p;
        });

        $result = $this->service(7)->restore('cp_1');

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['affected_count']);
        $this->assertSame([['sku' => 'B', 'error' => 'B broke']], $result['failed']);
        $this->assertSame('Restored 1/3 records from checkpoint cp_1.', $result['summary']);
        $this->assertSame([$productA], $saved);
        $this->assertCount(1, $this->updates);
        $this->assertSame(Checkpoint::STATUS_RESTORED, $this->updates[0]['data']['status']);
        $this->assertSame(['entity_id = ?' => 11], $this->updates[0]['where']);
    }

    public function testRestoreStatus(): void
    {
        $this->row = [
            'entity_id' => 1, 'admin_user_id' => null, 'status' => 'active',
            'entity_type' => 'product_status', 'before_state' => json_encode(['A' => ['status' => 2]]),
        ];
        $product = $this->createMock(ProductInterface::class);
        $product->expects($this->once())->method('setStatus')->with(2);
        $this->productRepository->method('get')->willReturn($product);

        $this->assertSame(1, $this->service()->restore('cp_1')['affected_count']);
    }

    public function testRestoreStock(): void
    {
        $this->row = [
            'entity_id' => 1, 'admin_user_id' => null, 'status' => 'active',
            'entity_type' => 'stock_qty', 'before_state' => json_encode(['A' => ['qty' => 4, 'is_in_stock' => 0]]),
        ];
        $stock = $this->createMock(StockItemInterface::class);
        $stock->expects($this->once())->method('setQty')->with(4.0);
        $stock->expects($this->once())->method('setIsInStock')->with(0);
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->with('A')->willReturn($stock);
        $registry->expects($this->once())->method('updateStockItemBySku')->with('A', $stock);
        $this->stockRegistry = $registry;

        $result = $this->service()->restore('cp_1');
        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['affected_count']);
    }

    public function testRestoreConfigSavesOrDeletes(): void
    {
        $this->row = [
            'entity_id' => 1, 'admin_user_id' => null, 'status' => 'active', 'entity_type' => 'config',
            'before_state' => json_encode([
                'a' => ['path' => 'design/header/welcome', 'scope' => 'default', 'scope_id' => 0, 'value' => 'Hi'],
                'b' => ['path' => 'design/footer/copyright', 'scope' => 'stores', 'scope_id' => 2, 'value' => null],
                'c' => ['path' => '', 'value' => 'x'],
            ]),
        ];
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('save')->with('design/header/welcome', 'Hi', 'default', 0);
        $writer->expects($this->once())->method('delete')->with('design/footer/copyright', 'stores', 2);
        $this->configWriter = $writer;

        $result = $this->service()->restore('cp_1');
        $this->assertSame(2, $result['affected_count']);
        $this->assertSame('Restored 2/3 records from checkpoint cp_1.', $result['summary']);
    }

    public function testRestoreConfigMapsStoreAndWebsiteScopesForTheWriter(): void
    {
        $this->row = [
            'entity_id' => 1, 'admin_user_id' => null, 'status' => 'active', 'entity_type' => 'config',
            'before_state' => json_encode([
                'a' => ['path' => 'design/header/welcome', 'scope' => 'store', 'scope_id' => 3, 'value' => 'Hi'],
                'b' => ['path' => 'design/footer/copyright', 'scope' => 'website', 'scope_id' => 2, 'value' => null],
            ]),
        ];
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->once())->method('save')->with('design/header/welcome', 'Hi', 'stores', 3);
        $writer->expects($this->once())->method('delete')->with('design/footer/copyright', 'websites', 2);
        $this->configWriter = $writer;

        $this->assertSame(2, $this->service()->restore('cp_1')['affected_count']);
    }

    public function testRestoreWhereEverythingFailsReportsErrorAndKeepsCheckpointActive(): void
    {
        $this->row = [
            'entity_id' => 1, 'admin_user_id' => null, 'status' => 'active',
            'entity_type' => 'product_price', 'before_state' => json_encode(['A' => ['price' => 1]]),
        ];
        $this->productRepository->method('get')->willThrowException(new \RuntimeException('gone'));

        $result = $this->service()->restore('cp_1');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Checkpoint cp_1 could not be restored: gone', $result['message']);
        $this->assertSame([], $this->updates);
    }
}
