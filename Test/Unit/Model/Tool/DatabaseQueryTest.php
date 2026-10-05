<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\QueryGuard;
use Panth\ClaudeAi\Model\Tool\DatabaseQuery;
use Panth\ClaudeAi\Model\Tool\RestoreCheckpoint;
use PHPUnit\Framework\TestCase;

class DatabaseQueryTest extends TestCase
{
    private function tool(AdapterInterface $connection, string $prefix = ''): DatabaseQuery
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn(string $t) => $prefix . $t);

        return new DatabaseQuery($resource, $this->createStub(Config::class), new QueryGuard());
    }

    public function testDefinition(): void
    {
        $tool = $this->tool($this->createStub(AdapterInterface::class));
        $this->assertSame('database_query', $tool->name());
        $this->assertSame('database_query', $tool->definition()['name']);
        $this->assertSame(['sql'], $tool->definition()['input_schema']['required']);
    }

    public function testEmptySqlIsRejected(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('fetchAll');

        $this->assertSame(
            ['status' => 'error', 'message' => 'sql is required.'],
            $this->tool($connection)->execute(['sql' => '  '])
        );
    }

    public function testGuardRejectionIsReturnedAsError(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('fetchAll');

        $result = $this->tool($connection)->execute(['sql' => 'DELETE FROM sales_order']);
        $this->assertSame('error', $result['status']);
        $this->assertSame('Only a single SELECT statement is allowed.', $result['message']);
    }

    public function testCustomerGridRowsAreLimitedAndRedacted(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('fetchAll')
            ->with('SELECT /*+ MAX_EXECUTION_TIME(15000) */ * FROM pre_customer_grid_flat LIMIT 2')
            ->willReturn([
                ['entity_id' => 1, 'email' => 'x'],
                ['entity_id' => 2, 'email' => 'y'],
                ['entity_id' => 3, 'email' => 'z'],
            ]);

        $result = $this->tool($connection, 'pre_')->execute([
            'sql' => 'SELECT * FROM pre_customer_grid_flat',
            'limit' => 2,
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame(
            [['entity_id' => 1, 'email' => '[redacted]'], ['entity_id' => 2, 'email' => '[redacted]']],
            $result['rows']
        );
    }

    public function testSuccessfulQueryReturnsRowsAndColumns(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn([
            ['sku' => 'A', 'password' => 'p'],
            ['sku' => 'B', 'password' => 'q'],
            ['sku' => 'C', 'password' => 'r'],
        ]);

        $result = $this->tool($connection)->execute(['sql' => 'SELECT sku FROM catalog_product_entity', 'limit' => 2]);

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['affected_count']);
        $this->assertSame([['sku' => 'A', 'password' => '[redacted]'], ['sku' => 'B', 'password' => '[redacted]']], $result['rows']);
        $this->assertSame(['sku', 'password'], $result['columns']);
        $this->assertSame('SELECT /*+ MAX_EXECUTION_TIME(15000) */ sku FROM catalog_product_entity LIMIT 2', $result['sql_run']);
        $this->assertSame('2 row(s) returned.', $result['summary']);
    }

    public function testEmptyResultHasNoColumns(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn([]);

        $result = $this->tool($connection)->execute(['sql' => 'SELECT 1']);
        $this->assertSame([], $result['columns']);
        $this->assertStringEndsWith('LIMIT 100', $result['sql_run']);
    }

    public function testDatabaseExceptionIsReturnedAsError(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willThrowException(new \RuntimeException('Unknown column'));

        $this->assertSame(
            ['status' => 'error', 'message' => 'Unknown column'],
            $this->tool($connection)->execute(['sql' => 'SELECT nope FROM t'])
        );
    }

    public function testRestoreCheckpointTool(): void
    {
        $service = $this->createMock(CheckpointService::class);
        $service->expects($this->once())->method('restore')->with('cp_abc')->willReturn(['status' => 'success']);
        $tool = new RestoreCheckpoint($service);

        $this->assertSame('restore_checkpoint', $tool->name());
        $this->assertSame(['checkpoint_id'], $tool->definition()['input_schema']['required']);
        $this->assertSame(['status' => 'error', 'message' => 'checkpoint_id is required.'], $tool->execute(['checkpoint_id' => ' ']));
        $this->assertSame(['status' => 'success'], $tool->execute(['checkpoint_id' => ' cp_abc ']));
    }
}
