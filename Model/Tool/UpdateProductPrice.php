<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;

/**
 * Bulk price-update tool. Always snapshots before write so the merchant can
 * undo via `restore_checkpoint`. Hard-capped per call. Honors dry-run.
 */
class UpdateProductPrice implements ToolInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly Config $config,
        private readonly CheckpointService $checkpoints
    ) {
    }

    public function name(): string
    {
        return 'update_product_price';
    }

    public function definition(): array
    {
        return [
            'name' => 'update_product_price',
            'description' => 'Update the price of one or more products. Either provide `sku_list` OR `sku_pattern` (% as wildcard) OR `name_contains`. Optionally `percent_change` (e.g. -10 for 10% off) instead of `new_price`. Always creates a checkpoint first so the merchant can undo via restore_checkpoint. Hard-capped per call.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sku_list' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Explicit list of SKUs.',
                    ],
                    'sku_pattern' => [
                        'type' => 'string',
                        'description' => 'SQL LIKE pattern with % as wildcard.',
                    ],
                    'name_contains' => [
                        'type' => 'string',
                        'description' => 'Match products whose name contains this substring.',
                    ],
                    'new_price' => [
                        'type' => 'number',
                        'description' => 'New fixed price (> 0). Use this OR percent_change.',
                    ],
                    'percent_change' => [
                        'type' => 'number',
                        'description' => 'Percentage change (e.g. -10 for 10% off, +15 for 15% increase). Applied to current price.',
                    ],
                ],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $newPriceFixed   = isset($input['new_price']) ? (float) $input['new_price'] : null;
        $percentChange   = isset($input['percent_change']) ? (float) $input['percent_change'] : null;
        if ($newPriceFixed === null && $percentChange === null) {
            return ['status' => 'error', 'message' => 'Provide either new_price or percent_change.'];
        }
        if ($newPriceFixed !== null && $newPriceFixed <= 0) {
            return ['status' => 'error', 'message' => 'new_price must be > 0.'];
        }

        $skus = $this->resolveSkus($input);
        if (empty($skus)) {
            return ['status' => 'error', 'message' => 'No SKUs matched. Provide sku_list, sku_pattern, or name_contains.'];
        }

        $cap = $this->config->getMaxBulkUpdate();
        if (count($skus) > $cap) {
            return [
                'status'  => 'error',
                'message' => sprintf('Refusing to update %d products (cap is %d). Narrow the match or raise the cap.', count($skus), $cap),
            ];
        }

        $dryRun = $this->config->isDryRun();

        // Snapshot before any write so the operation is reversible.
        $checkpointId = '';
        if (!$dryRun) {
            $checkpointId = $this->checkpoints->snapshotProducts(
                'update_product_price',
                'product_price',
                $skus,
                $newPriceFixed !== null
                    ? sprintf('Set price to $%.2f for %d products', $newPriceFixed, count($skus))
                    : sprintf('Adjust price by %+.1f%% for %d products', $percentChange, count($skus))
            );
        }

        $updated = 0;
        $failed  = [];
        $changes = [];
        foreach ($skus as $sku) {
            try {
                $product = $this->productRepository->get($sku);
                $currentPrice = (float) $product->getPrice();
                $targetPrice  = $newPriceFixed !== null
                    ? $newPriceFixed
                    : round($currentPrice * (1 + ($percentChange / 100)), 2);
                if ($targetPrice <= 0) {
                    $failed[] = ['sku' => $sku, 'error' => 'computed price <= 0'];
                    continue;
                }
                if (abs($currentPrice - $targetPrice) < 0.005) {
                    continue;
                }
                if (!$dryRun) {
                    $product->setPrice($targetPrice);
                    $this->productRepository->save($product);
                }
                $changes[] = ['sku' => $sku, 'before' => $currentPrice, 'after' => $targetPrice];
                $updated++;
            } catch (\Throwable $e) {
                $failed[] = ['sku' => $sku, 'error' => $e->getMessage()];
            }
        }

        $verb = $dryRun ? 'WOULD update' : 'Updated';
        return [
            'status'         => 'success',
            'dry_run'        => $dryRun,
            'matched'        => count($skus),
            'affected_count' => $updated,
            'failed'         => $failed,
            'changes'        => array_slice($changes, 0, 20),
            'checkpoint_id'  => $checkpointId,
            'summary'        => sprintf(
                '%s %d/%d products%s%s',
                $verb,
                $updated,
                count($skus),
                $checkpointId ? '. Checkpoint ' . $checkpointId . ' (use restore_checkpoint to undo).' : '',
                $dryRun ? ' (dry run — no DB writes)' : ''
            ),
        ];
    }

    /** @return string[] */
    private function resolveSkus(array $input): array
    {
        if (!empty($input['sku_list']) && is_array($input['sku_list'])) {
            return array_values(array_unique(array_filter(array_map('strval', $input['sku_list']))));
        }

        $cap = $this->config->getMaxBulkUpdate() + 1;
        if (!empty($input['sku_pattern'])) {
            $criteria = $this->criteriaBuilder
                ->addFilter('sku', $input['sku_pattern'], 'like')
                ->setPageSize($cap)
                ->create();
        } elseif (!empty($input['name_contains'])) {
            $pat = '%' . str_replace('%', '\\%', (string) $input['name_contains']) . '%';
            $criteria = $this->criteriaBuilder
                ->addFilter('name', $pat, 'like')
                ->setPageSize($cap)
                ->create();
        } else {
            return [];
        }

        $list = $this->productRepository->getList($criteria);
        $skus = [];
        foreach ($list->getItems() as $p) {
            $skus[] = $p->getSku();
        }
        return $skus;
    }
}
