<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject;
use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\Indexer\Collection as IndexerCollection;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use Magento\Indexer\Model\IndexerFactory;
use Panth\ClaudeAi\Model\Tool\CacheReindex;
use PHPUnit\Framework\TestCase;

class CacheReindexTest extends TestCase
{
    private array $cleaned = [];
    private array $reindexed = [];

    private function tool(array $failingIndexers = []): CacheReindex
    {
        $types = $this->createStub(TypeListInterface::class);
        $types->method('getTypes')->willReturn([
            'config' => new DataObject(['id' => 'config']),
            'full_page' => new DataObject(['id' => 'full_page']),
        ]);
        $types->method('cleanType')->willReturnCallback(function (string $type) {
            $this->cleaned[] = $type;
        });

        $collection = $this->createStub(IndexerCollection::class);
        $collection->method('getItems')->willReturn([
            new DataObject(['id' => 'catalog_product_price']),
            new DataObject(['id' => 'catalogsearch_fulltext']),
        ]);
        $collectionFactory = $this->createStub(IndexerCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $indexerFactory = $this->createStub(IndexerFactory::class);
        $indexerFactory->method('create')->willReturnCallback(function () use ($failingIndexers) {
            $indexer = $this->createStub(Indexer::class);
            $code = null;
            $indexer->method('load')->willReturnCallback(function ($id) use (&$code, $indexer) {
                $code = $id;
                return $indexer;
            });
            $indexer->method('reindexAll')->willReturnCallback(function () use (&$code, $failingIndexers) {
                if (in_array($code, $failingIndexers, true)) {
                    throw new \RuntimeException('locked');
                }
                $this->reindexed[] = $code;
            });
            return $indexer;
        });

        return new CacheReindex($types, $this->createStub(Pool::class), $indexerFactory, $collectionFactory);
    }

    public function testDefinitionAndUnknownAction(): void
    {
        $tool = $this->tool();
        $this->assertSame('cache_reindex', $tool->name());
        $this->assertSame(['flush_cache', 'reindex', 'list'], $tool->definition()['input_schema']['properties']['action']['enum']);
        $this->assertSame(['status' => 'error', 'message' => 'Unknown action: '], $tool->execute([]));
    }

    public function testList(): void
    {
        $result = $this->tool()->execute(['action' => 'list']);

        $this->assertSame(['config', 'full_page'], $result['cache_types']);
        $this->assertSame(['catalog_product_price', 'catalogsearch_fulltext'], $result['indexer_codes']);
        $this->assertSame('2 cache types, 2 indexers available.', $result['summary']);
    }

    public function testFlushSelectedTypes(): void
    {
        $result = $this->tool()->execute(['action' => 'flush_cache', 'cache_types' => ['block_html']]);

        $this->assertSame(['block_html'], $this->cleaned);
        $this->assertSame(1, $result['affected_count']);
        $this->assertSame('Flushed 1 cache types: block_html', $result['summary']);
    }

    public function testFlushAllOrEmptyCleansEveryType(): void
    {
        $this->tool()->execute(['action' => 'flush_cache', 'cache_types' => ['all']]);
        $this->tool()->execute(['action' => 'flush_cache']);

        $this->assertSame(['config', 'full_page', 'config', 'full_page'], $this->cleaned);
    }

    public function testReindexSpecificCodesCountsFailures(): void
    {
        $result = $this->tool(['bad'])->execute(['action' => 'reindex', 'indexer_codes' => ['catalog_product_price', 'bad']]);

        $this->assertSame(['catalog_product_price'], $this->reindexed);
        $this->assertSame(1, $result['affected_count']);
        $this->assertSame('Reindexed 1/2 indexers.', $result['summary']);
    }

    public function testReindexAll(): void
    {
        $result = $this->tool()->execute(['action' => 'reindex', 'indexer_codes' => ['all']]);

        $this->assertSame(['catalog_product_price', 'catalogsearch_fulltext'], $this->reindexed);
        $this->assertSame(2, $result['affected_count']);
    }

    public function testExceptionIsReturnedAsError(): void
    {
        $types = $this->createStub(TypeListInterface::class);
        $types->method('getTypes')->willThrowException(new \RuntimeException('cache config broken'));
        $tool = new CacheReindex(
            $types,
            $this->createStub(Pool::class),
            $this->createStub(IndexerFactory::class),
            $this->createStub(IndexerCollectionFactory::class)
        );

        $this->assertSame(['status' => 'error', 'message' => 'cache config broken'], $tool->execute(['action' => 'list']));
    }
}
