<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Model\Stats;
use PHPUnit\Framework\TestCase;

class StatsTest extends TestCase
{
    private function build(AdapterInterface $connection): Stats
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $config = $this->createStub(Config::class);
        $config->method('getModel')->willReturn('claude-haiku-4-5');

        return new Stats($resource, new Pricing(), $config);
    }

    public function testMissingTableReturnsEmptyStats(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);

        $stats = $this->build($connection)->compute();

        $this->assertSame(0, $stats['total_automations']);
        $this->assertSame(100.0, $stats['success_rate']);
        $this->assertSame([], $stats['trend_7d']);
        $this->assertSame(0.0, $stats['total_cost_usd']);
        $this->assertSame('claude-haiku-4-5', $stats['cost_model']);
    }

    public function testStatsAreComputedFromQueries(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) {
            return match (true) {
                str_contains($sql, "status = 'success'") => '3',
                str_contains($sql, "actor_type = 'tool'") => '4',
                str_contains($sql, 'CURDATE() - INTERVAL 1 MONTH') => '6',
                str_contains($sql, 'DATE(created_at) = CURDATE()') => '2',
                str_contains($sql, "DATE_FORMAT(CURDATE(), '%Y-%m-01')") => '9',
                default => '40',
            };
        });
        $connection->method('fetchAll')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'GROUP BY action')) {
                return [['action' => 'orders', 'n' => 4]];
            }
            if (str_contains($sql, 'GROUP BY DATE')) {
                return [['day' => '2026-10-01', 'n' => 5]];
            }
            return [['entity_id' => 1]];
        });
        $connection->method('fetchRow')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'DATE(created_at) = CURDATE()')) {
                return ['in_tot' => 0, 'out_tot' => 0, 'cache_tot' => 0];
            }
            if (str_contains($sql, 'DATE_FORMAT')) {
                return ['in_tot' => 1_000_000, 'out_tot' => 0, 'cache_tot' => 0];
            }
            return ['in_tot' => 1_000_000, 'out_tot' => 1_000_000, 'cache_tot' => 1_000_000];
        });

        $stats = $this->build($connection)->compute();

        $this->assertSame(40, $stats['total_automations']);
        $this->assertSame(4, $stats['tasks_executed']);
        $this->assertSame(75.0, $stats['success_rate']);
        $this->assertSame(2.0, $stats['time_saved_hours']);
        $this->assertSame(2, $stats['today_count']);
        $this->assertSame(9, $stats['month_count']);
        $this->assertSame(6, $stats['prev_month_count']);
        $this->assertSame([['action' => 'orders', 'n' => 4]], $stats['most_used_tools']);
        $this->assertSame([['day' => '2026-10-01', 'n' => 5]], $stats['trend_7d']);
        $this->assertSame([['entity_id' => 1]], $stats['recent']);
        $this->assertSame(1_000_000, $stats['total_cache_tokens']);
        $this->assertSame(6.1, $stats['total_cost_usd']);
        $this->assertSame(0.0, $stats['today_cost_usd']);
        $this->assertSame(1.0, $stats['month_cost_usd']);
    }

    public function testNoToolRunsMeansFullSuccessRate(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchRow')->willReturn(['in_tot' => 0, 'out_tot' => 0, 'cache_tot' => 0]);

        $stats = $this->build($connection)->compute();
        $this->assertSame(100.0, $stats['success_rate']);
        $this->assertSame(0.0, $stats['time_saved_hours']);
    }
}
