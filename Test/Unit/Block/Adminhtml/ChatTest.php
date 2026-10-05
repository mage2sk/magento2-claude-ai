<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Block\Adminhtml;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\ClaudeAi\Block\Adminhtml\Chat;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\ToolInterface;
use Panth\ClaudeAi\Model\ToolRegistry;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class ChatTest extends TestCase
{
    use BlockContextTrait;

    protected function setUp(): void
    {
        $this->installObjectManager();
    }

    protected function tearDown(): void
    {
        $this->restoreObjectManager();
    }

    private function block(?AdapterInterface $connection = null, array $tools = [], bool $enabled = true, string $key = 'k'): Chat
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection ?? $this->createStub(AdapterInterface::class));
        $resource->method('getTableName')->willReturnArgument(0);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getApiKey')->willReturn($key);
        $config->method('getModel')->willReturn('claude-haiku-4-5');
        $config->method('isDryRun')->willReturn(true);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $session = $this->createStub(AdminSession::class);
        $session->method('__call')->willReturnCallback(fn(string $m) => $m === 'getUser' ? $user : null);

        return new Chat(
            $this->blockContext([], null, [], 'FK'),
            $config,
            new ToolRegistry($tools),
            $resource,
            $session
        );
    }

    public function testRecentConversationsWithPreviews(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $binds = []) {
            if (str_contains($sql, 'GROUP BY conversation_id')) {
                $this->assertStringEndsWith('LIMIT 5', trim($sql));
                $this->assertStringContainsString('admin_user_id = ? OR admin_user_id IS NULL', $sql);
                $this->assertSame([7], $binds);
                return [
                    ['conversation_id' => 'c1', 'started_at' => 'a', 'last_at' => 'b', 'turns' => '4'],
                    ['conversation_id' => 'c2', 'started_at' => 'c', 'last_at' => 'd', 'turns' => '2'],
                    ['conversation_id' => 'c3', 'started_at' => 'e', 'last_at' => 'f', 'turns' => '1'],
                ];
            }
            $this->assertStringContainsString('admin_user_id = ? OR admin_user_id IS NULL', $sql);
            $this->assertSame(['c1', 'c2', 'c3', 7], $binds);
            return [
                ['conversation_id' => 'c1', 'content_json' => json_encode(['type' => 'text', 'text' => str_repeat('x', 80)])],
                ['conversation_id' => 'c1', 'content_json' => json_encode(['type' => 'text', 'text' => 'second'])],
                ['conversation_id' => 'c2', 'content_json' => json_encode([['type' => 'image'], ['type' => 'text', 'text' => 'Look']])],
                ['conversation_id' => 'c3', 'content_json' => 'garbage'],
            ];
        });

        $out = $this->block($connection)->getRecentConversations(5);

        $this->assertSame(['c1', 'c2', 'c3'], array_column($out, 'conversation_id'));
        $this->assertSame(str_repeat('x', 57) . '...', $out[0]['preview']);
        $this->assertSame(4, $out[0]['turns']);
        $this->assertSame('Look', $out[1]['preview']);
        $this->assertSame('', $out[2]['preview']);
    }

    public function testRecentConversationsEmptyCases(): void
    {
        $missing = $this->createStub(AdapterInterface::class);
        $missing->method('isTableExists')->willReturn(false);
        $this->assertSame([], $this->block($missing)->getRecentConversations());

        $empty = $this->createMock(AdapterInterface::class);
        $empty->method('isTableExists')->willReturn(true);
        $empty->expects($this->once())->method('fetchAll')->willReturn([]);
        $this->assertSame([], $this->block($empty)->getRecentConversations());
    }

    public function testToolSummariesUseDefinitions(): void
    {
        $tool = $this->createStub(ToolInterface::class);
        $tool->method('name')->willReturn('orders');
        $tool->method('definition')->willReturn(['name' => 'orders', 'description' => 'Look up orders']);
        $bare = $this->createStub(ToolInterface::class);
        $bare->method('name')->willReturn('bare');
        $bare->method('definition')->willReturn([]);

        $this->assertSame(
            [['name' => 'bare', 'description' => ''], ['name' => 'orders', 'description' => 'Look up orders']],
            $this->block(null, [$tool, $bare])->getToolSummaries()
        );
    }

    public function testSettingsAndUrls(): void
    {
        $block = $this->block();

        $this->assertTrue($block->isConfigured());
        $this->assertFalse($this->block(null, [], true, '')->isConfigured());
        $this->assertTrue($this->block(null, [], false, 'k')->hasApiKey());
        $this->assertFalse($this->block(null, [], false, 'k')->isConfigured());
        $this->assertFalse($this->block(null, [], true, '')->hasApiKey());
        $this->assertSame('claude-haiku-4-5', $block->getModel());
        $this->assertTrue($block->isDryRun());
        $this->assertSame('FK', $block->getFormKey());
        $this->assertSame('https://admin.test/claudeai/chat/send', $block->getSendUrl());
        $this->assertSame('https://admin.test/claudeai/chat/stream', $block->getStreamUrl());
        $this->assertSame('https://admin.test/claudeai/chat/upload', $block->getUploadUrl());
        $this->assertSame('https://admin.test/claudeai/chat/load', $block->getLoadUrl());
        $this->assertSame('https://admin.test/adminhtml/system_config/edit?section=panth_claudeai', $block->getConfigUrl());
        $this->assertCount(5, $block->getSuggestions());
    }
}
