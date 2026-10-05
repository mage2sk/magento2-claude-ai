<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\UpdateProductStatus;
use Panth\ClaudeAi\Model\WriteConfirmation;
use PHPUnit\Framework\TestCase;

class UpdateProductStatusTest extends TestCase
{
    private array $statuses = [];
    private array $filters = [];

    private function product(string $sku, int $status): ProductInterface
    {
        $p = $this->createStub(ProductInterface::class);
        $p->method('getSku')->willReturn($sku);
        $p->method('getStatus')->willReturn($status);
        $p->method('setStatus')->willReturnCallback(function ($v) use ($sku, $p) {
            $this->statuses[$sku] = $v;
            return $p;
        });
        return $p;
    }

    private function tool(
        array $catalog,
        bool $dryRun = false,
        int $cap = 500,
        ?WriteConfirmation $confirmation = null,
        ?CheckpointService $checkpoints = null,
        ?ProductRepositoryInterface $repo = null
    ): UpdateProductStatus {
        if ($repo === null) {
            $repo = $this->createStub(ProductRepositoryInterface::class);
            $repo->method('get')->willReturnCallback(function (string $sku) use ($catalog) {
                if (!isset($catalog[$sku])) {
                    throw new \RuntimeException('missing ' . $sku);
                }
                return $catalog[$sku];
            });
            $results = $this->createStub(ProductSearchResultsInterface::class);
            $results->method('getItems')->willReturn(array_values($catalog));
            $repo->method('getList')->willReturn($results);
        }

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
            $checkpoints->method('snapshotProducts')->willReturn('cp_s');
        }

        return new UpdateProductStatus($repo, $builder, $config, $checkpoints, $confirmation ?? $this->createStub(WriteConfirmation::class));
    }

    public function testDefinition(): void
    {
        $this->assertSame('update_product_status', $this->tool([])->name());
        $this->assertSame(['status'], $this->tool([])->definition()['input_schema']['required']);
    }

    public function testInvalidStatus(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => 'status must be "enable" or "disable".'],
            $this->tool([])->execute(['status' => 'on', 'sku_list' => ['A']])
        );
    }

    public function testNoMatch(): void
    {
        $this->assertSame(['status' => 'error', 'message' => 'No products matched.'], $this->tool([])->execute(['status' => 'enable']));
    }

    public function testCap(): void
    {
        $result = $this->tool([], false, 1)->execute(['status' => 'enable', 'sku_list' => ['A', 'B']]);
        $this->assertSame('Refusing to change 2 products (cap 1).', $result['message']);
    }

    public function testConfirmationShortCircuits(): void
    {
        $confirmation = $this->createMock(WriteConfirmation::class);
        $confirmation->expects($this->once())->method('check')
            ->with('update_product_status', ['A'], ['status' => 'disable'])
            ->willReturn(['status' => 'needs_confirmation']);

        $this->assertSame(
            ['status' => 'needs_confirmation'],
            $this->tool([], false, 500, $confirmation)->execute(['status' => 'disable', 'sku_list' => ['A']])
        );
    }

    public function testDisableSkipsAlreadyDisabledAndRecordsFailures(): void
    {
        $catalog = ['A' => $this->product('A', 1), 'B' => $this->product('B', 2)];
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->once())->method('snapshotProducts')
            ->with('update_product_status', 'product_status', ['A', 'B', 'C'], 'Disable 3 products')
            ->willReturn('cp_9');

        $result = $this->tool($catalog, false, 500, null, $checkpoints)
            ->execute(['status' => 'disable', 'sku_list' => ['A', 'B', 'C']]);

        $this->assertSame(['A' => 2], $this->statuses);
        $this->assertSame(1, $result['affected_count']);
        $this->assertSame([['sku' => 'C', 'error' => 'missing C']], $result['failed']);
        $this->assertSame(' disabled 1/3 products. Checkpoint cp_9.', $result['summary']);
    }

    public function testDryRunEnableDoesNotSave(): void
    {
        $catalog = ['A' => $this->product('A', 2)];
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('get')->willReturn($catalog['A']);
        $repo->expects($this->never())->method('save');

        $result = $this->tool($catalog, true, 500, null, null, $repo)->execute(['status' => 'enable', 'sku_list' => ['A']]);

        $this->assertSame([], $this->statuses);
        $this->assertSame(1, $result['affected_count']);
        $this->assertSame('', $result['checkpoint_id']);
        $this->assertSame('WOULD have enabled 1/1 products (dry run)', $result['summary']);
    }

    public function testPatternAndNameResolution(): void
    {
        $catalog = ['MH01' => $this->product('MH01', 2)];
        $this->tool($catalog)->execute(['status' => 'enable', 'sku_pattern' => 'MH%']);
        $this->tool($catalog)->execute(['status' => 'enable', 'name_contains' => 'Tee']);

        $this->assertSame([['sku', 'MH%', 'like'], ['name', '%Tee%', 'like']], $this->filters);
        $this->assertSame(['MH01' => 1], $this->statuses);
    }
}
