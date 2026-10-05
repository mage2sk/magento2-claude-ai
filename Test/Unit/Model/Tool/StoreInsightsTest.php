<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Panth\ClaudeAi\Model\Tool\StoreInsights;
use PHPUnit\Framework\TestCase;

class StoreInsightsTest extends TestCase
{
    private array $filters = [];
    private array $pageSizes = [];

    private function builder(): SearchCriteriaBuilder
    {
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($field, $value) use (&$builder) {
            $this->filters[] = [$field, $value];
            return $builder;
        });
        $builder->method('setPageSize')->willReturnCallback(function ($size) use (&$builder) {
            $this->pageSizes[] = $size;
            return $builder;
        });
        $builder->method('addSortOrder')->willReturnSelf();
        $builder->method('create')->willReturnCallback(function () {
            $criteria = new SearchCriteria();
            $criteria->setFilterGroups([]);
            $criteria->setPageSize(count($this->filters));
            return $criteria;
        });
        return $builder;
    }

    private function sortBuilder(): SortOrderBuilder
    {
        $sort = $this->createStub(SortOrderBuilder::class);
        $sort->method('setField')->willReturnSelf();
        $sort->method('setDirection')->willReturnSelf();
        $sort->method('create')->willReturn($this->createStub(SortOrder::class));
        return $sort;
    }

    private function orderResults(int $total, array $items = []): OrderSearchResultInterface
    {
        $r = $this->createStub(OrderSearchResultInterface::class);
        $r->method('getTotalCount')->willReturn($total);
        $r->method('getItems')->willReturn($items);
        return $r;
    }

    public function testDefinitionAndUnknownMetric(): void
    {
        $tool = new StoreInsights(
            $this->createStub(CustomerRepositoryInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
            $this->builder(),
            $this->sortBuilder()
        );
        $this->assertSame('store_insights', $tool->name());
        $this->assertSame(['metric'], $tool->definition()['input_schema']['required']);
        $this->assertSame(['status' => 'error', 'message' => 'Unknown metric: revenue'], $tool->execute(['metric' => 'revenue']));
    }

    public function testCustomerAndOrderCounts(): void
    {
        $customers = $this->createStub(CustomerSearchResultsInterface::class);
        $customers->method('getTotalCount')->willReturn(321);
        $customerRepo = $this->createStub(CustomerRepositoryInterface::class);
        $customerRepo->method('getList')->willReturn($customers);
        $orderRepo = $this->createStub(OrderRepositoryInterface::class);
        $orderRepo->method('getList')->willReturn($this->orderResults(54));

        $tool = new StoreInsights($customerRepo, $orderRepo, $this->builder(), $this->sortBuilder());

        $c = $tool->execute(['metric' => 'customer_count']);
        $this->assertSame(321, $c['count']);
        $this->assertSame('321 total customers.', $c['summary']);

        $o = $tool->execute(['metric' => 'order_count']);
        $this->assertSame(54, $o['count']);
        $this->assertSame('54 total orders.', $o['summary']);
        $this->assertSame([1, 1], $this->pageSizes);
    }

    public function testOrderCountByStatusQueriesEachStatus(): void
    {
        $counts = ['pending' => 3, 'processing' => 2, 'complete' => 10, 'canceled' => 1, 'closed' => 0];
        $orderRepo = $this->createStub(OrderRepositoryInterface::class);
        $orderRepo->method('getList')->willReturnCallback(function () use ($counts) {
            $last = end($this->filters);
            return $this->orderResults($counts[$last[1]]);
        });

        $tool = new StoreInsights($this->createStub(CustomerRepositoryInterface::class), $orderRepo, $this->builder(), $this->sortBuilder());
        $result = $tool->execute(['metric' => 'order_count_by_status']);

        $this->assertSame($counts, $result['by_status']);
        $this->assertSame(array_keys($counts), array_column($this->filters, 1));
        $this->assertSame('Order counts by status: ' . json_encode($counts), $result['summary']);
    }

    public function testRecentOrdersAreShapedAndLimitClamped(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getCreatedAt')->willReturn('2026-10-01 10:00:00');
        $order->method('getStatus')->willReturn('pending');
        $order->method('getGrandTotal')->willReturn('99.5');
        $order->method('getCustomerEmail')->willReturn('a@example.com');
        $orderRepo = $this->createStub(OrderRepositoryInterface::class);
        $orderRepo->method('getList')->willReturn($this->orderResults(1, [$order]));

        $tool = new StoreInsights($this->createStub(CustomerRepositoryInterface::class), $orderRepo, $this->builder(), $this->sortBuilder());
        $result = $tool->execute(['metric' => 'recent_orders', 'limit' => 500]);

        $this->assertSame([50], $this->pageSizes);
        $this->assertSame([[
            'increment_id' => '000000042',
            'created_at' => '2026-10-01 10:00:00',
            'status' => 'pending',
            'grand_total' => 99.5,
            'customer_email' => 'a@example.com',
        ]], $result['orders']);
        $this->assertSame('Last 1 orders.', $result['summary']);
    }

    public function testRepositoryFailureIsReturnedAsError(): void
    {
        $orderRepo = $this->createStub(OrderRepositoryInterface::class);
        $orderRepo->method('getList')->willThrowException(new \RuntimeException('db down'));
        $tool = new StoreInsights($this->createStub(CustomerRepositoryInterface::class), $orderRepo, $this->builder(), $this->sortBuilder());

        $this->assertSame(['status' => 'error', 'message' => 'db down'], $tool->execute(['metric' => 'order_count']));
    }
}
