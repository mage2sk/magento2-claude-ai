<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Activity;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\User\Model\User;
use Panth\ClaudeAi\Model\Activity\Logger;
use Panth\ClaudeAi\Model\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LoggerTest extends TestCase
{
    private function session(bool $throws = false): AdminSession
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(12);
        $session = $this->createStub(AdminSession::class);
        $session->method('__call')->willReturnCallback(function (string $m) use ($user, $throws) {
            if ($throws) {
                throw new \RuntimeException('no session');
            }
            return $m === 'getUser' ? $user : null;
        });
        return $session;
    }

    private function resource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function config(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoggingEnabled')->willReturn($enabled);
        return $config;
    }

    public function testRowIsMergedOverDefaults(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('insert')->with(
            'panth_claudeai_activity',
            $this->callback(function (array $data) {
                $this->assertSame('tool', $data['actor_type']);
                $this->assertSame('orders', $data['action']);
                $this->assertSame('success', $data['status']);
                $this->assertSame(0, $data['affected_count']);
                $this->assertNull($data['prompt']);
                $this->assertSame(12, $data['admin_user_id']);
                return true;
            })
        );

        $logger = new Logger($this->resource($connection), $this->session(), $this->createStub(LoggerInterface::class), $this->config(true));
        $logger->log(['actor_type' => 'tool', 'action' => 'orders']);
    }

    public function testSessionFailureLogsWithoutUser(): void
    {
        $captured = null;
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insert')->willReturnCallback(function ($t, array $data) use (&$captured) {
            $captured = $data;
            return 1;
        });

        $logger = new Logger($this->resource($connection), $this->session(true), $this->createStub(LoggerInterface::class), $this->config(true));
        $logger->log(['action' => 'x', 'admin_user_id' => null]);

        $this->assertNull($captured['admin_user_id']);
        $this->assertSame('admin', $captured['actor_type']);
    }

    public function testDisabledLoggingWritesNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('insert');

        $logger = new Logger($this->resource($connection), $this->session(), $this->createStub(LoggerInterface::class), $this->config(false));
        $logger->log(['action' => 'x']);
    }

    public function testInsertFailureIsReportedAsWarning(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('table missing'));
        $psr = $this->createMock(LoggerInterface::class);
        $psr->expects($this->once())->method('warning')->with($this->stringContains('table missing'));

        $logger = new Logger($this->resource($connection), $this->session(), $psr, $this->config(true));
        $logger->log(['action' => 'x']);
    }
}
