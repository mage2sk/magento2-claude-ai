<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Api\Data\BlockSearchResultsInterface;
use Magento\Cms\Model\Block;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\ManageCmsBlocks;
use PHPUnit\Framework\TestCase;

class ManageCmsBlocksTest extends TestCase
{
    private array $calls = [];
    private array $filters = [];

    private BlockRepositoryInterface $repo;
    private BlockInterfaceFactory $factory;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(BlockRepositoryInterface::class);
        $this->factory = $this->createStub(BlockInterfaceFactory::class);
    }

    private function block(string $label, int $id, string $identifier): Block
    {
        $b = $this->createStub(Block::class);
        $b->method('getId')->willReturn($id);
        $b->method('getIdentifier')->willReturn($identifier);
        $b->method('getTitle')->willReturn('Block ' . $identifier);
        $b->method('isActive')->willReturn(false);
        $b->method('getUpdateTime')->willReturn('2026-10-02');
        $b->method('getContent')->willReturn('<div/>');
        foreach (['setIdentifier', 'setTitle', 'setContent', 'setIsActive'] as $setter) {
            $b->method($setter)->willReturnCallback(function ($v) use ($label, $setter, $b) {
                $this->calls[$label][$setter][] = $v;
                return $b;
            });
        }
        $b->method('__call')->willReturnCallback(function (string $m, array $args) use ($label, $b) {
            $this->calls[$label][$m][] = $args[0] ?? null;
            return $b;
        });
        return $b;
    }

    private function results(array $items, int $total = 0): BlockSearchResultsInterface
    {
        $r = $this->createStub(BlockSearchResultsInterface::class);
        $r->method('getItems')->willReturn($items);
        $r->method('getTotalCount')->willReturn($total ?: count($items));
        return $r;
    }

    private function tool(bool $confirmRequired = false, bool $dryRun = false): ManageCmsBlocks
    {
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($f, $v, $t = 'eq') use (&$builder) {
            $this->filters[] = [$f, $v, $t];
            return $builder;
        });
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createStub(SearchCriteria::class));

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createStub(Config::class);
        $config->method('isConfirmationRequired')->willReturn($confirmRequired);
        $config->method('isDryRun')->willReturn($dryRun);

        return new ManageCmsBlocks($this->repo, $this->factory, $builder, $storeManager, $config);
    }

    public function testDefinitionAndUnknownAction(): void
    {
        $tool = $this->tool();
        $this->assertSame('manage_cms_blocks', $tool->name());
        $this->assertSame(['action'], $tool->definition()['input_schema']['required']);
        $this->assertSame(['status' => 'error', 'message' => 'Unknown action: '], $tool->execute([]));
    }

    public function testList(): void
    {
        $this->repo->method('getList')->willReturn($this->results([$this->block('a', 1, 'footer')], 3));

        $result = $this->tool()->execute(['action' => 'list', 'identifier_contains' => 'foot', 'is_active' => true]);

        $this->assertSame([['identifier', '%foot%', 'like'], ['is_active', 1, 'eq']], $this->filters);
        $this->assertSame([
            'block_id' => 1,
            'identifier' => 'footer',
            'title' => 'Block footer',
            'is_active' => false,
            'updated_at' => '2026-10-02',
        ], $result['blocks'][0]);
        $this->assertSame('3 blocks match.', $result['summary']);
    }

    public function testGet(): void
    {
        $this->repo->method('getById')->willReturnCallback(function ($id) {
            if ($id !== 2) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $this->block('b', 2, 'promo');
        });
        $this->repo->method('getList')->willReturn($this->results([]));

        $this->assertSame('<div/>', $this->tool()->execute(['action' => 'get', 'block_id' => 2])['block']['content']);
        $this->assertSame(['status' => 'error', 'message' => 'Block not found.'], $this->tool()->execute(['action' => 'get', 'block_id' => 3]));
        $this->assertSame(['status' => 'error', 'message' => 'Block not found.'], $this->tool()->execute(['action' => 'get', 'identifier' => 'none']));
    }

    public function testCreate(): void
    {
        $this->assertSame('identifier and title are required.', $this->tool()->execute(['action' => 'create', 'identifier' => 'x'])['message']);
        $this->assertSame("Create CMS block 'T'? Re-call with confirm=true.", $this->tool(true)->execute(['action' => 'create', 'identifier' => 'x', 'title' => 'T'])['message']);
        $this->assertSame('[DRY RUN] Would create CMS block x.', $this->tool(false, true)->execute(['action' => 'create', 'identifier' => 'x', 'title' => 'T'])['message']);

        $new = $this->block('new', 8, 'x');
        $this->factory->method('create')->willReturn($new);
        $this->repo->method('save')->willReturn($new);

        $result = $this->tool()->execute(['action' => 'create', 'identifier' => ' x ', 'title' => 'T']);

        $this->assertSame(['x'], $this->calls['new']['setIdentifier']);
        $this->assertSame([''], $this->calls['new']['setContent']);
        $this->assertSame([[1]], $this->calls['new']['setStores']);
        $this->assertSame('Created CMS block x (id=8).', $result['summary']);
    }

    public function testUpdate(): void
    {
        $block = $this->block('b', 2, 'promo');
        $this->repo->method('getList')->willReturn($this->results([$block]));
        $this->repo->method('save')->willReturn($block);

        $this->assertSame('Update block promo? Re-call with confirm=true.', $this->tool(true)->execute(['action' => 'update', 'identifier' => 'promo'])['message']);
        $this->assertSame('[DRY RUN] Would update block promo.', $this->tool(false, true)->execute(['action' => 'update', 'identifier' => 'promo'])['message']);

        $result = $this->tool()->execute(['action' => 'update', 'identifier' => 'promo', 'content' => '<b/>', 'is_active' => true, 'store_ids' => ['2']]);

        $this->assertSame(['promo'], $this->calls['b']['setIdentifier']);
        $this->assertSame(['<b/>'], $this->calls['b']['setContent']);
        $this->assertSame([true], $this->calls['b']['setIsActive']);
        $this->assertSame([[2]], $this->calls['b']['setStores']);
        $this->assertSame('Updated CMS block promo (id=2).', $result['summary']);
    }

    public function testUpdateMissingBlock(): void
    {
        $this->repo->method('getList')->willReturn($this->results([]));
        $this->assertSame(['status' => 'error', 'message' => 'Block not found.'], $this->tool()->execute(['action' => 'update', 'identifier' => 'nope']));
    }
}
