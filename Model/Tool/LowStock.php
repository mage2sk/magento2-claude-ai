<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Find products with stock at or below a threshold. Useful for "show me
 * products running low" questions.
 */
class LowStock implements ToolInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly SearchCriteriaBuilder $criteriaBuilder
    ) {
    }

    public function name(): string
    {
        return 'get_low_stock_products';
    }

    public function definition(): array
    {
        return [
            'name' => 'get_low_stock_products',
            'description' => 'Find products whose stock quantity is at or below a threshold. Returns SKU, name, and current quantity. Default threshold is 5 units; max scan size is 500 products.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'threshold' => [
                        'type' => 'integer',
                        'description' => 'Stock quantity threshold (≤ this is "low"). Default 5.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max low-stock products to return. Default 50.',
                    ],
                ],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $threshold = max(0, (int) ($input['threshold'] ?? 5));
        $limit     = min(200, max(1, (int) ($input['limit'] ?? 50)));

        // Scan up to 500 products by default, return first $limit that match
        $criteria = $this->criteriaBuilder
            ->addFilter('type_id', 'simple')
            ->setPageSize(500)
            ->create();
        $products = $this->productRepository->getList($criteria)->getItems();

        $low = [];
        foreach ($products as $p) {
            try {
                $stock = $this->stockRegistry->getStockItem($p->getId());
                $qty   = (float) $stock->getQty();
                if ($qty <= $threshold) {
                    $low[] = [
                        'sku'  => $p->getSku(),
                        'name' => $p->getName(),
                        'qty'  => $qty,
                    ];
                }
                if (count($low) >= $limit) {
                    break;
                }
            } catch (\Throwable $e) {
                // skip products without stock items
            }
        }

        return [
            'status'         => 'success',
            'affected_count' => count($low),
            'threshold'      => $threshold,
            'products'       => $low,
            'summary'        => sprintf('Found %d products with qty ≤ %d.', count($low), $threshold),
        ];
    }
}
