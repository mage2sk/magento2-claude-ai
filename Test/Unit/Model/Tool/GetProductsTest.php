<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Panth\ClaudeAi\Model\Tool\GetProducts;
use PHPUnit\Framework\TestCase;

class GetProductsTest extends TestCase
{
    private array $filters = [];
    private array $groups = [];
    private ?int $pageSize = null;

    private function tool(array $products = [], int $total = 0): GetProducts
    {
        $current = [];
        $filterBuilder = $this->createStub(FilterBuilder::class);
        $filterBuilder->method('setField')->willReturnCallback(function ($v) use (&$current, &$filterBuilder) {
            $current['field'] = $v;
            return $filterBuilder;
        });
        $filterBuilder->method('setConditionType')->willReturnCallback(function ($v) use (&$current, &$filterBuilder) {
            $current['condition'] = $v;
            return $filterBuilder;
        });
        $filterBuilder->method('setValue')->willReturnCallback(function ($v) use (&$current, &$filterBuilder) {
            $current['value'] = $v;
            return $filterBuilder;
        });
        $filterBuilder->method('create')->willReturnCallback(function () use (&$current) {
            $this->filters[] = $current;
            $current = [];
            return new Filter();
        });

        $groupBuilder = $this->createStub(FilterGroupBuilder::class);
        $groupBuilder->method('addFilter')->willReturnSelf();
        $groupBuilder->method('create')->willReturnCallback(fn() => new FilterGroup());

        $criteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('setFilterGroups')->willReturnCallback(function (array $groups) use (&$criteriaBuilder) {
            $this->groups = $groups;
            return $criteriaBuilder;
        });
        $criteriaBuilder->method('setPageSize')->willReturnCallback(function (int $size) use (&$criteriaBuilder) {
            $this->pageSize = $size;
            return $criteriaBuilder;
        });
        $criteriaBuilder->method('create')->willReturnCallback(function () {
            $criteria = new SearchCriteria();
            $criteria->setFilterGroups($this->groups);
            return $criteria;
        });

        $results = $this->createStub(ProductSearchResultsInterface::class);
        $results->method('getItems')->willReturn($products);
        $results->method('getTotalCount')->willReturn($total);
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('getList')->willReturn($results);

        return new GetProducts($repo, $criteriaBuilder, $filterBuilder, $groupBuilder);
    }

    public function testDefinition(): void
    {
        $tool = $this->tool();
        $this->assertSame('get_products', $tool->name());
        $this->assertArrayHasKey('sku_pattern', $tool->definition()['input_schema']['properties']);
    }

    public function testNoFiltersUsesDefaultLimit(): void
    {
        $result = $this->tool()->execute([]);

        $this->assertSame([], $this->filters);
        $this->assertSame(50, $this->pageSize);
        $this->assertSame('0 products match (showing 0).', $result['summary']);
    }

    public function testEveryFilterBecomesItsOwnAndGroup(): void
    {
        $this->tool()->execute([
            'sku_pattern' => 'MH%',
            'name_contains' => '100%',
            'min_price' => '10',
            'max_price' => 20,
            'limit' => 1000,
        ]);

        $this->assertSame([
            ['field' => 'sku', 'condition' => 'like', 'value' => 'MH%'],
            ['field' => 'name', 'condition' => 'like', 'value' => '%100\\%%'],
            ['field' => 'price', 'condition' => 'gteq', 'value' => 10.0],
            ['field' => 'price', 'condition' => 'lteq', 'value' => 20.0],
        ], $this->filters);
        $this->assertCount(4, $this->groups);
        $this->assertSame(200, $this->pageSize);
    }

    public function testProductsAreShaped(): void
    {
        $p = $this->createStub(ProductInterface::class);
        $p->method('getSku')->willReturn('MH01');
        $p->method('getName')->willReturn('Hoodie');
        $p->method('getPrice')->willReturn('39.99');
        $p->method('getTypeId')->willReturn('simple');
        $p->method('getStatus')->willReturn(2);

        $result = $this->tool([$p], 12)->execute(['limit' => 0]);

        $this->assertSame(1, $this->pageSize);
        $this->assertSame([['sku' => 'MH01', 'name' => 'Hoodie', 'price' => 39.99, 'type' => 'simple', 'status' => 'disabled']], $result['products']);
        $this->assertSame(12, $result['total_match']);
        $this->assertSame(1, $result['returned']);
        $this->assertSame('12 products match (showing 1).', $result['summary']);
    }
}
