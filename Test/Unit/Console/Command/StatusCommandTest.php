<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\ClaudeAi\Console\Command\StatusCommand;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\ToolInterface;
use Panth\ClaudeAi\Model\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class StatusCommandTest extends TestCase
{
    private function config(bool $enabled, string $key, bool $dryRun): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getApiKey')->willReturn($key);
        $config->method('getModel')->willReturn('claude-sonnet-4-6');
        $config->method('getEffort')->willReturn('medium');
        $config->method('getMaxIterations')->willReturn(6);
        $config->method('isDryRun')->willReturn($dryRun);
        $config->method('getMaxBulkUpdate')->willReturn(250);
        return $config;
    }

    private function resource(?AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        if ($connection === null) {
            $resource->method('getConnection')->willThrowException(new \RuntimeException('no db'));
        } else {
            $resource->method('getConnection')->willReturn($connection);
        }
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    public function testFullStatusOutput(): void
    {
        $tool = $this->createStub(ToolInterface::class);
        $tool->method('name')->willReturn('orders');
        $registry = new ToolRegistry([$tool]);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('fetchOne')->willReturnCallback(fn(string $sql) => match (true) {
            str_contains($sql, 'CURDATE') => '4',
            str_contains($sql, "status = 'active'") => '2',
            default => '123',
        });

        $command = new StatusCommand($this->config(true, 'key', true), $registry, $this->resource($connection));
        $tester = new CommandTester($command);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame('panth_claudeai:status', $command->getName());

        $out = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/Module enabled:\s+yes/', $out);
        $this->assertMatchesRegularExpression('/API key configured:\s+yes/', $out);
        $this->assertMatchesRegularExpression('/Model:\s+claude-sonnet-4-6/', $out);
        $this->assertMatchesRegularExpression('/Effort:\s+medium/', $out);
        $this->assertMatchesRegularExpression('/Max iterations:\s+6/', $out);
        $this->assertStringContainsString('ON (no real writes)', $out);
        $this->assertMatchesRegularExpression('/Bulk cap per call:\s+250/', $out);
        $this->assertStringContainsString('orders', $out);
        $this->assertMatchesRegularExpression('/Total entries:\s+123/', $out);
        $this->assertMatchesRegularExpression('/Today:\s+4/', $out);
        $this->assertMatchesRegularExpression('/Active checkpoints:\s+2/', $out);
    }

    public function testNoToolsMissingKeyAndMissingTables(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);

        $tester = new CommandTester(new StatusCommand($this->config(false, '', false), new ToolRegistry(), $this->resource($connection)));
        $tester->execute([]);
        $out = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/Module enabled:\s+no/', $out);
        $this->assertMatchesRegularExpression('/API key configured:\s+no/', $out);
        $this->assertMatchesRegularExpression('/Dry run:\s+off/', $out);
        $this->assertStringContainsString('(none - check Tool Capabilities config)', $out);
        $this->assertStringNotContainsString('Total entries', $out);
    }

    public function testDatabaseErrorIsPrintedButCommandSucceeds(): void
    {
        $tester = new CommandTester(new StatusCommand($this->config(true, 'k', false), new ToolRegistry(), $this->resource(null)));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Could not read activity table: no db', $tester->getDisplay());
    }
}
