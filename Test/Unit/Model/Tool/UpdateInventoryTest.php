<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\UpdateInventory;
use Panth\ClaudeAi\Model\WriteConfirmation;
use PHPUnit\Framework\TestCase;

class UpdateInventoryTest extends TestCase
{
    private array $qty = [];
    private array $inStock = [];
    private array $persisted = [];

    private function tool(
        array $currentQty,
        bool $dryRun = false,
        int $cap = 500,
        ?WriteConfirmation $confirmation = null,
        ?CheckpointService $checkpoints = null
    ): UpdateInventory {
        $ids = array_flip(array_keys($currentQty));
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(function (string $sku) use ($ids) {
            if (!isset($ids[$sku])) {
                throw new \RuntimeException('missing ' . $sku);
            }
            $p = $this->createStub(ProductInterface::class);
            $p->method('getId')->willReturn($ids[$sku] + 100);
            return $p;
        });

        $skuById = array_flip(array_map(fn($i) => $i + 100, $ids));
        $registry = $this->createStub(StockRegistryInterface::class);
        $registry->method('getStockItem')->willReturnCallback(function (int $id) use ($skuById, $currentQty) {
            $sku = $skuById[$id];
            $stock = $this->createStub(StockItemInterface::class);
            $stock->method('getQty')->willReturn($currentQty[$sku]);
            $stock->method('setQty')->willReturnCallback(function ($v) use ($sku, $stock) {
                $this->qty[$sku] = $v;
                return $stock;
            });
            $stock->method('setIsInStock')->willReturnCallback(function ($v) use ($sku, $stock) {
                $this->inStock[$sku] = $v;
                return $stock;
            });
            return $stock;
        });
        $registry->method('updateStockItemBySku')->willReturnCallback(function (string $sku) {
            $this->persisted[] = $sku;
            return 1;
        });

        $config = $this->createStub(Config::class);
        $config->method('isDryRun')->willReturn($dryRun);
        $config->method('getMaxBulkUpdate')->willReturn($cap);

        if ($checkpoints === null) {
            $checkpoints = $this->createStub(CheckpointService::class);
            $checkpoints->method('snapshotProducts')->willReturn('cp_inv');
        }

        return new UpdateInventory($repo, $registry, $config, $checkpoints, $confirmation ?? $this->createStub(WriteConfirmation::class));
    }

    public function testDefinition(): void
    {
        $this->assertSame('update_inventory', $this->tool([])->name());
        $this->assertSame(['sku_list'], $this->tool([])->definition()['input_schema']['required']);
    }

    public function testValidation(): void
    {
        $tool = $this->tool([], false, 1);
        $this->assertSame(['status' => 'error', 'message' => 'sku_list is required.'], $tool->execute(['sku_list' => ['']]));
        $this->assertSame(['status' => 'error', 'message' => 'Provide qty, qty_change, or in_stock.'], $tool->execute(['sku_list' => ['A']]));
        $this->assertSame(
            ['status' => 'error', 'message' => 'Refusing to update 2 products (cap 1).'],
            $tool->execute(['sku_list' => ['A', 'B'], 'qty' => 1])
        );
    }

    public function testConfirmationShortCircuits(): void
    {
        $confirmation = $this->createMock(WriteConfirmation::class);
        $confirmation->expects($this->once())->method('check')
            ->with('update_inventory', ['A'], ['qty' => null, 'qty_change' => -2, 'in_stock' => null])
            ->willReturn(['status' => 'needs_confirmation']);

        $result = $this->tool(['A' => 1.0], false, 500, $confirmation)->execute(['sku_list' => ['A'], 'qty_change' => -2]);
        $this->assertSame(['status' => 'needs_confirmation'], $result);
    }

    public function testAbsoluteQtyIsClampedAndFlagsInStock(): void
    {
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->once())->method('snapshotProducts')
            ->with('update_inventory', 'stock_qty', ['A', 'B', 'X'], 'Inventory change for 3 products')
            ->willReturn('cp_7');

        $result = $this->tool(['A' => 1.0, 'B' => 0.0], false, 500, null, $checkpoints)
            ->execute(['sku_list' => ['A', 'B', 'X'], 'qty' => 10]);

        $this->assertSame(['A' => 10.0, 'B' => 10.0], $this->qty);
        $this->assertSame(['A' => 1, 'B' => 1], $this->inStock);
        $this->assertSame(['A', 'B'], $this->persisted);
        $this->assertSame(2, $result['affected_count']);
        $this->assertSame([['sku' => 'X', 'error' => 'missing X']], $result['failed']);
        $this->assertSame('Updated stock for 2/3 products. Checkpoint cp_7.', $result['summary']);
    }

    public function testDeltaNeverGoesNegativeAndZeroLeavesStockFlag(): void
    {
        $this->tool(['A' => 3.0, 'B' => 8.0])->execute(['sku_list' => ['A', 'B'], 'qty_change' => -5]);

        $this->assertEquals(['A' => 0.0, 'B' => 3.0], $this->qty);
        $this->assertSame(['B' => 1], $this->inStock);
    }

    public function testExplicitOutOfStockFlagWins(): void
    {
        $this->tool(['A' => 3.0])->execute(['sku_list' => ['A'], 'in_stock' => false]);

        $this->assertSame(['A' => 3.0], $this->qty);
        $this->assertSame(['A' => 0], $this->inStock);
    }

    public function testDryRunSkipsCheckpointAndPersistence(): void
    {
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->never())->method('snapshotProducts');

        $result = $this->tool(['A' => 3.0], true, 500, null, $checkpoints)->execute(['sku_list' => ['A'], 'qty' => 4]);

        $this->assertSame([], $this->persisted);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('(dry run) Updated stock for 1/1 products', $result['summary']);
    }
}
