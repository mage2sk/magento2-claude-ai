<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\ClaudeAi\Model\ConversationHistory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ConversationHistoryTest extends TestCase
{
    private function build(array $rows, bool $foreign): ConversationHistory
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($foreign ? '5' : false);
        $connection->method('fetchAll')->willReturn($rows);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ConversationHistory($resource);
    }

    private static function row(int $seq, string $role, array $content): array
    {
        return ['sequence' => $seq, 'role' => $role, 'content_json' => json_encode($content)];
    }

    public function testHistoryIsRebuiltFromStoredRows(): void
    {
        $rows = [
            self::row(0, 'user', ['type' => 'text', 'text' => 'How many orders?']),
            self::row(1, 'assistant', [['type' => 'tool_use', 'id' => 't1', 'name' => 'store_insights', 'input' => []]]),
            self::row(2, 'tool_result', [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{}']]),
            self::row(3, 'assistant', [['type' => 'text', 'text' => 'You have 3 orders.']]),
        ];

        $resolved = $this->build($rows, false)->resolve('abc123', 7);

        $this->assertSame('abc123', $resolved['conversation_id']);
        $this->assertSame(4, $resolved['next_sequence']);
        $this->assertCount(4, $resolved['history']);
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column($resolved['history'], 'role'));
        $this->assertSame('You have 3 orders.', $resolved['history'][3]['content'][0]['text']);
    }

    public function testDanglingTurnsAreDropped(): void
    {
        $rows = [
            self::row(0, 'user', ['type' => 'text', 'text' => 'Hi']),
            self::row(1, 'assistant', [['type' => 'text', 'text' => 'Hello']]),
            self::row(2, 'user', ['type' => 'text', 'text' => 'Raise prices']),
            self::row(3, 'assistant', [['type' => 'tool_use', 'id' => 't9', 'name' => 'x', 'input' => []]]),
        ];

        $resolved = $this->build($rows, false)->resolve('abc123', 7);

        $this->assertSame(4, $resolved['next_sequence']);
        $this->assertSame(['user', 'assistant'], array_column($resolved['history'], 'role'));
    }

    public function testForeignConversationStartsFresh(): void
    {
        $rows = [self::row(0, 'user', ['type' => 'text', 'text' => 'secret'])];

        $resolved = $this->build($rows, true)->resolve('abc123', 7);

        $this->assertNotSame('abc123', $resolved['conversation_id']);
        $this->assertSame([], $resolved['history']);
        $this->assertSame(0, $resolved['next_sequence']);
    }

    public function testInvalidIdStartsFresh(): void
    {
        $resolved = $this->build([], false)->resolve("x' OR 1=1", 7);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $resolved['conversation_id']);
        $this->assertSame([], $resolved['history']);
    }
}
