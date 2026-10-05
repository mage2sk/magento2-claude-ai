<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Framework\AuthorizationInterface;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\ToolInterface;
use Panth\ClaudeAi\Model\ToolRegistry;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
    private function tool(string $name): ToolInterface
    {
        $tool = $this->createStub(ToolInterface::class);
        $tool->method('name')->willReturn($name);
        $tool->method('definition')->willReturn(['name' => $name]);
        return $tool;
    }

    private function config(array $disabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isToolEnabled')->willReturnCallback(fn(string $n) => !in_array($n, $disabled, true));
        return $config;
    }

    public function testToolsAreKeyedByNameSortedAndNonToolsIgnored(): void
    {
        $registry = new ToolRegistry([$this->tool('orders'), new \stdClass(), $this->tool('customers')]);

        $this->assertSame(['customers', 'orders'], array_keys($registry->all()));
        $this->assertSame(['customers', 'orders'], array_keys($registry->enabled()));
        $this->assertTrue($registry->has('orders'));
        $this->assertNull($registry->get('missing'));
        $this->assertSame([['name' => 'customers'], ['name' => 'orders']], $registry->definitions());
    }

    public function testDisabledToolsAreHidden(): void
    {
        $registry = new ToolRegistry(
            [$this->tool('orders'), $this->tool('customers')],
            $this->config(['orders'])
        );

        $this->assertSame(['customers', 'orders'], array_keys($registry->all()));
        $this->assertSame(['customers'], array_keys($registry->enabled()));
        $this->assertFalse($registry->has('orders'));
        $this->assertNull($registry->get('orders'));
        $this->assertSame([['name' => 'customers']], $registry->definitions());
    }

    public function testAclDeniedToolsAreHidden(): void
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(fn(string $r) => $r !== 'Magento_Sales::sales_order');

        $orders = $this->tool('orders');
        $registry = new ToolRegistry(
            [$orders, $this->tool('customers'), $this->tool('get_modules')],
            $this->config([]),
            $auth
        );

        $this->assertFalse($registry->isAllowed('orders'));
        $this->assertTrue($registry->isAllowed('customers'));
        $this->assertTrue($registry->isAllowed('get_modules'));
        $this->assertSame(['customers', 'get_modules'], array_column($registry->definitions(), 'name'));
        $this->assertNull($registry->get('orders'));
        $this->assertArrayHasKey('orders', $registry->enabled());
    }

    public function testDatabaseQueryRequiresFullAdminResource(): void
    {
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->expects($this->once())->method('isAllowed')->with('Magento_Backend::all')->willReturn(false);

        $registry = new ToolRegistry([], null, $auth);
        $this->assertFalse($registry->isAllowed('database_query'));
    }

    public function testAuthorizationFailureDeniesTool(): void
    {
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willThrowException(new \RuntimeException('acl down'));

        $registry = new ToolRegistry([$this->tool('orders')], null, $auth);
        $this->assertFalse($registry->isAllowed('orders'));
        $this->assertFalse($registry->has('orders'));
    }

    public function testWithoutAuthorizationEverythingIsAllowed(): void
    {
        $this->assertTrue((new ToolRegistry())->isAllowed('database_query'));
    }
}
