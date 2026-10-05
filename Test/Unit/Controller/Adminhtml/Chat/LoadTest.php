<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\User\Model\User;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\Load;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\ContextBuilderTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LoadTest extends TestCase
{
    use ContextBuilderTrait;

    private ?array $data = null;
    private ?array $queryBinds = null;

    private function controller(array $params, $rows, bool $enabled = true, ?LoggerInterface $logger = null): Load
    {
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->data = $data;
            return $json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturnCallback(function ($sql, $binds) use ($rows) {
            $this->queryBinds = $binds;
            if ($rows instanceof \Throwable) {
                throw $rows;
            }
            return $rows;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $session = $this->createStub(AdminSession::class);
        $session->method('__call')->willReturnCallback(fn(string $m) => $m === 'getUser' ? $user : null);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new Load(
            $this->buildContext($this->request($params)),
            $jsonFactory,
            $resource,
            $session,
            $logger ?? $this->createStub(LoggerInterface::class),
            $config
        );
    }

    private static function row(string $role, $content, string $at = '2026-10-01 10:00:00', ?string $cost = null): array
    {
        return [
            'role' => $role,
            'content_json' => is_string($content) ? $content : json_encode($content),
            'created_at' => $at,
            'cost_usd' => $cost,
        ];
    }

    public function testDisabledModule(): void
    {
        $this->controller(['cid' => 'abc'], [], false)->execute();
        $this->assertSame(['success' => false, 'error' => 'Claude AI is disabled.'], $this->data);
    }

    public function testConversationIdRequired(): void
    {
        $this->controller([], [])->execute();
        $this->assertSame(['success' => false, 'error' => 'conversation_id is required.'], $this->data);
    }

    public function testUnknownConversation(): void
    {
        $this->controller(['id' => 'abc'], [])->execute();
        $this->assertSame(['success' => false, 'error' => 'Conversation not found.'], $this->data);
        $this->assertSame(['abc', 7], $this->queryBinds);
    }

    public function testMessagesAreRebuiltAndMerged(): void
    {
        $rows = [
            self::row('user', ['type' => 'text', 'text' => 'Hi'], '2026-10-01 10:00:00'),
            self::row('assistant', [['type' => 'tool_use', 'id' => 't1', 'name' => 'orders', 'input' => []]], '2026-10-01 10:00:01', '0.001'),
            self::row('tool_result', [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{}']], '2026-10-01 10:00:02'),
            self::row('user', ['type' => 'text', 'text' => 'And?'], '2026-10-01 10:00:03'),
            self::row('system', ['type' => 'text', 'text' => 'ignored'], '2026-10-01 10:00:04'),
            self::row('assistant', 'not json', '2026-10-01 10:00:05'),
            self::row('assistant', [['type' => 'text', 'text' => 'Done']], '2026-10-01 10:00:06', '0.0025'),
        ];

        $this->controller(['cid' => 'abc'], $rows)->execute();

        $this->assertTrue($this->data['success']);
        $messages = $this->data['messages'];
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column($messages, 'role'));
        $this->assertSame('Hi', $messages[0]['content'][0]['text']);
        $this->assertCount(2, $messages[2]['content']);
        $this->assertSame('tool_result', $messages[2]['content'][0]['type']);
        $this->assertSame('And?', $messages[2]['content'][1]['text']);
        $this->assertSame([
            'turns' => 7,
            'started_at' => '2026-10-01 10:00:00',
            'last_at' => '2026-10-01 10:00:06',
            'cost_usd' => 0.0035,
        ], $this->data['stats']);
    }

    public function testDatabaseFailureIsHidden(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('SQLSTATE'));

        $this->controller(['cid' => 'abc'], new \RuntimeException('SQLSTATE[42S02]'), true, $logger)->execute();

        $this->assertSame(['success' => false, 'error' => 'Failed to load conversation.'], $this->data);
    }
}
