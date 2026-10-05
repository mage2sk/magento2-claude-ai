<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Block\Adminhtml\Conversation;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\ClaudeAi\Block\Adminhtml\Conversation\Listing;
use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Test\Unit\Block\Adminhtml\BlockContextTrait;
use PHPUnit\Framework\TestCase;

class ListingTest extends TestCase
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

    private function block(AdapterInterface $connection): Listing
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturnCallback(fn($route, $params) => $route . '?cid=' . $params['cid']);

        return new Listing($this->blockContext(), $resource, new Pricing(), $url);
    }

    public function testConversationsGetPreviewsAndAttachmentCounts(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql, array $binds = []) {
            if (str_contains($sql, 'SUM(IFNULL(cost_usd,0))')) {
                return [
                    ['conversation_id' => 'c1', 'turns' => 3],
                    ['conversation_id' => 'c2', 'turns' => 1],
                ];
            }
            if (str_contains($sql, 'panth_claudeai_attachment')) {
                $this->assertSame(['c1', 'c2'], $binds);
                return [['conversation_id' => 'c2', 'c' => '2']];
            }
            return [
                ['conversation_id' => 'c1', 'content_json' => json_encode([['type' => 'text', 'text' => '']])],
                ['conversation_id' => 'c2', 'content_json' => json_encode(['type' => 'text', 'text' => str_repeat('y', 200)])],
                ['conversation_id' => 'c2', 'content_json' => json_encode(['type' => 'text', 'text' => 'later'])],
            ];
        });

        $rows = $this->block($connection)->getConversations();

        $this->assertSame('', $rows[0]['preview']);
        $this->assertSame(0, $rows[0]['attachments']);
        $this->assertSame(str_repeat('y', 157) . '...', $rows[1]['preview']);
        $this->assertSame(2, $rows[1]['attachments']);
    }

    public function testAttachmentTableIsOptional(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(fn($t) => $t === 'panth_claudeai_message');
        $connection->method('fetchAll')->willReturnCallback(fn(string $sql) => str_contains($sql, 'GROUP BY conversation_id')
            ? [['conversation_id' => 'c1']]
            : [['conversation_id' => 'c1', 'content_json' => 'oops']]);

        $rows = $this->block($connection)->getConversations();
        $this->assertSame([['conversation_id' => 'c1', 'preview' => '', 'attachments' => 0]], $rows);
    }

    public function testEmptyStates(): void
    {
        $missing = $this->createStub(AdapterInterface::class);
        $missing->method('isTableExists')->willReturn(false);
        $this->assertSame([], $this->block($missing)->getConversations());

        $empty = $this->createStub(AdapterInterface::class);
        $empty->method('isTableExists')->willReturn(true);
        $empty->method('fetchAll')->willReturn([]);
        $this->assertSame([], $this->block($empty)->getConversations());
    }

    public function testUrlAndCostHelpers(): void
    {
        $block = $this->block($this->createStub(AdapterInterface::class));
        $this->assertSame('claudeai/conversation/view?cid=abc', $block->getViewUrl('abc'));
        $this->assertSame('$2.50', $block->formatCost(2.5));
    }
}
