<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\Config;

/**
 * Categories — list (tree), get, create, update, assign products.
 *
 * No delete — set is_active=false to hide. Cleanup of unused categories
 * is a manual operation under Catalog → Categories.
 */
class ManageCategories implements ToolInterface
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryInterfaceFactory $categoryFactory,
        private readonly CategoryManagementInterface $categoryManagement,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function name(): string { return 'manage_categories'; }

    public function definition(): array
    {
        return [
            'name' => 'manage_categories',
            'description' =>
                'Manage catalog categories. action="tree" returns the full tree (rooted at the store root). action="get" returns one category. action="create" with name + parent_id (required) + optional is_active/include_in_menu/url_key/description. action="update" with category_id + fields. action="assign_product" with category_id + sku adds the product to that category. action="remove_product" detaches it (this is NOT a delete — both the product and category remain). No category-deletion exposed.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action'           => ['type' => 'string', 'enum' => ['tree', 'get', 'create', 'update', 'assign_product', 'remove_product']],
                    'category_id'      => ['type' => 'integer'],
                    'parent_id'        => ['type' => 'integer'],
                    'name'             => ['type' => 'string'],
                    'is_active'        => ['type' => 'boolean'],
                    'include_in_menu'  => ['type' => 'boolean'],
                    'url_key'          => ['type' => 'string'],
                    'description'      => ['type' => 'string'],
                    'sku'              => ['type' => 'string', 'description' => 'For assign_product / remove_product.'],
                    'depth'            => ['type' => 'integer', 'description' => 'For tree — limit depth (default 3).'],
                    'confirm'          => ['type' => 'boolean'],
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
                case 'tree':            return $this->doTree($input);
                case 'get':             return $this->doGet($input);
                case 'create':          return $this->doCreate($input);
                case 'update':          return $this->doUpdate($input);
                case 'assign_product':  return $this->doAssign($input, true);
                case 'remove_product':  return $this->doAssign($input, false);
            }
            return ['status' => 'error', 'message' => 'Unknown action: ' . $action];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function doTree(array $input): array
    {
        $rootId = (int) $this->storeManager->getStore()->getRootCategoryId();
        $depth  = max(1, min(5, (int) ($input['depth'] ?? 3)));
        $tree = $this->categoryManagement->getTree($rootId, $depth);
        return [
            'status'  => 'success',
            'tree'    => $this->serialiseTree($tree),
            'summary' => sprintf('Category tree from root id=%d, depth=%d.', $rootId, $depth),
        ];
    }

    private function doGet(array $input): array
    {
        $id = (int) ($input['category_id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 'error', 'message' => 'category_id required.'];
        }
        $cat = $this->categoryRepository->get($id);
        return [
            'status'   => 'success',
            'category' => $this->shape($cat),
            'summary'  => sprintf('Category %s (id=%d).', $cat->getName(), (int) $cat->getId()),
        ];
    }

    private function doCreate(array $input): array
    {
        $name     = trim((string) ($input['name'] ?? ''));
        $parentId = (int) ($input['parent_id'] ?? 0);
        if ($name === '' || $parentId <= 0) {
            return ['status' => 'error', 'message' => 'name and parent_id are required.'];
        }
        if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
            return ['status' => 'needs_confirmation', 'message' => "Create category '{$name}' under parent {$parentId}? Re-call with confirm=true."];
        }
        if ($this->config->isDryRun()) {
            return ['status' => 'dry_run', 'message' => "[DRY RUN] Would create category {$name} under {$parentId}."];
        }

        /** @var CategoryInterface $cat */
        $cat = $this->categoryFactory->create();
        $parent = $this->categoryRepository->get($parentId);
        $cat->setName($name);
        $cat->setParentId($parentId);
        $cat->setPath($parent->getPath());
        $cat->setIsActive((bool) ($input['is_active'] ?? true));
        $cat->setIncludeInMenu((bool) ($input['include_in_menu'] ?? true));
        if (isset($input['url_key']))     { $cat->setUrlKey((string) $input['url_key']); }
        if (isset($input['description'])) { $cat->setData('description', (string) $input['description']); }

        $saved = $this->categoryRepository->save($cat);
        return [
            'status'         => 'success',
            'affected_count' => 1,
            'category'       => $this->shape($saved),
            'summary'        => sprintf('Created category %s (id=%d) under parent %d.', $saved->getName(), (int) $saved->getId(), $parentId),
        ];
    }

    private function doUpdate(array $input): array
    {
        $id = (int) ($input['category_id'] ?? 0);
        if ($id <= 0) {
            return ['status' => 'error', 'message' => 'category_id required.'];
        }
        $cat = $this->categoryRepository->get($id);
        if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
            return ['status' => 'needs_confirmation', 'message' => "Update category {$cat->getName()} (id={$id})? Re-call with confirm=true."];
        }
        if ($this->config->isDryRun()) {
            return ['status' => 'dry_run', 'message' => "[DRY RUN] Would update category {$id}."];
        }
        if (isset($input['name']))            { $cat->setName((string) $input['name']); }
        if (isset($input['is_active']))       { $cat->setIsActive((bool) $input['is_active']); }
        if (isset($input['include_in_menu'])) { $cat->setIncludeInMenu((bool) $input['include_in_menu']); }
        if (isset($input['url_key']))         { $cat->setUrlKey((string) $input['url_key']); }
        if (isset($input['description']))     { $cat->setData('description', (string) $input['description']); }
        $saved = $this->categoryRepository->save($cat);
        return [
            'status'         => 'success',
            'affected_count' => 1,
            'category'       => $this->shape($saved),
            'summary'        => sprintf('Updated category %s (id=%d).', $saved->getName(), (int) $saved->getId()),
        ];
    }

    private function doAssign(array $input, bool $assign): array
    {
        $catId = (int) ($input['category_id'] ?? 0);
        $sku   = trim((string) ($input['sku'] ?? ''));
        if ($catId <= 0 || $sku === '') {
            return ['status' => 'error', 'message' => 'category_id and sku required.'];
        }
        $product = $this->productRepository->get($sku);
        $cats = array_map('intval', $product->getCategoryIds() ?: []);
        if ($assign) {
            if (!in_array($catId, $cats, true)) { $cats[] = $catId; }
        } else {
            $cats = array_values(array_diff($cats, [$catId]));
        }
        $product->setCategoryIds($cats);
        $this->productRepository->save($product);
        return [
            'status'         => 'success',
            'affected_count' => 1,
            'summary'        => sprintf('%s product %s %s category %d.', $assign ? 'Added' : 'Removed', $sku, $assign ? 'to' : 'from', $catId),
        ];
    }

    private function shape(CategoryInterface $c): array
    {
        return [
            'category_id'     => (int) $c->getId(),
            'name'            => (string) $c->getName(),
            'parent_id'       => (int) $c->getParentId(),
            'level'           => (int) $c->getLevel(),
            'path'            => (string) $c->getPath(),
            'is_active'       => (bool) $c->getIsActive(),
            'include_in_menu' => (bool) $c->getIncludeInMenu(),
            'url_key'         => (string) $c->getUrlKey(),
            'product_count'   => (int) $c->getProductCount(),
        ];
    }

    private function serialiseTree($node): array
    {
        if (!$node) { return []; }
        $out = [
            'id'              => (int) $node->getId(),
            'name'            => (string) $node->getName(),
            'level'           => (int) $node->getLevel(),
            'is_active'       => (bool) $node->getIsActive(),
            'include_in_menu' => (bool) $node->getIncludeInMenu(),
            'product_count'   => (int) $node->getProductCount(),
            'children'        => [],
        ];
        foreach ($node->getChildrenData() ?? [] as $child) {
            $out['children'][] = $this->serialiseTree($child);
        }
        return $out;
    }
}
