<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Indexer\Model\IndexerFactory;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;

/**
 * Operational tool — flush specific cache types and reindex specific
 * indexers without leaving the chat. Useful after the merchant edits
 * something else in admin and wants the changes live immediately.
 */
class CacheReindex implements ToolInterface
{
    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly Pool $cachePool,
        private readonly IndexerFactory $indexerFactory,
        private readonly IndexerCollectionFactory $indexerCollectionFactory
    ) {
    }

    public function name(): string { return 'cache_reindex'; }

    public function definition(): array
    {
        return [
            'name' => 'cache_reindex',
            'description' => 'Flush caches and/or run indexers. Set action="flush_cache" with cache_types (e.g. ["full_page","block_html"] or ["all"]). Or action="reindex" with indexer_codes (e.g. ["catalog_product_price"] or ["all"]). Or action="list" to see available types/codes.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action'        => ['type' => 'string', 'enum' => ['flush_cache', 'reindex', 'list']],
                    'cache_types'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'indexer_codes' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['action'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $action = (string) ($input['action'] ?? '');
        try {
            switch ($action) {
                case 'list': {
                    $caches = [];
                    foreach ($this->cacheTypeList->getTypes() as $type) {
                        $caches[] = $type->getId();
                    }
                    $indexers = [];
                    foreach ($this->indexerCollectionFactory->create()->getItems() as $i) {
                        $indexers[] = $i->getId();
                    }
                    return [
                        'status' => 'success',
                        'cache_types' => $caches,
                        'indexer_codes' => $indexers,
                        'summary' => sprintf('%d cache types, %d indexers available.', count($caches), count($indexers)),
                    ];
                }
                case 'flush_cache': {
                    $types = (array) ($input['cache_types'] ?? []);
                    if (in_array('all', $types, true) || empty($types)) {
                        $types = array_keys($this->cacheTypeList->getTypes());
                    }
                    foreach ($types as $type) {
                        $this->cacheTypeList->cleanType($type);
                    }
                    foreach ($this->cachePool as $frontend) {
                        $frontend->getBackend()->clean();
                    }
                    return [
                        'status' => 'success',
                        'affected_count' => count($types),
                        'summary' => sprintf('Flushed %d cache types: %s', count($types), implode(', ', $types)),
                    ];
                }
                case 'reindex': {
                    $codes = (array) ($input['indexer_codes'] ?? []);
                    if (in_array('all', $codes, true) || empty($codes)) {
                        $codes = [];
                        foreach ($this->indexerCollectionFactory->create()->getItems() as $i) {
                            $codes[] = $i->getId();
                        }
                    }
                    $done = 0;
                    foreach ($codes as $code) {
                        try {
                            $this->indexerFactory->create()->load($code)->reindexAll();
                            $done++;
                        } catch (\Throwable $e) {
                            // skip but continue
                        }
                    }
                    return [
                        'status' => 'success',
                        'affected_count' => $done,
                        'summary' => sprintf('Reindexed %d/%d indexers.', $done, count($codes)),
                    ];
                }
                default:
                    return ['status' => 'error', 'message' => 'Unknown action: ' . $action];
            }
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
