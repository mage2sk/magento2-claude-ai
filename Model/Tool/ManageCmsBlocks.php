<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\Config;

/**
 * CMS blocks — list, get, create, update. Twin of manage_cms_pages.
 * No delete — set is_active=false to hide.
 */
class ManageCmsBlocks implements ToolInterface
{
    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function name(): string { return 'manage_cms_blocks'; }

    public function definition(): array
    {
        return [
            'name' => 'manage_cms_blocks',
            'description' =>
                'CMS blocks: action="list" (filters: title_contains, identifier_contains, is_active), action="get" (block_id or identifier), action="create" (identifier, title, content, optional is_active/store_ids), action="update" (id or identifier + fields). No delete — set is_active=false to hide.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action'              => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update']],
                    'block_id'            => ['type' => 'integer'],
                    'identifier'          => ['type' => 'string'],
                    'title'               => ['type' => 'string'],
                    'title_contains'      => ['type' => 'string'],
                    'identifier_contains' => ['type' => 'string'],
                    'content'             => ['type' => 'string'],
                    'is_active'           => ['type' => 'boolean'],
                    'store_ids'           => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'limit'               => ['type' => 'integer'],
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
                case 'list':   return $this->doList($input);
                case 'get':    return $this->doGet($input);
                case 'create': return $this->doCreate($input);
                case 'update': return $this->doUpdate($input);
            }
            return ['status' => 'error', 'message' => 'Unknown action: ' . $action];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function doList(array $input): array
    {
        $cb = $this->criteriaBuilder;
        if (!empty($input['title_contains'])) {
            $cb->addFilter('title', '%' . $input['title_contains'] . '%', 'like');
        }
        if (!empty($input['identifier_contains'])) {
            $cb->addFilter('identifier', '%' . $input['identifier_contains'] . '%', 'like');
        }
        if (isset($input['is_active'])) {
            $cb->addFilter('is_active', $input['is_active'] ? 1 : 0);
        }
        $limit = min(100, max(1, (int) ($input['limit'] ?? 25)));
        $list = $this->blockRepository->getList($cb->setPageSize($limit)->create());
        $rows = [];
        foreach ($list->getItems() as $b) {
            $rows[] = $this->shape($b);
        }
        return [
            'status'         => 'success',
            'affected_count' => count($rows),
            'total'          => (int) $list->getTotalCount(),
            'blocks'         => $rows,
            'summary'        => sprintf('%d blocks match.', (int) $list->getTotalCount()),
        ];
    }

    private function doGet(array $input): array
    {
        $b = $this->lookup($input);
        if (!$b) { return ['status' => 'error', 'message' => 'Block not found.']; }
        return [
            'status' => 'success',
            'block'  => $this->shape($b) + ['content' => (string) $b->getContent()],
        ];
    }

    private function doCreate(array $input): array
    {
        $identifier = trim((string) ($input['identifier'] ?? ''));
        $title      = trim((string) ($input['title'] ?? ''));
        if ($identifier === '' || $title === '') {
            return ['status' => 'error', 'message' => 'identifier and title are required.'];
        }
        if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
            return ['status' => 'needs_confirmation', 'message' => "Create CMS block '{$title}'? Re-call with confirm=true."];
        }
        if ($this->config->isDryRun()) {
            return ['status' => 'dry_run', 'message' => "[DRY RUN] Would create CMS block {$identifier}."];
        }
        /** @var BlockInterface $b */
        $b = $this->blockFactory->create();
        $b->setIdentifier($identifier);
        $b->setTitle($title);
        $b->setContent((string) ($input['content'] ?? ''));
        $b->setIsActive((bool) ($input['is_active'] ?? true));
        $stores = !empty($input['store_ids']) ? array_map('intval', (array) $input['store_ids'])
                : [(int) $this->storeManager->getStore()->getId()];
        $b->setStores($stores);

        $saved = $this->blockRepository->save($b);
        return [
            'status'         => 'success',
            'affected_count' => 1,
            'block'          => $this->shape($saved),
            'summary'        => sprintf('Created CMS block %s (id=%d).', $saved->getIdentifier(), (int) $saved->getId()),
        ];
    }

    private function doUpdate(array $input): array
    {
        $b = $this->lookup($input);
        if (!$b) { return ['status' => 'error', 'message' => 'Block not found.']; }
        if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
            return ['status' => 'needs_confirmation', 'message' => "Update block {$b->getIdentifier()}? Re-call with confirm=true."];
        }
        if ($this->config->isDryRun()) {
            return ['status' => 'dry_run', 'message' => "[DRY RUN] Would update block {$b->getIdentifier()}."];
        }
        if (isset($input['title']))      { $b->setTitle((string) $input['title']); }
        if (isset($input['identifier'])) { $b->setIdentifier((string) $input['identifier']); }
        if (isset($input['content']))    { $b->setContent((string) $input['content']); }
        if (isset($input['is_active']))  { $b->setIsActive((bool) $input['is_active']); }
        if (!empty($input['store_ids'])) { $b->setStores(array_map('intval', (array) $input['store_ids'])); }
        $saved = $this->blockRepository->save($b);
        return [
            'status'         => 'success',
            'affected_count' => 1,
            'block'          => $this->shape($saved),
            'summary'        => sprintf('Updated CMS block %s (id=%d).', $saved->getIdentifier(), (int) $saved->getId()),
        ];
    }

    private function lookup(array $input): ?BlockInterface
    {
        try {
            if (!empty($input['block_id'])) {
                return $this->blockRepository->getById((int) $input['block_id']);
            }
            if (!empty($input['identifier'])) {
                $cb = $this->criteriaBuilder->addFilter('identifier', $input['identifier'])->setPageSize(1)->create();
                $items = $this->blockRepository->getList($cb)->getItems();
                if ($items) { return array_values($items)[0]; }
            }
        } catch (NoSuchEntityException) {
        }
        return null;
    }

    private function shape(BlockInterface $b): array
    {
        return [
            'block_id'   => (int) $b->getId(),
            'identifier' => (string) $b->getIdentifier(),
            'title'      => (string) $b->getTitle(),
            'is_active'  => (bool) $b->isActive(),
            'updated_at' => (string) $b->getUpdateTime(),
        ];
    }
}
