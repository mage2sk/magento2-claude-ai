<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;

/**
 * Read-only product query tool. Lets Claude inspect the catalog before
 * proposing a write — the merchant always sees what would be touched.
 */
class GetProducts implements ToolInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder
    ) {
    }

    public function name(): string
    {
        return 'get_products';
    }

    public function definition(): array
    {
        return [
            'name' => 'get_products',
            'description' => 'Search the product catalog. Use this BEFORE any price-update operation to confirm exactly which products will be matched. Returns SKU, name, price, type, and status. Always summarize the result back to the merchant before writing.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sku_pattern' => [
                        'type' => 'string',
                        'description' => 'Match SKU against an SQL LIKE pattern. Use % as wildcard. Example: "MH%" matches all SKUs starting with MH.',
                    ],
                    'name_contains' => [
                        'type' => 'string',
                        'description' => 'Match products whose name contains this substring (case-insensitive).',
                    ],
                    'min_price' => ['type' => 'number'],
                    'max_price' => ['type' => 'number'],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max products to return. Default 50, hard cap 200.',
                    ],
                ],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $limit = min(200, max(1, (int) ($input['limit'] ?? 50)));

        $filters = [];
        if (!empty($input['sku_pattern'])) {
            $filters[] = $this->filterBuilder
                ->setField('sku')
                ->setConditionType('like')
                ->setValue((string) $input['sku_pattern'])
                ->create();
        }
        if (!empty($input['name_contains'])) {
            $filters[] = $this->filterBuilder
                ->setField('name')
                ->setConditionType('like')
                ->setValue('%' . str_replace('%', '\\%', (string) $input['name_contains']) . '%')
                ->create();
        }
        if (isset($input['min_price'])) {
            $filters[] = $this->filterBuilder
                ->setField('price')
                ->setConditionType('gteq')
                ->setValue((float) $input['min_price'])
                ->create();
        }
        if (isset($input['max_price'])) {
            $filters[] = $this->filterBuilder
                ->setField('price')
                ->setConditionType('lteq')
                ->setValue((float) $input['max_price'])
                ->create();
        }

        if (!empty($filters)) {
            // each filter goes in its own AND group
            foreach ($filters as $f) {
                $group = $this->filterGroupBuilder->addFilter($f)->create();
                $this->criteriaBuilder->setFilterGroups(array_merge(
                    $this->criteriaBuilder->create()->getFilterGroups() ?: [],
                    [$group]
                ));
            }
        }

        $criteria = $this->criteriaBuilder->setPageSize($limit)->create();
        $list = $this->productRepository->getList($criteria);

        $items = [];
        foreach ($list->getItems() as $product) {
            $items[] = [
                'sku'    => $product->getSku(),
                'name'   => $product->getName(),
                'price'  => (float) $product->getPrice(),
                'type'   => $product->getTypeId(),
                'status' => (int) $product->getStatus() === 1 ? 'enabled' : 'disabled',
            ];
        }

        $total = (int) $list->getTotalCount();
        return [
            'status'         => 'success',
            'affected_count' => count($items),
            'total_match'    => $total,
            'returned'       => count($items),
            'products'       => $items,
            'summary'        => sprintf(
                '%d products match (showing %d).',
                $total,
                count($items)
            ),
        ];
    }
}
