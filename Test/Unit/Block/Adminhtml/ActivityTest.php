<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Block\Adminhtml;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\ClaudeAi\Block\Adminhtml\Activity;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Pricing;
use PHPUnit\Framework\TestCase;

class ActivityTest extends TestCase
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

    private function block(AdapterInterface $connection): Activity
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $config = $this->createStub(Config::class);
        $config->method('getModel')->willReturn('claude-sonnet-4-6');

        $backendUrl = $this->createStub(BackendUrl::class);
        $backendUrl->method('getUrl')->willReturnCallback(fn($route, $params) => $route . '|' . json_encode($params));

        return new Activity($this->blockContext(), $resource, new Pricing(), $config, $backendUrl);
    }

    public function testRecentActivityQueryUsesLimit(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->expects($this->exactly(2))->method('fetchAll')
            ->with($this->logicalOr(
                $this->stringEndsWith('ORDER BY created_at DESC LIMIT 25'),
                $this->stringEndsWith('ORDER BY created_at DESC LIMIT 1')
            ))
            ->willReturn([['entity_id' => 1]]);

        $block = $this->block($connection);
        $this->assertSame([['entity_id' => 1]], $block->getRecentActivity(25));
        $this->assertSame([['entity_id' => 1]], $block->getRecentActivity(-3));
    }

    public function testMissingTableReturnsNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);
        $connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->block($connection)->getRecentActivity());
    }

    public function testRowCost(): void
    {
        $block = $this->block($this->createStub(AdapterInterface::class));

        $this->assertSame('', $block->rowCost(['input_tokens' => null, 'output_tokens' => 0]));
        $this->assertSame('$18.00', $block->rowCost(['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000]));
        $this->assertSame('$0.0030', $block->rowCost(['cache_read_tokens' => 10_000]));
    }

    public function testConversationViewUrl(): void
    {
        $this->assertSame(
            'claudeai/conversation/view|{"cid":"abc"}',
            $this->block($this->createStub(AdapterInterface::class))->getConversationViewUrl('abc')
        );
    }
}
