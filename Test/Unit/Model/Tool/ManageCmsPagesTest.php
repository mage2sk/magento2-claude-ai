<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\Data\PageSearchResultsInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\ManageCmsPages;
use PHPUnit\Framework\TestCase;

class ManageCmsPagesTest extends TestCase
{
    private array $calls = [];
    private array $filters = [];
    private ?int $pageSize = null;

    private PageRepositoryInterface $repo;
    private PageInterfaceFactory $factory;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(PageRepositoryInterface::class);
        $this->factory = $this->createStub(PageInterfaceFactory::class);
    }

    private function page(string $label, int $id, string $identifier): Page
    {
        $p = $this->createStub(Page::class);
        $p->method('getId')->willReturn($id);
        $p->method('getIdentifier')->willReturn($identifier);
        $p->method('getTitle')->willReturn('Title ' . $identifier);
        $p->method('isActive')->willReturn(true);
        $p->method('getPageLayout')->willReturn('1column');
        $p->method('getUpdateTime')->willReturn('2026-10-01');
        $p->method('getContent')->willReturn('<p>body</p>');
        foreach (['setIdentifier', 'setTitle', 'setContent', 'setContentHeading', 'setPageLayout', 'setIsActive'] as $setter) {
            $p->method($setter)->willReturnCallback(function ($v) use ($label, $setter, $p) {
                $this->calls[$label][$setter][] = $v;
                return $p;
            });
        }
        $p->method('__call')->willReturnCallback(function (string $m, array $args) use ($label, $p) {
            $this->calls[$label][$m][] = $args[0] ?? null;
            return $p;
        });
        return $p;
    }

    private function results(array $items, int $total = 0): PageSearchResultsInterface
    {
        $r = $this->createStub(PageSearchResultsInterface::class);
        $r->method('getItems')->willReturn($items);
        $r->method('getTotalCount')->willReturn($total ?: count($items));
        return $r;
    }

    private function tool(bool $confirmRequired = false, bool $dryRun = false): ManageCmsPages
    {
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($f, $v, $t = 'eq') use (&$builder) {
            $this->filters[] = [$f, $v, $t];
            return $builder;
        });
        $builder->method('setPageSize')->willReturnCallback(function ($s) use (&$builder) {
            $this->pageSize = $s;
            return $builder;
        });
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(Config::class);
        $config->method('isConfirmationRequired')->willReturn($confirmRequired);
        $config->method('isDryRun')->willReturn($dryRun);

        return new ManageCmsPages($this->repo, $this->factory, $builder, $storeManager, $this->createStub(CheckpointService::class), $config);
    }

    public function testDefinitionAndUnknownAction(): void
    {
        $tool = $this->tool();
        $this->assertSame('manage_cms_pages', $tool->name());
        $this->assertSame(['list', 'get', 'create', 'update'], $tool->definition()['input_schema']['properties']['action']['enum']);
        $this->assertSame(['status' => 'error', 'message' => 'Unknown action: delete'], $tool->execute(['action' => 'delete']));
    }

    public function testListAppliesFilters(): void
    {
        $this->repo->method('getList')->willReturn($this->results([$this->page('a', 1, 'home')], 9));

        $result = $this->tool()->execute([
            'action' => 'list',
            'title_contains' => 'Home',
            'identifier_contains' => 'ho',
            'is_active' => false,
            'limit' => 500,
        ]);

        $this->assertSame([
            ['title', '%Home%', 'like'],
            ['identifier', '%ho%', 'like'],
            ['is_active', 0, 'eq'],
        ], $this->filters);
        $this->assertSame(100, $this->pageSize);
        $this->assertSame([
            'page_id' => 1,
            'identifier' => 'home',
            'title' => 'Title home',
            'is_active' => true,
            'page_layout' => '1column',
            'updated_at' => '2026-10-01',
        ], $result['pages'][0]);
        $this->assertSame('9 pages match (showing 1).', $result['summary']);
    }

    public function testGetByIdAndByIdentifier(): void
    {
        $this->repo->method('getById')->willReturnCallback(function ($id) {
            if ($id !== 4) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $this->page('p', 4, 'about');
        });
        $this->repo->method('getList')->willReturn($this->results([$this->page('q', 5, 'faq')]));

        $byId = $this->tool()->execute(['action' => 'get', 'page_id' => 4]);
        $this->assertSame('<p>body</p>', $byId['page']['content']);
        $this->assertSame('Page about (id=4).', $byId['summary']);

        $byIdentifier = $this->tool()->execute(['action' => 'get', 'identifier' => 'faq']);
        $this->assertSame(5, $byIdentifier['page']['page_id']);
        $this->assertSame(['identifier', 'faq', 'eq'], $this->filters[0]);

        $this->assertSame(['status' => 'error', 'message' => 'Page not found.'], $this->tool()->execute(['action' => 'get', 'page_id' => 99]));
        $this->assertSame(['status' => 'error', 'message' => 'Page not found.'], $this->tool()->execute(['action' => 'get']));
    }

    public function testCreateFlow(): void
    {
        $this->assertSame('identifier and title are required.', $this->tool()->execute(['action' => 'create', 'title' => 'T'])['message']);
        $this->assertSame('needs_confirmation', $this->tool(true)->execute(['action' => 'create', 'title' => 'T', 'identifier' => 't'])['status']);
        $this->assertSame(
            '[DRY RUN] Would create CMS page t.',
            $this->tool(false, true)->execute(['action' => 'create', 'title' => 'T', 'identifier' => 't'])['message']
        );

        $new = $this->page('new', 12, 'sale');
        $this->factory->method('create')->willReturn($new);
        $this->repo->method('save')->willReturn($new);

        $result = $this->tool()->execute([
            'action' => 'create',
            'identifier' => 'sale',
            'title' => 'Sale',
            'content' => '<p>x</p>',
            'content_heading' => 'H',
        ]);

        $calls = $this->calls['new'];
        $this->assertSame(['sale'], $calls['setIdentifier']);
        $this->assertSame(['<p>x</p>'], $calls['setContent']);
        $this->assertSame(['H'], $calls['setContentHeading']);
        $this->assertArrayNotHasKey('setPageLayout', $calls);
        $this->assertSame([true], $calls['setIsActive']);
        $this->assertSame([[1]], $calls['setStores']);
        $this->assertSame('Created CMS page sale (id=12). To revert: update with is_active=false.', $result['summary']);
    }

    public function testCreateWithExplicitStores(): void
    {
        $new = $this->page('new', 12, 'sale');
        $this->factory->method('create')->willReturn($new);
        $this->repo->method('save')->willReturn($new);

        $this->tool()->execute(['action' => 'create', 'identifier' => 'sale', 'title' => 'Sale', 'store_ids' => ['0', '2'], 'is_active' => false]);

        $this->assertSame([[0, 2]], $this->calls['new']['setStores']);
        $this->assertSame([false], $this->calls['new']['setIsActive']);
    }

    public function testUpdateFlow(): void
    {
        $this->repo->method('getById')->willThrowException(new NoSuchEntityException(__('missing')));
        $this->assertSame('Page not found for update.', $this->tool()->execute(['action' => 'update', 'page_id' => 3])['message']);
    }

    public function testUpdateAppliesFields(): void
    {
        $page = $this->page('p', 4, 'about');
        $repo = $this->createMock(PageRepositoryInterface::class);
        $repo->method('getById')->willReturn($page);
        $repo->expects($this->once())->method('save')->with($page)->willReturn($page);
        $this->repo = $repo;

        $this->assertSame('Update page about? Re-call with confirm=true.', $this->tool(true)->execute(['action' => 'update', 'page_id' => 4])['message']);
        $this->assertSame('[DRY RUN] Would update page about.', $this->tool(false, true)->execute(['action' => 'update', 'page_id' => 4])['message']);

        $result = $this->tool()->execute([
            'action' => 'update',
            'page_id' => 4,
            'title' => 'About us',
            'page_layout' => '2columns-left',
            'is_active' => 0,
            'store_ids' => [3],
        ]);

        $calls = $this->calls['p'];
        $this->assertSame(['About us'], $calls['setTitle']);
        $this->assertSame(['2columns-left'], $calls['setPageLayout']);
        $this->assertSame([false], $calls['setIsActive']);
        $this->assertSame([[3]], $calls['setStores']);
        $this->assertArrayNotHasKey('setContent', $calls);
        $this->assertSame('Updated CMS page about (id=4).', $result['summary']);
    }

    public function testSaveFailureIsReturnedAsError(): void
    {
        $new = $this->page('new', 0, 'x');
        $this->factory->method('create')->willReturn($new);
        $this->repo->method('save')->willThrowException(new \RuntimeException('URL key for specified store already exists.'));

        $this->assertSame(
            ['status' => 'error', 'message' => 'URL key for specified store already exists.'],
            $this->tool()->execute(['action' => 'create', 'identifier' => 'x', 'title' => 'X'])
        );
    }
}
