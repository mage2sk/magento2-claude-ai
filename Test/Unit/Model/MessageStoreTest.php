<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\User\Model\User;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\MessageStore;
use Panth\ClaudeAi\Model\Pricing;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageStoreTest extends TestCase
{
    private ?array $inserted = null;

    private function build(
        ?AdapterInterface $connection = null,
        ?LoggerInterface $logger = null,
        ?int $userId = 4
    ): MessageStore {
        if ($connection === null) {
            $connection = $this->createStub(AdapterInterface::class);
            $connection->method('insert')->willReturnCallback(function (string $table, array $data) {
                $this->inserted = ['table' => $table, 'data' => $data];
                return 1;
            });
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn(string $t) => 'pre_' . $t);

        $config = $this->createStub(Config::class);
        $config->method('getModel')->willReturn('claude-sonnet-4-6');

        $user = null;
        if ($userId !== null) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($userId);
        }
        $session = $this->createStub(AdminSession::class);
        $session->method('__call')->willReturnCallback(fn(string $m) => $m === 'getUser' ? $user : null);

        return new MessageStore(
            $resource,
            new Pricing(),
            $config,
            $session,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testStringContentIsWrappedAsTextBlock(): void
    {
        $this->build()->record('cid1', 0, 'user', 'Hello / world');

        $this->assertSame('pre_panth_claudeai_message', $this->inserted['table']);
        $data = $this->inserted['data'];
        $this->assertSame('{"type":"text","text":"Hello / world"}', $data['content_json']);
        $this->assertSame('cid1', $data['conversation_id']);
        $this->assertSame(0, $data['sequence']);
        $this->assertSame('user', $data['role']);
        $this->assertSame('admin', $data['surface']);
        $this->assertNull($data['input_tokens']);
        $this->assertNull($data['cost_usd']);
        $this->assertSame('claude-sonnet-4-6', $data['model']);
        $this->assertSame(4, $data['admin_user_id']);
    }

    public function testUsageIsCostedAndStored(): void
    {
        $this->build()->record('cid1', 3, 'assistant', [['type' => 'text', 'text' => 'ok']], 'admin', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 1_000_000,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 10,
        ]);

        $data = $this->inserted['data'];
        $this->assertSame('[{"type":"text","text":"ok"}]', $data['content_json']);
        $this->assertSame(1_000_000, $data['input_tokens']);
        $this->assertSame(1_000_000, $data['output_tokens']);
        $this->assertNull($data['cache_read_tokens']);
        $this->assertSame(10, $data['cache_write_tokens']);
        $this->assertSame((new Pricing())->costFor('claude-sonnet-4-6', 1_000_000, 1_000_000, 0, 10), $data['cost_usd']);
    }

    public function testNonAdminSurfaceStoresNoUser(): void
    {
        $this->build()->record('cid1', 0, 'user', 'hi', 'cli');
        $this->assertSame('cli', $this->inserted['data']['surface']);
        $this->assertNull($this->inserted['data']['admin_user_id']);
    }

    public function testMissingAdminUserStoresNull(): void
    {
        $this->build(null, null, null)->record('cid1', 0, 'user', 'hi');
        $this->assertNull($this->inserted['data']['admin_user_id']);
    }

    public function testUnencodableContentIsSkipped(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('insert');

        $this->build($connection)->record('cid1', 0, 'user', ["\xB1\x31"]);
    }

    public function testDatabaseFailureIsLoggedNotThrown(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('db gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('db gone'));

        $this->build($connection, $logger)->record('cid1', 0, 'user', 'hi');
    }
}
