<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\ManageProducts;
use PHPUnit\Framework\TestCase;

class ManageProductsTest extends TestCase
{
    private const SETTERS = [
        'setSku', 'setName', 'setTypeId', 'setAttributeSetId', 'setPrice', 'setStatus', 'setVisibility',
        'setWeight', 'setStockData', 'setCustomAttribute',
    ];

    private array $calls = [];
    private array $existing = [];
    private array $saved = [];

    private ProductRepositoryInterface $repo;
    private ProductInterfaceFactory $factory;
    private StockRegistryInterface $stockRegistry;
    private CheckpointService $checkpoints;

    protected function setUp(): void
    {
        $this->repo = $this->createStub(ProductRepositoryInterface::class);
        $this->repo->method('get')->willReturnCallback(function (string $sku) {
            if (!isset($this->existing[$sku])) {
                throw new NoSuchEntityException(__('No such product.'));
            }
            return $this->existing[$sku];
        });
        $this->repo->method('save')->willReturnCallback(function (Product $p) {
            $this->saved[] = $p;
            return $p;
        });

        $this->factory = $this->createStub(ProductInterfaceFactory::class);
        $this->factory->method('create')->willReturnCallback(fn() => $this->product('new', ['getId' => 99, 'getSku' => 'NEW1']));

        $this->stockRegistry = $this->createStub(StockRegistryInterface::class);
        $this->checkpoints = $this->createStub(CheckpointService::class);
        $this->checkpoints->method('snapshotProducts')->willReturn('cp_p');
    }

    private function product(string $label, array $getters = []): Product
    {
        $p = $this->createStub(Product::class);
        foreach (self::SETTERS as $setter) {
            $p->method($setter)->willReturnCallback(function (...$args) use ($label, $setter, $p) {
                $this->calls[$label][$setter][] = count($args) === 1 ? $args[0] : $args;
                return $p;
            });
        }
        $p->method('__call')->willReturnCallback(function (string $method, array $args) use ($label, $getters, $p) {
            if (str_starts_with($method, 'set')) {
                $this->calls[$label][$method][] = $args[0] ?? null;
                return $p;
            }
            return $getters[$method] ?? null;
        });
        foreach ($getters as $getter => $value) {
            if (method_exists(Product::class, $getter)) {
                $p->method($getter)->willReturn($value);
            }
        }
        return $p;
    }

    private function tool(bool $confirmRequired = false, bool $dryRun = false): ManageProducts
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfirmationRequired')->willReturn($confirmRequired);
        $config->method('isDryRun')->willReturn($dryRun);

        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);

        return new ManageProducts($this->repo, $this->factory, $this->stockRegistry, $storeManager, $this->checkpoints, $config);
    }

    public function testDefinitionAndUnknownAction(): void
    {
        $tool = $this->tool();
        $this->assertSame('manage_products', $tool->name());
        $this->assertSame(['create', 'update', 'clone'], $tool->definition()['input_schema']['properties']['action']['enum']);
        $this->assertSame(['status' => 'error', 'message' => 'Unknown action: delete'], $tool->execute(['action' => 'delete']));
    }

    public function testCreateValidation(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => 'sku and name are required for create.'],
            $this->tool()->execute(['action' => 'create', 'sku' => 'A'])
        );

        $this->existing['A'] = $this->product('a', ['getId' => 5]);
        $result = $this->tool()->execute(['action' => 'create', 'sku' => 'A', 'name' => 'Thing']);
        $this->assertStringContainsString('A product with SKU A already exists (id=5)', $result['message']);
    }

    public function testCreateConfirmationAndDryRun(): void
    {
        $this->assertSame(
            "About to create product 'Thing' (SKU NEW1). Re-call with confirm=true to apply.",
            $this->tool(true)->execute(['action' => 'create', 'sku' => 'NEW1', 'name' => 'Thing'])['message']
        );
        $this->assertSame(
            "[DRY RUN] Would create 'Thing' (SKU NEW1).",
            $this->tool(false, true)->execute(['action' => 'create', 'sku' => 'NEW1', 'name' => 'Thing'])['message']
        );
        $this->assertSame([], $this->saved);
    }

    public function testCreateBuildsProductWithDefaults(): void
    {
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->once())->method('snapshotProducts')
            ->with('manage_products:create', 'product_status', ['NEW1'], 'Created product NEW1 (id=99)', '')
            ->willReturn('cp_c');
        $this->checkpoints = $checkpoints;

        $result = $this->tool()->execute([
            'action' => 'create',
            'sku' => ' NEW1 ',
            'name' => 'Thing',
            'price' => '12.5',
            'qty' => 3,
            'visibility' => 'search',
            'status' => 'disabled',
            'url_key' => 'thing',
            'description' => 'Desc',
            'category_ids' => ['4', 5],
        ]);

        $calls = $this->calls['new'];
        $this->assertSame(['NEW1'], $calls['setSku']);
        $this->assertSame(['simple'], $calls['setTypeId']);
        $this->assertSame([4], $calls['setAttributeSetId']);
        $this->assertSame([12.5], $calls['setPrice']);
        $this->assertSame([2], $calls['setStatus']);
        $this->assertSame([3], $calls['setVisibility']);
        $this->assertSame(['thing'], $calls['setUrlKey']);
        $this->assertSame([['description', 'Desc']], $calls['setCustomAttribute']);
        $this->assertSame([[1]], $calls['setWebsiteIds']);
        $this->assertSame([[4, 5]], $calls['setCategoryIds']);
        $this->assertSame(3.0, $calls['setStockData'][0]['qty']);
        $this->assertSame(1, $calls['setStockData'][0]['is_in_stock']);
        $this->assertSame('success', $result['status']);
        $this->assertSame('cp_c', $result['checkpoint_id']);
        $this->assertSame(99, $result['product']['product_id']);
    }

    public function testCreateWithoutQtyIsOutOfStockAndUsesGivenWebsites(): void
    {
        $this->tool()->execute(['action' => 'create', 'sku' => 'NEW1', 'name' => 'Thing', 'website_ids' => ['2', '3']]);

        $calls = $this->calls['new'];
        $this->assertSame([[2, 3]], $calls['setWebsiteIds']);
        $this->assertSame(0, $calls['setStockData'][0]['is_in_stock']);
        $this->assertSame([4], $calls['setVisibility']);
        $this->assertSame([1], $calls['setStatus']);
    }

    public function testUpdateRequiresSkuAndConfirmation(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => 'sku is required for update.'],
            $this->tool()->execute(['action' => 'update'])
        );

        $this->existing['A'] = $this->product('a', ['getId' => 5]);
        $result = $this->tool(true)->execute([
            'action' => 'update',
            'sku' => 'A',
            'name' => str_repeat('x', 40),
            'price' => 9,
            'category_ids' => [1, 2],
            'confirm' => false,
        ]);

        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame(
            'About to update SKU A: name=' . str_repeat('x', 27) . '..., price=9, category_ids=[2]. Re-call with confirm=true.',
            $result['message']
        );
    }

    public function testUpdateDryRunCreatesNoCheckpointAndDoesNotSave(): void
    {
        $this->existing['A'] = $this->product('a', ['getId' => 5]);
        $checkpoints = $this->createMock(CheckpointService::class);
        $checkpoints->expects($this->never())->method('snapshotProducts');
        $this->checkpoints = $checkpoints;

        $result = $this->tool(false, true)->execute(['action' => 'update', 'sku' => 'A']);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame([], $this->saved);
    }

    public function testUpdateAppliesFieldsAndStock(): void
    {
        $this->existing['A'] = $this->product('a', ['getId' => 5, 'getSku' => 'A']);
        $stock = $this->createMock(StockItemInterface::class);
        $stock->expects($this->once())->method('setQty')->with(0.0);
        $stock->expects($this->once())->method('setIsInStock')->with(0);
        $registry = $this->createMock(StockRegistryInterface::class);
        $registry->method('getStockItemBySku')->with('A')->willReturn($stock);
        $registry->expects($this->once())->method('updateStockItemBySku')->with('A', $stock);
        $this->stockRegistry = $registry;

        $result = $this->tool()->execute([
            'action' => 'update',
            'sku' => 'A',
            'name' => 'Renamed',
            'price' => 20,
            'status' => 'enabled',
            'visibility' => 'not_visible',
            'meta_title' => 'MT',
            'qty' => 0,
        ]);

        $calls = $this->calls['a'];
        $this->assertSame(['Renamed'], $calls['setName']);
        $this->assertSame([20.0], $calls['setPrice']);
        $this->assertSame([1], $calls['setStatus']);
        $this->assertSame([1], $calls['setVisibility']);
        $this->assertSame([['meta_title', 'MT']], $calls['setCustomAttribute']);
        $this->assertCount(1, $this->saved);
        $this->assertSame('Updated A. Undo: restore_checkpoint with cp_p.', $result['summary']);
    }

    public function testUpdateOfMissingProductIsReturnedAsError(): void
    {
        $this->assertSame(
            ['status' => 'error', 'message' => 'No such product.'],
            $this->tool()->execute(['action' => 'update', 'sku' => 'ZZ'])
        );
    }

    public function testCloneValidation(): void
    {
        $this->assertSame(
            'source_sku and new_sku are required for clone.',
            $this->tool()->execute(['action' => 'clone', 'source_sku' => 'A'])['message']
        );

        $this->existing['A'] = $this->product('a', ['getId' => 5]);
        $this->existing['B'] = $this->product('b', ['getId' => 6]);
        $this->assertSame(
            'Target SKU B already exists.',
            $this->tool()->execute(['action' => 'clone', 'source_sku' => 'A', 'new_sku' => 'B'])['message']
        );
        $this->assertSame(
            'needs_confirmation',
            $this->tool(true)->execute(['action' => 'clone', 'source_sku' => 'A', 'new_sku' => 'C'])['status']
        );
        $this->assertSame(
            '[DRY RUN] Would clone A -> C.',
            $this->tool(false, true)->execute(['action' => 'clone', 'source_sku' => 'A', 'new_sku' => 'C'])['message']
        );
    }

    public function testCloneCopiesSourceAttributes(): void
    {
        $desc = new AttributeValue();
        $desc->setValue('Source description');
        $source = $this->product('src', [
            'getId' => 5,
            'getName' => 'Hoodie',
            'getTypeId' => 'simple',
            'getAttributeSetId' => '9',
            'getPrice' => '30',
            'getStatus' => '1',
            'getVisibility' => '4',
            'getWeight' => '1.5',
            'getWebsiteIds' => [1],
            'getCategoryIds' => ['7'],
        ]);
        $source->method('getCustomAttribute')->willReturnCallback(fn($code) => $code === 'description' ? $desc : null);
        $this->existing['A'] = $source;

        $result = $this->tool()->execute(['action' => 'clone', 'source_sku' => 'A', 'new_sku' => 'A2']);

        $calls = $this->calls['new'];
        $this->assertSame(['A2'], $calls['setSku']);
        $this->assertSame(['Hoodie (copy)'], $calls['setName']);
        $this->assertSame([9], $calls['setAttributeSetId']);
        $this->assertSame([30.0], $calls['setPrice']);
        $this->assertSame([1.5], $calls['setWeight']);
        $this->assertSame([[1]], $calls['setWebsiteIds']);
        $this->assertSame([['7']], $calls['setCategoryIds']);
        $this->assertSame([['description', 'Source description'], ['short_description', '']], $calls['setCustomAttribute']);
        $this->assertSame(0, $calls['setStockData'][0]['qty']);
        $this->assertSame('success', $result['status']);
        $this->assertStringStartsWith('Cloned A -> A2 (id=99).', $result['summary']);
    }
}
