<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Panth\ClaudeAi\Model\Tool\LowStock;
use PHPUnit\Framework\TestCase;

class LowStockTest extends TestCase
{
    private function product(int $id, string $sku): ProductInterface
    {
        $p = $this->createStub(ProductInterface::class);
        $p->method('getId')->willReturn($id);
        $p->method('getSku')->willReturn($sku);
        $p->method('getName')->willReturn('Name ' . $sku);
        return $p;
    }

    private function tool(array $qtyById): LowStock
    {
        $products = [];
        foreach (array_keys($qtyById) as $id) {
            $products[] = $this->product($id, 'SKU' . $id);
        }
        $results = $this->createStub(ProductSearchResultsInterface::class);
        $results->method('getItems')->willReturn($products);

        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('getList')->willReturn($results);

        $registry = $this->createStub(StockRegistryInterface::class);
        $registry->method('getStockItem')->willReturnCallback(function ($id) use ($qtyById) {
            if ($qtyById[$id] === null) {
                throw new \RuntimeException('no stock item');
            }
            $stock = $this->createStub(StockItemInterface::class);
            $stock->method('getQty')->willReturn($qtyById[$id]);
            return $stock;
        });

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->expects($this->once())->method('addFilter')->with('type_id', 'simple')->willReturnSelf();
        $builder->expects($this->once())->method('setPageSize')->with(500)->willReturnSelf();
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        return new LowStock($repo, $registry, $builder);
    }

    public function testDefinition(): void
    {
        $tool = new LowStock(
            $this->createStub(ProductRepositoryInterface::class),
            $this->createStub(StockRegistryInterface::class),
            $this->createStub(SearchCriteriaBuilder::class)
        );
        $this->assertSame('get_low_stock_products', $tool->name());
        $this->assertSame('get_low_stock_products', $tool->definition()['name']);
    }

    public function testDefaultThresholdFindsLowItemsAndSkipsBrokenStock(): void
    {
        $result = $this->tool([1 => 2.0, 2 => 50.0, 3 => null, 4 => 5.0])->execute([]);

        $this->assertSame('success', $result['status']);
        $this->assertSame(5, $result['threshold']);
        $this->assertSame(2, $result['affected_count']);
        $this->assertSame([
            ['sku' => 'SKU1', 'name' => 'Name SKU1', 'qty' => 2.0],
            ['sku' => 'SKU4', 'name' => 'Name SKU4', 'qty' => 5.0],
        ], $result['products']);
    }

    public function testLimitStopsScanAndNegativeThresholdIsClamped(): void
    {
        $result = $this->tool([1 => 0.0, 2 => 0.0, 3 => 0.0])->execute(['threshold' => -3, 'limit' => 2]);

        $this->assertSame(0, $result['threshold']);
        $this->assertSame(['SKU1', 'SKU2'], array_column($result['products'], 'sku'));
    }
}
