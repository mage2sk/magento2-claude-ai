<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\ClaudeAi\Model\Tool\Customers;
use PHPUnit\Framework\TestCase;

class CustomersTest extends TestCase
{
    private function customer(int $id, string $email): CustomerInterface
    {
        $c = $this->createStub(CustomerInterface::class);
        $c->method('getId')->willReturn($id);
        $c->method('getEmail')->willReturn($email);
        $c->method('getFirstname')->willReturn('Ann');
        $c->method('getLastname')->willReturn('Lee');
        $c->method('getGroupId')->willReturn(1);
        $c->method('getCreatedAt')->willReturn('2026-01-01 00:00:00');
        $c->method('getWebsiteId')->willReturn(1);
        return $c;
    }

    public function testDefinitionAndUnknownAction(): void
    {
        $tool = new Customers($this->createStub(CustomerRepositoryInterface::class), $this->createStub(SearchCriteriaBuilder::class));
        $this->assertSame('customers', $tool->name());
        $this->assertSame(['action'], $tool->definition()['input_schema']['required']);
        $this->assertSame(['status' => 'error', 'message' => 'Unknown action: drop'], $tool->execute(['action' => 'drop']));
    }

    public function testGetRequiresIdOrEmail(): void
    {
        $tool = new Customers($this->createStub(CustomerRepositoryInterface::class), $this->createStub(SearchCriteriaBuilder::class));
        $this->assertSame(['status' => 'error', 'message' => 'Provide customer_id or email.'], $tool->execute(['action' => 'get']));
    }

    public function testGetById(): void
    {
        $repo = $this->createMock(CustomerRepositoryInterface::class);
        $repo->expects($this->once())->method('getById')->with(5)->willReturn($this->customer(5, 'ann@example.com'));
        $repo->expects($this->never())->method('get');

        $result = (new Customers($repo, $this->createStub(SearchCriteriaBuilder::class)))
            ->execute(['action' => 'get', 'customer_id' => 5, 'email' => 'ignored@example.com']);

        $this->assertSame('success', $result['status']);
        $this->assertSame([
            'customer_id' => 5,
            'email' => 'ann@example.com',
            'firstname' => 'Ann',
            'lastname' => 'Lee',
            'group_id' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'website_id' => 1,
        ], $result['customer']);
        $this->assertSame('Customer #5 - Ann Lee (ann@example.com)', $result['summary']);
    }

    public function testGetByEmailNotFound(): void
    {
        $repo = $this->createMock(CustomerRepositoryInterface::class);
        $repo->expects($this->once())->method('get')->with('x@example.com')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));

        $result = (new Customers($repo, $this->createStub(SearchCriteriaBuilder::class)))
            ->execute(['action' => 'get', 'email' => ' x@example.com ']);

        $this->assertSame(['status' => 'error', 'message' => 'No such entity.'], $result);
    }

    public function testSearchAppliesEscapedFiltersAndLimit(): void
    {
        $filters = [];
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($field, $value, $type) use (&$filters, &$builder) {
            $filters[] = [$field, $value, $type];
            return $builder;
        });
        $builder->expects($this->once())->method('setPageSize')->with(100)->willReturnSelf();
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        $results = $this->createStub(CustomerSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$this->customer(1, 'a@example.com'), $this->customer(2, 'b@example.com')]);
        $results->method('getTotalCount')->willReturn(40);
        $repo = $this->createStub(CustomerRepositoryInterface::class);
        $repo->method('getList')->willReturn($results);

        $result = (new Customers($repo, $builder))->execute([
            'action' => 'search',
            'email_contains' => '50%',
            'name_contains' => 'Ann',
            'created_after' => '2026-01-01',
            'limit' => 999,
        ]);

        $this->assertSame([
            ['email', '%50\\%%', 'like'],
            ['firstname', '%Ann%', 'like'],
            ['created_at', '2026-01-01', 'gteq'],
        ], $filters);
        $this->assertSame(2, $result['affected_count']);
        $this->assertSame(40, $result['total']);
        $this->assertSame('40 customers match (showing 2).', $result['summary']);
    }
}
