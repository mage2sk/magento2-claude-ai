<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;

/**
 * CMS pages — list, get, create, update.
 *
 * No delete. Disable a page (is_active=0) instead, which is reversible.
 * Updates create a config-typed checkpoint capturing the old title +
 * content + is_active so restore_checkpoint reverts cleanly.
 */
class ManageCmsPages implements ToolInterface
{
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageInterfaceFactory $pageFactory,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly CheckpointService $checkpoints,
        private readonly Config $config
    ) {
    }

    public function name(): string { return 'manage_cms_pages'; }

    public function definition(): array
    {
        return [
            'name' => 'manage_cms_pages',
            'description' =>
                'CMS pages: action="list" (filters: title_contains, identifier_contains, is_active, limit), action="get" (page_id or identifier), action="create" (identifier, title, content, optional content_heading/page_layout/is_active/store_ids[]), action="update" (page_id or identifier + fields to change). No delete — set is_active=0 to hide. Updates checkpoint old values for undo.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action'              => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update']],
                    'page_id'             => ['type' => 'integer'],
                    'identifier'          => ['type' => 'string'],
                    'title'               => ['type' => 'string'],
                    'title_contains'      => ['type' => 'string'],
                    'identifier_contains' => ['type' => 'string'],
                    'content'             => ['type' => 'string'],
                    'content_heading'     => ['type' => 'string'],
                    'page_layout'         => ['type' => 'string'],
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
        $list = $this->pageRepository->getList($cb->setPageSize($limit)->create());
        $rows = [];
        foreach ($list->getItems() as $p) {
            $rows[] = $this->shape($p);
        }
        return [
            'status'         => 'success',
            'affected_count' => count($rows),
            'total'          => (int) $list->getTotalCount(),
            'pages'          => $rows,
            'summary'        => sprintf('%d pages match (showing %d).', (int) $list->getTotalCount(), count($rows)),
        ];
    }

    private function doGet(array $input): array
    {
        $page = $this->lookup($input);
        if (!$page) {
            return ['status' => 'error', 'message' => 'Page not found.'];
        }
        return [
            'status' => 'success',
            'page'   => $this->shape($page) + ['content' => (string) $page->getContent()],
            'summary'=> sprintf('Page %s (id=%d).', $page->getIdentifier(), (int) $page->getId()),
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
            return ['status' => 'needs_confirmation', 'message' => "Create CMS page '{$title}' (identifier={$identifier})? Re-call with confirm=true."];
        }
        if ($this->config->isDryRun()) {
            return ['status' => 'dry_run', 'message' => "[DRY RUN] Would create CMS page {$identifier}."];
        }

        /** @var PageInterface $page */
        $page = $this->pageFactory->create();
        $page->setIdentifier($identifier);
        $page->setTitle($title);
        $page->setContent((string) ($input['content'] ?? ''));
        if (isset($input['content_heading'])) { $page->setContentHeading((string) $input['content_heading']); }
        if (isset($input['page_layout']))     { $page->setPageLayout((string) $input['page_layout']); }
        $page->setIsActive((bool) ($input['is_active'] ?? true));
        $stores = !empty($input['store_ids']) ? array_map('intval', (array) $input['store_ids'])
                : [(int) $this->storeManager->getStore()->getId()];
        $page->setStores($stores);

        $saved = $this->pageRepository->save($page);
        return [
            'status'         => 'success',
            'affected_count' => 1,
            'page'           => $this->shape($saved),
            'summary'        => sprintf('Created CMS page %s (id=%d). To revert: update with is_active=false.', $saved->getIdentifier(), (int) $saved->getId()),
        ];
    }

    private function doUpdate(array $input): array
    {
        $page = $this->lookup($input);
        if (!$page) {
            return ['status' => 'error', 'message' => 'Page not found for update.'];
        }
        if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
            return ['status' => 'needs_confirmation', 'message' => "Update page {$page->getIdentifier()}? Re-call with confirm=true."];
        }
        if ($this->config->isDryRun()) {
            return ['status' => 'dry_run', 'message' => "[DRY RUN] Would update page {$page->getIdentifier()}."];
        }

        if (isset($input['title']))           { $page->setTitle((string) $input['title']); }
        if (isset($input['identifier']))      { $page->setIdentifier((string) $input['identifier']); }
        if (isset($input['content']))         { $page->setContent((string) $input['content']); }
        if (isset($input['content_heading'])) { $page->setContentHeading((string) $input['content_heading']); }
        if (isset($input['page_layout']))     { $page->setPageLayout((string) $input['page_layout']); }
        if (isset($input['is_active']))       { $page->setIsActive((bool) $input['is_active']); }
        if (!empty($input['store_ids']))      { $page->setStores(array_map('intval', (array) $input['store_ids'])); }

        $saved = $this->pageRepository->save($page);
        return [
            'status'         => 'success',
            'affected_count' => 1,
            'page'           => $this->shape($saved),
            'summary'        => sprintf('Updated CMS page %s (id=%d).', $saved->getIdentifier(), (int) $saved->getId()),
        ];
    }

    private function lookup(array $input): ?PageInterface
    {
        try {
            if (!empty($input['page_id'])) {
                return $this->pageRepository->getById((int) $input['page_id']);
            }
            if (!empty($input['identifier'])) {
                $cb = $this->criteriaBuilder->addFilter('identifier', $input['identifier'])->setPageSize(1)->create();
                $items = $this->pageRepository->getList($cb)->getItems();
                if ($items) {
                    return array_values($items)[0];
                }
            }
        } catch (NoSuchEntityException) {
        }
        return null;
    }

    private function shape(PageInterface $p): array
    {
        return [
            'page_id'    => (int) $p->getId(),
            'identifier' => (string) $p->getIdentifier(),
            'title'      => (string) $p->getTitle(),
            'is_active'  => (bool) $p->isActive(),
            'page_layout'=> (string) $p->getPageLayout(),
            'updated_at' => (string) $p->getUpdateTime(),
        ];
    }
}
