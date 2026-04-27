<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;

class UpdateInventory implements ToolInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Config $config,
        private readonly CheckpointService $checkpoints
    ) {
    }

    public function name(): string { return 'update_inventory'; }

    public function definition(): array
    {
        return [
            'name' => 'update_inventory',
            'description' => 'Set stock quantity and in-stock flag for products. Pass sku_list and either qty (set absolute) or qty_change (delta). Optional: in_stock (true/false) to also flip the in-stock flag. Snapshots before write.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sku_list'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'qty'        => ['type' => 'number', 'description' => 'Set absolute quantity.'],
                    'qty_change' => ['type' => 'number', 'description' => 'Delta to apply (e.g. -5 for "sold 5", +10 for "received 10").'],
                    'in_stock'   => ['type' => 'boolean', 'description' => 'Set the in-stock flag.'],
                ],
                'required' => ['sku_list'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $skus = array_filter(array_map('strval', (array) ($input['sku_list'] ?? [])));
        if (empty($skus)) {
            return ['status' => 'error', 'message' => 'sku_list is required.'];
        }
        if (!isset($input['qty']) && !isset($input['qty_change']) && !isset($input['in_stock'])) {
            return ['status' => 'error', 'message' => 'Provide qty, qty_change, or in_stock.'];
        }

        $cap = $this->config->getMaxBulkUpdate();
        if (count($skus) > $cap) {
            return ['status' => 'error', 'message' => sprintf('Refusing to update %d products (cap %d).', count($skus), $cap)];
        }

        $dryRun = $this->config->isDryRun();
        $checkpointId = $dryRun ? '' : $this->checkpoints->snapshotProducts(
            'update_inventory', 'stock_qty', $skus,
            sprintf('Inventory change for %d products', count($skus))
        );

        $updated = 0;
        $failed = [];
        foreach ($skus as $sku) {
            try {
                $product = $this->productRepository->get($sku);
                $stock = $this->stockRegistry->getStockItem((int) $product->getId());
                $newQty = (float) $stock->getQty();
                if (isset($input['qty'])) {
                    $newQty = max(0, (float) $input['qty']);
                } elseif (isset($input['qty_change'])) {
                    $newQty = max(0, $newQty + (float) $input['qty_change']);
                }
                $stock->setQty($newQty);
                if (isset($input['in_stock'])) {
                    $stock->setIsInStock((bool) $input['in_stock'] ? 1 : 0);
                } elseif ($newQty > 0) {
                    $stock->setIsInStock(1);
                }
                if (!$dryRun) {
                    $this->stockRegistry->updateStockItemBySku($sku, $stock);
                }
                $updated++;
            } catch (\Throwable $e) {
                $failed[] = ['sku' => $sku, 'error' => $e->getMessage()];
            }
        }

        return [
            'status'         => 'success',
            'dry_run'        => $dryRun,
            'affected_count' => $updated,
            'failed'         => $failed,
            'checkpoint_id'  => $checkpointId,
            'summary'        => sprintf('%sUpdated stock for %d/%d products%s', $dryRun ? '(dry run) ' : '', $updated, count($skus), $checkpointId ? '. Checkpoint ' . $checkpointId . '.' : ''),
        ];
    }
}
