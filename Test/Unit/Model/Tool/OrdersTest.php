<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\Orders;
use PHPUnit\Framework\TestCase;

class OrdersTest extends TestCase
{
    private array $filters = [];
    private ?int $pageSize = null;

    private function order(int $id = 42, string $status = 'pending'): Order
    {
        $item = $this->createStub(Item::class);
        $item->method('getSku')->willReturn('MH01');
        $item->method('getName')->willReturn('Hoodie');
        $item->method('getQtyOrdered')->willReturn('2.0000');
        $item->method('getRowTotal')->willReturn('80.0000');

        $o = $this->createStub(Order::class);
        $o->method('getId')->willReturn($id);
        $o->method('getIncrementId')->willReturn('000000042');
        $o->method('getStatus')->willReturn($status);
        $o->method('getState')->willReturn('new');
        $o->method('getCreatedAt')->willReturn('2026-10-01 10:00:00');
        $o->method('getCustomerEmail')->willReturn('a@example.com');
        $o->method('getCustomerFirstname')->willReturn('Ann');
        $o->method('getCustomerLastname')->willReturn('Lee');
        $o->method('getGrandTotal')->willReturn('85.5000');
        $o->method('getOrderCurrencyCode')->willReturn('USD');
        $o->method('getStoreId')->willReturn('1');
        $o->method('getAllVisibleItems')->willReturn([$item]);
        return $o;
    }

    private function results(array $items, int $total = 0): OrderSearchResultInterface
    {
        $r = $this->createStub(OrderSearchResultInterface::class);
        $r->method('getItems')->willReturn($items);
        $r->method('getTotalCount')->willReturn($total ?: count($items));
        return $r;
    }

    private function tool(
        ?OrderRepositoryInterface $repo = null,
        ?OrderManagementInterface $management = null,
        bool $dryRun = false,
        ?OrderStatusHistoryRepositoryInterface $historyRepo = null,
        ?OrderStatusHistoryInterfaceFactory $historyFactory = null
    ): Orders {
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($f, $v, $t = 'eq') use (&$builder) {
            $this->filters[] = [$f, $v, $t];
            return $builder;
        });
        $builder->method('setPageSize')->willReturnCallback(function ($s) use (&$builder) {
            $this->pageSize = $s;
            return $builder;
        });
        $builder->method('addSortOrder')->willReturnSelf();
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        $sort = $this->createStub(SortOrderBuilder::class);
        $sort->method('setField')->willReturnSelf();
        $sort->method('setDirection')->willReturnSelf();
        $sort->method('create')->willReturn($this->createStub(SortOrder::class));

        $config = $this->createStub(Config::class);
        $config->method('isDryRun')->willReturn($dryRun);

        return new Orders(
            $repo ?? $this->createStub(OrderRepositoryInterface::class),
            $management ?? $this->createStub(OrderManagementInterface::class),
            $historyRepo ?? $this->createStub(OrderStatusHistoryRepositoryInterface::class),
            $historyFactory ?? $this->createStub(OrderStatusHistoryInterfaceFactory::class),
            $builder,
            $sort,
            $config
        );
    }

    private function repoWith(array $orders): OrderRepositoryInterface
    {
        $repo = $this->createStub(OrderRepositoryInterface::class);
        $repo->method('getList')->willReturn($this->results($orders));
        $repo->method('get')->willReturn($this->order(42, 'holded'));
        return $repo;
    }

    public function testDefinitionAndUnknownAction(): void
    {
        $tool = $this->tool();
        $this->assertSame('orders', $tool->name());
        $this->assertSame(['action'], $tool->definition()['input_schema']['required']);
        $this->assertSame(['status' => 'error', 'message' => 'Unknown action: refund'], $tool->execute(['action' => 'refund']));
    }

    public function testSearchAppliesFilters(): void
    {
        $repo = $this->createStub(OrderRepositoryInterface::class);
        $repo->method('getList')->willReturn($this->results([$this->order()], 77));

        $result = $this->tool($repo)->execute([
            'action' => 'search',
            'status' => 'pending',
            'email_contains' => 'ann',
            'created_after' => '2026-01-01',
            'limit' => 0,
        ]);

        $this->assertSame([
            ['status', 'pending', 'eq'],
            ['customer_email', '%ann%', 'like'],
            ['created_at', '2026-01-01', 'gteq'],
        ], $this->filters);
        $this->assertSame(1, $this->pageSize);
        $this->assertSame(77, $result['total']);
        $this->assertSame([
            'order_id' => 42,
            'increment_id' => '000000042',
            'status' => 'pending',
            'state' => 'new',
            'created_at' => '2026-10-01 10:00:00',
            'customer_email' => 'a@example.com',
            'customer_name' => 'Ann Lee',
            'grand_total' => 85.5,
            'order_currency' => 'USD',
            'store_id' => 1,
        ], $result['orders'][0]);
        $this->assertSame('77 orders match (showing 1).', $result['summary']);
    }

    public function testGetValidatesAndHandlesMissingOrder(): void
    {
        $this->assertSame(['status' => 'error', 'message' => 'increment_id is required.'], $this->tool()->execute(['action' => 'get']));
        $this->assertSame(
            ['status' => 'error', 'message' => 'Order not found: 999'],
            $this->tool($this->repoWith([]))->execute(['action' => 'get', 'increment_id' => '999'])
        );
    }

    public function testGetReturnsOrderWithItems(): void
    {
        $result = $this->tool($this->repoWith([$this->order()]))->execute(['action' => 'get', 'increment_id' => ' 000000042 ']);

        $this->assertSame(['increment_id', '000000042', 'eq'], $this->filters[0]);
        $this->assertSame([['sku' => 'MH01', 'name' => 'Hoodie', 'qty' => 2.0, 'row_total' => 80.0]], $result['order']['items']);
        $this->assertSame('Order 000000042 - pending, 1 items, total 85.50.', $result['summary']);
    }

    public function testUpdateStatusValidation(): void
    {
        $this->assertSame(
            'increment_id and operation (hold|unhold|cancel) are required.',
            $this->tool()->execute(['action' => 'update_status', 'increment_id' => '1', 'operation' => 'ship'])['message']
        );
        $this->assertSame(
            'Order not found: 1',
            $this->tool($this->repoWith([]))->execute(['action' => 'update_status', 'increment_id' => '1', 'operation' => 'hold'])['message']
        );
    }

    public function testCancelNeedsConfirmation(): void
    {
        $management = $this->createMock(OrderManagementInterface::class);
        $management->expects($this->never())->method('cancel');

        $result = $this->tool($this->repoWith([$this->order()]), $management)
            ->execute(['action' => 'update_status', 'increment_id' => '000000042', 'operation' => 'CANCEL']);

        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame('000000042', $result['preview']['increment_id']);
    }

    public function testDryRunDoesNotTouchOrder(): void
    {
        $management = $this->createMock(OrderManagementInterface::class);
        $management->expects($this->never())->method('hold');

        $result = $this->tool($this->repoWith([$this->order()]), $management, true)
            ->execute(['action' => 'update_status', 'increment_id' => '000000042', 'operation' => 'hold']);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame('[DRY RUN] Would hold order 000000042 (current status: pending).', $result['message']);
    }

    public function testHoldSucceedsAndReloadsOrder(): void
    {
        $management = $this->createMock(OrderManagementInterface::class);
        $management->expects($this->once())->method('hold')->with(42)->willReturn(true);

        $result = $this->tool($this->repoWith([$this->order()]), $management)
            ->execute(['action' => 'update_status', 'increment_id' => '000000042', 'operation' => 'hold']);

        $this->assertSame('success', $result['status']);
        $this->assertSame('holded', $result['order']['status']);
        $this->assertSame('Order 000000042 holded. New status: holded.', $result['summary']);
    }

    public function testConfirmedCancelThatFailsReportsError(): void
    {
        $management = $this->createMock(OrderManagementInterface::class);
        $management->expects($this->once())->method('cancel')->with(42)->willReturn(false);

        $result = $this->tool($this->repoWith([$this->order()]), $management)
            ->execute(['action' => 'update_status', 'increment_id' => '000000042', 'operation' => 'cancel', 'confirm' => true]);

        $this->assertSame(['status' => 'error', 'message' => 'Order cancel failed - order may not be eligible.'], $result);
    }

    public function testUnholdUsesManagement(): void
    {
        $management = $this->createMock(OrderManagementInterface::class);
        $management->expects($this->once())->method('unHold')->with(42)->willReturn(true);

        $result = $this->tool($this->repoWith([$this->order()]), $management)
            ->execute(['action' => 'update_status', 'increment_id' => '000000042', 'operation' => 'unhold']);
        $this->assertSame('success', $result['status']);
    }

    public function testAddCommentValidationAndDryRun(): void
    {
        $this->assertSame(
            'increment_id and comment are required.',
            $this->tool()->execute(['action' => 'add_comment', 'increment_id' => '1', 'comment' => ' '])['message']
        );

        $factory = $this->createMock(OrderStatusHistoryInterfaceFactory::class);
        $factory->expects($this->never())->method('create');
        $result = $this->tool($this->repoWith([$this->order()]), null, true, null, $factory)
            ->execute(['action' => 'add_comment', 'increment_id' => '000000042', 'comment' => 'hi', 'notify_customer' => true]);

        $this->assertSame('[DRY RUN] Would add comment to order 000000042 (notify=yes).', $result['message']);
    }

    public function testAddCommentSavesHistory(): void
    {
        $history = $this->createMock(OrderStatusHistoryInterface::class);
        $history->expects($this->once())->method('setParentId')->with(42);
        $history->expects($this->once())->method('setComment')->with('Packed');
        $history->expects($this->once())->method('setStatus')->with('pending');
        $history->expects($this->once())->method('setIsCustomerNotified')->with(0);
        $history->expects($this->once())->method('setIsVisibleOnFront')->with(0);
        $history->expects($this->once())->method('setEntityName')->with('order');
        $factory = $this->createStub(OrderStatusHistoryInterfaceFactory::class);
        $factory->method('create')->willReturn($history);
        $historyRepo = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $historyRepo->expects($this->once())->method('save')->with($history);

        $result = $this->tool($this->repoWith([$this->order()]), null, false, $historyRepo, $factory)
            ->execute(['action' => 'add_comment', 'increment_id' => '000000042', 'comment' => ' Packed ']);

        $this->assertSame('Comment added to order 000000042.', $result['summary']);
    }

    public function testRepositoryExceptionIsReturnedAsError(): void
    {
        $repo = $this->createStub(OrderRepositoryInterface::class);
        $repo->method('getList')->willThrowException(new \RuntimeException('db down'));

        $this->assertSame(['status' => 'error', 'message' => 'db down'], $this->tool($repo)->execute(['action' => 'search']));
    }
}
