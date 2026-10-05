<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Block\Adminhtml\Conversation;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Block\Adminhtml\Conversation\View;
use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Test\Unit\Block\Adminhtml\BlockContextTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    use BlockContextTrait;

    private ?string $lastSql = null;

    protected function setUp(): void
    {
        $this->installObjectManager();
    }

    protected function tearDown(): void
    {
        $this->restoreObjectManager();
    }

    private function block(array $params, int $total = 0, array $rows = [], bool $tableExists = true): View
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn($tableExists);
        $connection->method('fetchOne')->willReturn((string) $total);
        $connection->method('fetchAll')->willReturnCallback(function (string $sql) use ($rows) {
            $this->lastSql = $sql;
            return $rows;
        });
        $connection->method('fetchRow')->willReturn(['turns' => $total]);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturnCallback(fn($route, $p = []) => $route . ($p ? '?' . http_build_query($p) : ''));

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturnCallback(
            fn($type) => $type === UrlInterface::URL_TYPE_MEDIA ? 'https://shop.test/media/' : 'https://shop.test/'
        );
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new View($this->blockContext($params), $resource, new Pricing(), $url, $storeManager);
    }

    public function testConversationIdFromCidOrId(): void
    {
        $this->assertSame('abc', $this->block(['cid' => 'abc', 'id' => 'zzz'])->getConversationId());
        $this->assertSame('zzz', $this->block(['id' => 'zzz'])->getConversationId());
        $this->assertSame('', $this->block([])->getConversationId());
    }

    public function testNoConversationMeansEmptyResults(): void
    {
        $block = $this->block([], 50);

        $this->assertSame(0, $block->getTotalMessages());
        $this->assertSame(1, $block->getTotalPages());
        $this->assertSame([], $block->getMessages());
        $this->assertSame([], $block->getSummary());
        $this->assertSame([], $block->getAttachments());
        $this->assertSame([], $block->getPaginationLinks());
    }

    public function testMissingTableMeansEmptyResults(): void
    {
        $block = $this->block(['cid' => 'abc'], 50, [], false);

        $this->assertSame(0, $block->getTotalMessages());
        $this->assertSame([], $block->getMessages());
        $this->assertSame([], $block->getSummary());
        $this->assertSame([], $block->getAttachments());
    }

    #[DataProvider('pageProvider')]
    public function testCurrentPageDefaultsToLastAndIsClamped(?int $requested, int $total, int $expected): void
    {
        $params = ['cid' => 'abc'];
        if ($requested !== null) {
            $params['p'] = $requested;
        }
        $block = $this->block($params, $total);

        $this->assertSame($expected, $block->getCurrentPage());
        $this->assertSame(20, $block->getPageSize());
    }

    public static function pageProvider(): array
    {
        return [
            'default is last page' => [null, 45, 3],
            'requested page' => [2, 45, 2],
            'too high clamps' => [9, 45, 3],
            'negative means last' => [-1, 45, 3],
            'no messages' => [null, 0, 1],
        ];
    }

    #[DataProvider('paginationProvider')]
    public function testPaginationLinks(int $page, int $totalMessages, array $expected): void
    {
        $this->assertSame($expected, $this->block(['cid' => 'abc', 'p' => $page], $totalMessages)->getPaginationLinks());
    }

    public static function paginationProvider(): array
    {
        return [
            'single page' => [1, 15, []],
            'few pages' => [2, 100, [1, 2, 3, 4, 5]],
            'start of many' => [1, 400, [1, 2, '...', 20]],
            'middle of many' => [10, 400, [1, '...', 9, 10, 11, '...', 20]],
            'end of many' => [20, 400, [1, '...', 19, 20]],
            'near start' => [3, 400, [1, 2, 3, 4, '...', 20]],
        ];
    }

    public function testMessagesArePagedAndDecoded(): void
    {
        $rows = [
            ['message_id' => 1, 'content_json' => json_encode(['type' => 'text', 'text' => 'hi'])],
            ['message_id' => 2, 'content_json' => json_encode([['type' => 'tool_use'], 'junk', ['type' => 'text']])],
            ['message_id' => 3, 'content_json' => 'plain words'],
        ];
        $messages = $this->block(['cid' => 'abc', 'p' => 2], 45, $rows)->getMessages();

        $this->assertStringContainsString('LIMIT 20 OFFSET 20', $this->lastSql);
        $this->assertSame([['type' => 'text', 'text' => 'hi']], $messages[0]['blocks']);
        $this->assertSame([['type' => 'tool_use'], ['type' => 'text']], $messages[1]['blocks']);
        $this->assertSame([['type' => 'text', 'text' => 'plain words']], $messages[2]['blocks']);
    }

    public function testAttachmentUrlsPointAtMediaBaseUrl(): void
    {
        $rows = [['attachment_id' => 1, 'stored_path' => 'panth/claudeai/a.png']];
        $attachments = $this->block(['cid' => 'abc'], 1, $rows)->getAttachments();

        $this->assertSame('https://shop.test/media/panth/claudeai/a.png', $attachments[0]['url']);
    }

    public function testSummaryAndUrls(): void
    {
        $block = $this->block(['cid' => 'abc'], 7);

        $this->assertSame(['turns' => 7], $block->getSummary());
        $this->assertSame('claudeai/conversation/view?cid=abc&p=2', $block->getPageUrl(2));
        $this->assertSame('claudeai/conversation/index', $block->getBackUrl());
        $this->assertSame('$0.00', $block->formatCost(0.0));
    }

    public function testImageDataUri(): void
    {
        $block = $this->block([]);

        $this->assertSame('data:image/webp;base64,QUJD', $block->imageDataUri(['source' => ['media_type' => 'image/webp', 'data' => 'QUJD']]));
        $this->assertSame('data:image/png;base64,QUJD', $block->imageDataUri(['source' => ['data' => 'QUJD']]));
        $this->assertSame('', $block->imageDataUri([]));
    }

    public function testPrettyJson(): void
    {
        $block = $this->block([]);

        $this->assertSame("{\n    \"a\": \"x/y\"\n}", $block->prettyJson('{"a":"x\/y"}'));
        $this->assertSame('not json', $block->prettyJson('not json'));
        $this->assertSame("[\n    1,\n    2\n]", $block->prettyJson([1, 2]));
        $this->assertSame('5', $block->prettyJson(5));
    }
}
