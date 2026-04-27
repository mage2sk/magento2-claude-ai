<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;

class UpdateProductStatus implements ToolInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly Config $config,
        private readonly CheckpointService $checkpoints
    ) {
    }

    public function name(): string { return 'update_product_status'; }

    public function definition(): array
    {
        return [
            'name' => 'update_product_status',
            'description' => 'Enable or disable products. "Enable" makes them visible/buyable, "disable" hides them. Use sku_list, sku_pattern, or name_contains to match. Always creates a checkpoint so the change can be undone.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sku_list'      => ['type' => 'array', 'items' => ['type' => 'string']],
                    'sku_pattern'   => ['type' => 'string'],
                    'name_contains' => ['type' => 'string'],
                    'status'        => ['type' => 'string', 'enum' => ['enable', 'disable'], 'description' => 'Set to "enable" or "disable".'],
                ],
                'required' => ['status'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $statusInput = (string) ($input['status'] ?? '');
        if (!in_array($statusInput, ['enable', 'disable'], true)) {
            return ['status' => 'error', 'message' => 'status must be "enable" or "disable".'];
        }
        $statusValue = $statusInput === 'enable' ? Status::STATUS_ENABLED : Status::STATUS_DISABLED;

        $skus = $this->resolveSkus($input);
        if (empty($skus)) {
            return ['status' => 'error', 'message' => 'No products matched.'];
        }

        $cap = $this->config->getMaxBulkUpdate();
        if (count($skus) > $cap) {
            return ['status' => 'error', 'message' => sprintf('Refusing to change %d products (cap %d).', count($skus), $cap)];
        }

        $dryRun = $this->config->isDryRun();
        $checkpointId = $dryRun ? '' : $this->checkpoints->snapshotProducts(
            'update_product_status', 'product_status', $skus,
            sprintf('%s %d products', ucfirst($statusInput), count($skus))
        );

        $updated = 0;
        $failed = [];
        foreach ($skus as $sku) {
            try {
                $p = $this->productRepository->get($sku);
                if ((int) $p->getStatus() === $statusValue) {
                    continue;
                }
                if (!$dryRun) {
                    $p->setStatus($statusValue);
                    $this->productRepository->save($p);
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
            'summary'        => sprintf(
                '%s %sd %d/%d products%s%s',
                $dryRun ? 'WOULD have' : '', $statusInput, $updated, count($skus),
                $checkpointId ? '. Checkpoint ' . $checkpointId . '.' : '',
                $dryRun ? ' (dry run)' : ''
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
            $cb = $this->criteriaBuilder->addFilter('sku', $input['sku_pattern'], 'like');
        } elseif (!empty($input['name_contains'])) {
            $pat = '%' . str_replace('%', '\\%', (string) $input['name_contains']) . '%';
            $cb = $this->criteriaBuilder->addFilter('name', $pat, 'like');
        } else {
            return [];
        }
        $list = $this->productRepository->getList($cb->setPageSize($cap)->create());
        return array_map(fn($p) => $p->getSku(), $list->getItems());
    }
}
