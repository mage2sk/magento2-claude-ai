<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;

/**
 * Full-surface product writes — create, update, clone.
 *
 * Covers what merchants actually ask the AI to do:
 *   - "create a test product called X with price 19.99"
 *   - "rename SKU mh01 to ..."
 *   - "set the description on these products"
 *   - "clone product X as Y"
 *
 * Reads still happen via get_products. Price-only or status-only writes
 * still go through update_product_price / update_product_status (those
 * keep their narrow snapshot shape for restore_checkpoint compatibility).
 *
 * Honors dry-run + confirmation. No DELETE — disable products instead.
 */
class ManageProducts implements ToolInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly CheckpointService $checkpoints,
        private readonly Config $config
    ) {
    }

    public function name(): string { return 'manage_products'; }

    public function definition(): array
    {
        return [
            'name' => 'manage_products',
            'description' =>
                'Create, update, or clone catalog products. action="create" with sku, name (required) plus optional price, qty, type ("simple"|"virtual"|"downloadable"), attribute_set_id, status, visibility, description, weight, url_key, category_ids[]. action="update" with sku and any fields you want to change. action="clone" with source_sku, new_sku, optional new_name. Always creates a checkpoint; restore_checkpoint reverts. Dry-run and confirmation flow honored.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action'              => ['type' => 'string', 'enum' => ['create', 'update', 'clone']],
                    'sku'                 => ['type' => 'string'],
                    'source_sku'          => ['type' => 'string', 'description' => 'For clone — SKU to copy from.'],
                    'new_sku'             => ['type' => 'string', 'description' => 'For clone.'],
                    'new_name'            => ['type' => 'string', 'description' => 'For clone — leave blank to keep source name.'],
                    'name'                => ['type' => 'string'],
                    'price'               => ['type' => 'number'],
                    'qty'                 => ['type' => 'number', 'description' => 'On create, sets initial stock quantity.'],
                    'type'                => ['type' => 'string', 'enum' => ['simple', 'virtual', 'downloadable']],
                    'attribute_set_id'    => ['type' => 'integer', 'description' => 'Defaults to 4 (Default attribute set).'],
                    'status'              => ['type' => 'string', 'enum' => ['enabled', 'disabled']],
                    'visibility'          => ['type' => 'string', 'enum' => ['not_visible', 'catalog', 'search', 'catalog_search']],
                    'description'         => ['type' => 'string'],
                    'short_description'   => ['type' => 'string'],
                    'weight'              => ['type' => 'number'],
                    'url_key'             => ['type' => 'string'],
                    'category_ids'        => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'website_ids'         => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'tax_class_id'        => ['type' => 'integer'],
                    'meta_title'          => ['type' => 'string'],
                    'meta_description'    => ['type' => 'string'],
                    'confirm'             => ['type' => 'boolean'],
                ],
                'required' => ['action'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $action = (string) ($input['action'] ?? '');
            switch ($action) {
                case 'create': return $this->doCreate($input);
                case 'update': return $this->doUpdate($input);
                case 'clone':  return $this->doClone($input);
            }
            return ['status' => 'error', 'message' => 'Unknown action: ' . $action];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function doCreate(array $input): array
    {
        $sku  = trim((string) ($input['sku']  ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if ($sku === '' || $name === '') {
            return ['status' => 'error', 'message' => 'sku and name are required for create.'];
        }
        // Bail early if a product with that SKU already exists.
        try {
            $existing = $this->productRepository->get($sku);
            if ($existing && $existing->getId()) {
                return [
                    'status'  => 'error',
                    'message' => "A product with SKU {$sku} already exists (id={$existing->getId()}). Use action=update or pick a different SKU.",
                ];
            }
        } catch (NoSuchEntityException) {
            // Good — SKU is free.
        }

        if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
            return [
                'status'  => 'needs_confirmation',
                'message' => "About to create product '{$name}' (SKU {$sku}). Re-call with confirm=true to apply.",
            ];
        }

        if ($this->config->isDryRun()) {
            return [
                'status'  => 'dry_run',
                'message' => "[DRY RUN] Would create '{$name}' (SKU {$sku}).",
            ];
        }

        /** @var ProductInterface $product */
        $product = $this->productFactory->create();
        $product->setSku($sku);
        $product->setName($name);
        $product->setTypeId((string) ($input['type'] ?? ProductType::TYPE_SIMPLE));
        $product->setAttributeSetId((int) ($input['attribute_set_id'] ?? 4));
        $product->setPrice((float) ($input['price'] ?? 0));
        $product->setStatus($this->statusValue($input['status'] ?? 'enabled'));
        $product->setVisibility($this->visibilityValue($input['visibility'] ?? 'catalog_search'));

        if (isset($input['description']))       { $product->setCustomAttribute('description', (string) $input['description']); }
        if (isset($input['short_description'])) { $product->setCustomAttribute('short_description', (string) $input['short_description']); }
        if (isset($input['weight']))            { $product->setWeight((float) $input['weight']); }
        if (isset($input['url_key']))           { $product->setUrlKey((string) $input['url_key']); }
        if (isset($input['tax_class_id']))      { $product->setCustomAttribute('tax_class_id', (int) $input['tax_class_id']); }
        if (isset($input['meta_title']))        { $product->setCustomAttribute('meta_title', (string) $input['meta_title']); }
        if (isset($input['meta_description']))  { $product->setCustomAttribute('meta_description', (string) $input['meta_description']); }

        $websiteIds = $input['website_ids'] ?? [];
        if (empty($websiteIds)) {
            $websiteIds = [(int) $this->storeManager->getWebsite()->getId()];
        }
        $product->setWebsiteIds(array_map('intval', $websiteIds));

        if (!empty($input['category_ids']) && is_array($input['category_ids'])) {
            $product->setCategoryIds(array_map('intval', $input['category_ids']));
        }

        // Stock
        $qty = (float) ($input['qty'] ?? 0);
        $product->setStockData([
            'use_config_manage_stock' => 1,
            'manage_stock'            => 1,
            'qty'                     => $qty,
            'is_in_stock'             => $qty > 0 ? 1 : 0,
            'is_qty_decimal'          => 0,
        ]);

        $saved = $this->productRepository->save($product);

        // Newly-created products don't need a "before" snapshot, but stamp a
        // checkpoint so restore_checkpoint can disable the new product as a
        // soft undo (Magento has no hard-delete from this tool by design).
        $checkpointId = $this->checkpoints->snapshotProducts(
            'manage_products:create',
            'product_status',
            [$saved->getSku()],
            sprintf('Created product %s (id=%d)', $saved->getSku(), (int) $saved->getId()),
            ''
        );

        return [
            'status'         => 'success',
            'affected_count' => 1,
            'checkpoint_id'  => $checkpointId,
            'product'        => $this->shape($saved),
            'summary'        => sprintf(
                'Created %s (SKU %s, id=%d, price %.2f, qty %d). Undo: restore_checkpoint with %s (will disable the new product).',
                $saved->getName(), $saved->getSku(), (int) $saved->getId(),
                (float) $saved->getPrice(), (int) $qty, $checkpointId
            ),
        ];
    }

    private function doUpdate(array $input): array
    {
        $sku = trim((string) ($input['sku'] ?? ''));
        if ($sku === '') {
            return ['status' => 'error', 'message' => 'sku is required for update.'];
        }
        $product = $this->productRepository->get($sku);

        if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
            $changes = $this->summariseChanges($input);
            return [
                'status'  => 'needs_confirmation',
                'message' => "About to update SKU {$sku}: {$changes}. Re-call with confirm=true.",
            ];
        }

        // Snapshot the fields about to change. The current snapshotProducts
        // entity_type='product_status' captures status; for richer field
        // updates we also stamp the price snapshot so price restore works.
        $checkpointId = $this->checkpoints->snapshotProducts(
            'manage_products:update',
            'product_price',
            [$sku],
            sprintf('Update %s — %s', $sku, $this->summariseChanges($input)),
            ''
        );

        if ($this->config->isDryRun()) {
            return [
                'status'        => 'dry_run',
                'checkpoint_id' => $checkpointId,
                'message'       => "[DRY RUN] Would update {$sku}: " . $this->summariseChanges($input),
            ];
        }

        if (isset($input['name']))              { $product->setName((string) $input['name']); }
        if (isset($input['price']))             { $product->setPrice((float) $input['price']); }
        if (isset($input['weight']))            { $product->setWeight((float) $input['weight']); }
        if (isset($input['url_key']))           { $product->setUrlKey((string) $input['url_key']); }
        if (isset($input['status']))            { $product->setStatus($this->statusValue((string) $input['status'])); }
        if (isset($input['visibility']))        { $product->setVisibility($this->visibilityValue((string) $input['visibility'])); }
        if (isset($input['description']))       { $product->setCustomAttribute('description', (string) $input['description']); }
        if (isset($input['short_description'])) { $product->setCustomAttribute('short_description', (string) $input['short_description']); }
        if (isset($input['meta_title']))        { $product->setCustomAttribute('meta_title', (string) $input['meta_title']); }
        if (isset($input['meta_description']))  { $product->setCustomAttribute('meta_description', (string) $input['meta_description']); }
        if (!empty($input['category_ids']) && is_array($input['category_ids'])) {
            $product->setCategoryIds(array_map('intval', $input['category_ids']));
        }
        if (!empty($input['website_ids']) && is_array($input['website_ids'])) {
            $product->setWebsiteIds(array_map('intval', $input['website_ids']));
        }

        $saved = $this->productRepository->save($product);

        if (isset($input['qty'])) {
            $stock = $this->stockRegistry->getStockItemBySku($sku);
            $stock->setQty((float) $input['qty']);
            $stock->setIsInStock(((float) $input['qty']) > 0 ? 1 : 0);
            $this->stockRegistry->updateStockItemBySku($sku, $stock);
        }

        return [
            'status'         => 'success',
            'affected_count' => 1,
            'checkpoint_id'  => $checkpointId,
            'product'        => $this->shape($saved),
            'summary'        => sprintf('Updated %s. Undo: restore_checkpoint with %s.', $sku, $checkpointId),
        ];
    }

    private function doClone(array $input): array
    {
        $source = trim((string) ($input['source_sku'] ?? ''));
        $newSku = trim((string) ($input['new_sku'] ?? ''));
        if ($source === '' || $newSku === '') {
            return ['status' => 'error', 'message' => 'source_sku and new_sku are required for clone.'];
        }
        try {
            $exists = $this->productRepository->get($newSku);
            if ($exists && $exists->getId()) {
                return ['status' => 'error', 'message' => "Target SKU {$newSku} already exists."];
            }
        } catch (NoSuchEntityException) {
            // Good
        }
        $orig = $this->productRepository->get($source);

        if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
            return [
                'status'  => 'needs_confirmation',
                'message' => "About to clone {$source} → {$newSku}. Re-call with confirm=true.",
            ];
        }
        if ($this->config->isDryRun()) {
            return [
                'status'  => 'dry_run',
                'message' => "[DRY RUN] Would clone {$source} → {$newSku}.",
            ];
        }

        /** @var ProductInterface $copy */
        $copy = $this->productFactory->create();
        $copy->setSku($newSku);
        $copy->setName((string) ($input['new_name'] ?? ($orig->getName() . ' (copy)')));
        $copy->setTypeId($orig->getTypeId());
        $copy->setAttributeSetId((int) $orig->getAttributeSetId());
        $copy->setPrice((float) $orig->getPrice());
        $copy->setStatus((int) $orig->getStatus());
        $copy->setVisibility((int) $orig->getVisibility());
        $copy->setWeight((float) $orig->getWeight());
        $copy->setWebsiteIds($orig->getWebsiteIds());
        if ($orig->getCategoryIds()) {
            $copy->setCategoryIds($orig->getCategoryIds());
        }
        $copy->setCustomAttribute('description', (string) $orig->getCustomAttribute('description')?->getValue());
        $copy->setCustomAttribute('short_description', (string) $orig->getCustomAttribute('short_description')?->getValue());
        $copy->setStockData([
            'use_config_manage_stock' => 1,
            'manage_stock'            => 1,
            'qty'                     => 0,
            'is_in_stock'             => 0,
        ]);
        $saved = $this->productRepository->save($copy);

        $checkpointId = $this->checkpoints->snapshotProducts(
            'manage_products:clone',
            'product_status',
            [$saved->getSku()],
            sprintf('Cloned %s → %s', $source, $newSku),
            ''
        );

        return [
            'status'         => 'success',
            'affected_count' => 1,
            'checkpoint_id'  => $checkpointId,
            'product'        => $this->shape($saved),
            'summary'        => sprintf('Cloned %s → %s (id=%d). Undo: restore_checkpoint with %s.', $source, $newSku, (int) $saved->getId(), $checkpointId),
        ];
    }

    private function statusValue(string $name): int
    {
        return strtolower($name) === 'disabled' ? ProductStatus::STATUS_DISABLED : ProductStatus::STATUS_ENABLED;
    }

    private function visibilityValue(string $name): int
    {
        return match (strtolower($name)) {
            'not_visible'    => Visibility::VISIBILITY_NOT_VISIBLE,
            'catalog'        => Visibility::VISIBILITY_IN_CATALOG,
            'search'         => Visibility::VISIBILITY_IN_SEARCH,
            default          => Visibility::VISIBILITY_BOTH,
        };
    }

    private function summariseChanges(array $input): string
    {
        $skip = ['action', 'sku', 'confirm'];
        $bits = [];
        foreach ($input as $k => $v) {
            if (in_array($k, $skip, true)) { continue; }
            if (is_scalar($v)) {
                $bits[] = $k . '=' . (is_string($v) ? mb_strimwidth($v, 0, 30, '…') : $v);
            } elseif (is_array($v)) {
                $bits[] = $k . '=[' . count($v) . ']';
            }
        }
        return $bits ? implode(', ', $bits) : 'no fields specified';
    }

    private function shape(ProductInterface $p): array
    {
        return [
            'product_id'       => (int) $p->getId(),
            'sku'              => (string) $p->getSku(),
            'name'             => (string) $p->getName(),
            'type'             => (string) $p->getTypeId(),
            'price'            => (float) $p->getPrice(),
            'status'           => (int) $p->getStatus(),
            'visibility'       => (int) $p->getVisibility(),
            'attribute_set_id' => (int) $p->getAttributeSetId(),
            'url_key'          => (string) $p->getUrlKey(),
            'website_ids'      => $p->getWebsiteIds(),
            'category_ids'     => $p->getCategoryIds(),
        ];
    }
}
