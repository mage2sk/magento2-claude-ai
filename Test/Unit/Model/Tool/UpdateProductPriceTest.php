<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\UpdateProductPrice;
use Panth\ClaudeAi\Model\WriteConfirmation;
use PHPUnit\Framework\TestCase;

class UpdateProductPriceTest extends TestCase
{
    private array $prices = [];
    private array $saved = [];
    private array $filters = [];

    private function product(string $sku, float $price): ProductInterface
    {
        $p = $this->createStub(ProductInterface::class);
        $p->method('getSku')->willReturn($sku);
        $p->method('getPrice')->willReturn($price);
        $p->method('setPrice')->willReturnCallback(function ($v) use ($sku, $p) {
            $this->prices[$sku] = $v;
            return $p;
        });
        return $p;
    }

    private function tool(
        array $catalog,
        bool $dryRun = false,
        int $cap = 500,
        ?WriteConfirmation $confirmation = null,
        ?CheckpointService $checkpoints = null
    ): UpdateProductPrice {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(function (string $sku) use ($catalog) {
            if (!isset($catalog[$sku])) {
                throw new NoSuchEntityException(__('The product that was requested doesn\'t exist.'));
            }
            return $catalog[$sku];
        });
        $repo->method('save')->willReturnCallback(function (ProductInterface $p) {
            $this->saved[] = $p->getSku();
            return $p;
        });
        $results = $this->createStub(ProductSearchResultsInterface::class);
        $results->method('getItems')->willReturn(array_values($catalog));
        $repo->method('getList')->willReturn($results);

        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($f, $v, $t) use (&$builder) {
            $this->filters[] = [$f, $v, $t];
            return $builder;
        });
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        $config = $this->createStub(Config::class);
        $config->method('isDryRun')->willReturn($dryRun);
        $config->method('getMaxBulkUpdate')->willReturn($cap);

        if ($checkpoints === null) {
            $checkpoints = $this->createStub(CheckpointService::class);
            $checkpoints->method('snapshotProducts')->willReturn('cp_test');
        }

        return new UpdateProductPrice(
            $repo,
            $builder,
            $config,
            $checkpoints,
            $confirmation ?? $this->createStub(WriteConfirmation::class)
        );
    }

    public function testDefinition(): void
    {
        $tool = $this->tool([]);
        $this->assertSame('update_product_price', $tool->name());
        $this->assertArrayHasKey('percent_change', $tool->definition()['input_schema']['properties']);
    }

    public function testRequiresPriceOrPercent(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => 'Provide either new_price or percent_change.'],
            $this->tool([])->execute(['sku_list' => ['A']])
        );
    }

    public function testRejectsNonPositiveFixedPrice(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => 'new_price must be > 0.'],
            $this->tool([])->execute(['sku_list' => ['A'], 'new_price' => 0])
        );
    }

    public function testRequiresAMatch(): void
    {
        $result = $this->tool([])->execute(['new_price' => 5]);
        $this->assertSame('error', $result['status']);
        $this->assertStringStartsWith('No SKUs matched.', $result['message']);
    }

    public function testRefusesAboveCap(): void
    {
        $result = $this->tool([], false, 2)->execute(['sku_list' => ['A', 'B', 'C'], 'new_price' => 5]);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Refusing to update 3 products (cap is 2)', $result['message']);
    }

    public function testConfirmationResponseIsReturnedUnchanged(): void
    {
        $confirmation = $this->createMock(WriteConfirmation::class);
        $confirmation->expects($this->once())->method('check')
            ->with('update_product_price', ['A', 'B'], ['new_price' => 5.0, 'percent_change' => null])
            ->willReturn(['status' => 'needs_confirmation']);
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->never())->method('snapshotProducts');

        $result = $this->tool([], false, 500, $confirmation, $checkpoints)
            ->execute(['sku_list' => ['A', 'B', 'A', ''], 'new_price' => 5]);

        $this->assertSame(['status' => 'needs_confirmation'], $result);
    }

    public function testFixedPriceUpdatesChangedProductsOnly(): void
    {
        $catalog = ['A' => $this->product('A', 10.0), 'B' => $this->product('B', 19.99)];
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->once())->method('snapshotProducts')
            ->with('update_product_price', 'product_price', ['A', 'B', 'X'], 'Set price to $19.99 for 3 products')
            ->willReturn('cp_1');

        $result = $this->tool($catalog, false, 500, null, $checkpoints)
            ->execute(['sku_list' => ['A', 'B', 'X'], 'new_price' => 19.99]);

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['dry_run']);
        $this->assertSame(3, $result['matched']);
        $this->assertSame(1, $result['affected_count']);
        $this->assertSame(['A' => 19.99], $this->prices);
        $this->assertSame(['A'], $this->saved);
        $this->assertSame([['sku' => 'A', 'before' => 10.0, 'after' => 19.99]], $result['changes']);
        $this->assertSame('X', $result['failed'][0]['sku']);
        $this->assertSame('cp_1', $result['checkpoint_id']);
        $this->assertSame('Updated 1/3 products. Checkpoint cp_1 (use restore_checkpoint to undo).', $result['summary']);
    }

    public function testPercentChangeRoundsAndRejectsZeroResult(): void
    {
        $catalog = ['A' => $this->product('A', 33.33), 'B' => $this->product('B', 0.0)];
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->once())->method('snapshotProducts')
            ->with($this->anything(), $this->anything(), $this->anything(), 'Adjust price by -10.0% for 2 products')
            ->willReturn('cp_2');

        $result = $this->tool($catalog, false, 500, null, $checkpoints)
            ->execute(['sku_list' => ['A', 'B'], 'percent_change' => -10]);

        $this->assertSame(['A' => 30.0], $this->prices);
        $this->assertSame([['sku' => 'B', 'error' => 'computed price <= 0']], $result['failed']);
    }

    public function testDryRunWritesNothing(): void
    {
        $catalog = ['A' => $this->product('A', 10.0)];
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->never())->method('snapshotProducts');

        $result = $this->tool($catalog, true, 500, null, $checkpoints)
            ->execute(['sku_list' => ['A'], 'percent_change' => 50]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['affected_count']);
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->prices);
        $this->assertSame('', $result['checkpoint_id']);
        $this->assertSame([['sku' => 'A', 'before' => 10.0, 'after' => 15.0]], $result['changes']);
        $this->assertSame('WOULD update 1/1 products (dry run - no DB writes)', $result['summary']);
    }

    public function testSkuPatternAndNameMatchUseRepositorySearch(): void
    {
        $catalog = ['MH01' => $this->product('MH01', 10.0)];

        $this->tool($catalog)->execute(['sku_pattern' => 'MH%', 'new_price' => 12]);
        $this->tool($catalog)->execute(['name_contains' => '5%', 'new_price' => 12]);

        $this->assertSame([['sku', 'MH%', 'like'], ['name', '%5\\%%', 'like']], $this->filters);
        $this->assertSame(['MH01', 'MH01'], $this->saved);
    }
}
