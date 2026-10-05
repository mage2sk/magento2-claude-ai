<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Tool;

use Magento\Catalog\Api\CategoryManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Tool\ManageCategories;
use PHPUnit\Framework\TestCase;

class ManageCategoriesTest extends TestCase
{
    private array $calls = [];

    private CategoryRepositoryInterface $categoryRepo;
    private CategoryInterfaceFactory $factory;
    private CategoryManagementInterface $management;
    private ProductRepositoryInterface $productRepo;

    protected function setUp(): void
    {
        $this->categoryRepo = $this->createStub(CategoryRepositoryInterface::class);
        $this->factory = $this->createStub(CategoryInterfaceFactory::class);
        $this->management = $this->createStub(CategoryManagementInterface::class);
        $this->productRepo = $this->createStub(ProductRepositoryInterface::class);
    }

    private function category(string $label, int $id, string $name): Category
    {
        $c = $this->createStub(Category::class);
        $c->method('getId')->willReturn($id);
        $c->method('getName')->willReturn($name);
        $c->method('getParentId')->willReturn(2);
        $c->method('getLevel')->willReturn(2);
        $c->method('getPath')->willReturn('1/2/' . $id);
        $c->method('getIsActive')->willReturn('1');
        $c->method('getIncludeInMenu')->willReturn('0');
        $c->method('getUrlKey')->willReturn('cat-' . $id);
        $c->method('getProductCount')->willReturn('4');
        foreach (['setName', 'setParentId', 'setPath', 'setIsActive', 'setIncludeInMenu', 'setData'] as $setter) {
            $c->method($setter)->willReturnCallback(function (...$args) use ($label, $setter, $c) {
                $this->calls[$label][$setter][] = count($args) === 1 ? $args[0] : $args;
                return $c;
            });
        }
        $c->method('__call')->willReturnCallback(function (string $m, array $args) use ($label, $c) {
            $this->calls[$label][$m][] = $args[0] ?? null;
            return $c;
        });
        return $c;
    }

    private function tool(bool $confirmRequired = false, bool $dryRun = false): ManageCategories
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfirmationRequired')->willReturn($confirmRequired);
        $config->method('isDryRun')->willReturn($dryRun);

        $store = $this->createStub(Store::class);
        $store->method('getRootCategoryId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new ManageCategories($this->categoryRepo, $this->factory, $this->management, $this->productRepo, $storeManager, $config);
    }

    private function treeNode(int $id, string $name, array $children = []): Category
    {
        $node = $this->createStub(Category::class);
        $node->method('getId')->willReturn($id);
        $node->method('getName')->willReturn($name);
        $node->method('getLevel')->willReturn(count($children) ? 1 : 2);
        $node->method('getIsActive')->willReturn(true);
        $node->method('getProductCount')->willReturn(3);
        $node->method('getChildrenData')->willReturn($children);
        return $node;
    }

    public function testDefinitionAndUnknownAction(): void
    {
        $tool = $this->tool();
        $this->assertSame('manage_categories', $tool->name());
        $this->assertNotContains('delete', $tool->definition()['input_schema']['properties']['action']['enum']);
        $this->assertSame(['status' => 'error', 'message' => 'Unknown action: delete'], $tool->execute(['action' => 'delete']));
    }

    public function testTreeIsSerialisedRecursivelyWithClampedDepth(): void
    {
        $management = $this->createMock(CategoryManagementInterface::class);
        $management->expects($this->once())->method('getTree')->with(2, 5)
            ->willReturn($this->treeNode(2, 'Root', [$this->treeNode(3, 'Men'), $this->treeNode(4, 'Women')]));
        $this->management = $management;

        $result = $this->tool()->execute(['action' => 'tree', 'depth' => 99]);

        $this->assertSame('Root', $result['tree']['name']);
        $this->assertSame(['Men', 'Women'], array_column($result['tree']['children'], 'name'));
        $this->assertSame([], $result['tree']['children'][0]['children']);
        $this->assertSame(3, $result['tree']['children'][1]['product_count']);
        $this->assertSame('Category tree from root id=2, depth=5.', $result['summary']);
    }

    public function testGet(): void
    {
        $this->assertSame(['status' => 'error', 'message' => 'category_id required.'], $this->tool()->execute(['action' => 'get']));

        $this->categoryRepo->method('get')->willReturn($this->category('c', 7, 'Tops'));
        $result = $this->tool()->execute(['action' => 'get', 'category_id' => 7]);

        $this->assertSame([
            'category_id' => 7,
            'name' => 'Tops',
            'parent_id' => 2,
            'level' => 2,
            'path' => '1/2/7',
            'is_active' => true,
            'include_in_menu' => false,
            'url_key' => 'cat-7',
            'product_count' => 4,
        ], $result['category']);
        $this->assertSame('Category Tops (id=7).', $result['summary']);
    }

    public function testCreateValidationConfirmationAndDryRun(): void
    {
        $this->assertSame(
            'name and parent_id are required.',
            $this->tool()->execute(['action' => 'create', 'name' => 'X'])['message']
        );
        $this->assertSame(
            'needs_confirmation',
            $this->tool(true)->execute(['action' => 'create', 'name' => 'X', 'parent_id' => 2])['status']
        );
        $this->assertSame(
            '[DRY RUN] Would create category X under 2.',
            $this->tool(false, true)->execute(['action' => 'create', 'name' => 'X', 'parent_id' => 2])['message']
        );
    }

    public function testCreateBuildsCategoryUnderParent(): void
    {
        $new = $this->category('new', 15, 'Sale');
        $this->factory->method('create')->willReturn($new);
        $parent = $this->category('parent', 2, 'Default');
        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('get')->with(2)->willReturn($parent);
        $repo->expects($this->once())->method('save')->with($new)->willReturn($new);
        $this->categoryRepo = $repo;

        $result = $this->tool()->execute([
            'action' => 'create',
            'name' => ' Sale ',
            'parent_id' => 2,
            'include_in_menu' => false,
            'url_key' => 'sale',
            'description' => 'Deals',
        ]);

        $calls = $this->calls['new'];
        $this->assertSame(['Sale'], $calls['setName']);
        $this->assertSame([2], $calls['setParentId']);
        $this->assertSame(['1/2/2'], $calls['setPath']);
        $this->assertSame([true], $calls['setIsActive']);
        $this->assertSame([false], $calls['setIncludeInMenu']);
        $this->assertSame(['sale'], $calls['setUrlKey']);
        $this->assertSame([['description', 'Deals']], $calls['setData']);
        $this->assertSame('Created category Sale (id=15) under parent 2.', $result['summary']);
    }

    public function testUpdateFlow(): void
    {
        $this->assertSame('category_id required.', $this->tool()->execute(['action' => 'update'])['message']);

        $cat = $this->category('c', 7, 'Tops');
        $this->categoryRepo->method('get')->willReturn($cat);
        $this->categoryRepo->method('save')->willReturn($cat);

        $this->assertSame(
            'Update category Tops (id=7)? Re-call with confirm=true.',
            $this->tool(true)->execute(['action' => 'update', 'category_id' => 7])['message']
        );
        $this->assertSame(
            '[DRY RUN] Would update category 7.',
            $this->tool(false, true)->execute(['action' => 'update', 'category_id' => 7])['message']
        );

        $result = $this->tool()->execute(['action' => 'update', 'category_id' => 7, 'is_active' => 0, 'name' => 'Shirts']);
        $this->assertSame([false], $this->calls['c']['setIsActive']);
        $this->assertSame(['Shirts'], $this->calls['c']['setName']);
        $this->assertSame('Updated category Tops (id=7).', $result['summary']);
    }

    public function testAssignAndRemoveProduct(): void
    {
        $this->assertSame(
            'category_id and sku required.',
            $this->tool()->execute(['action' => 'assign_product', 'category_id' => 3])['message']
        );

        $assigned = [];
        $product = $this->createStub(Product::class);
        $product->method('getCategoryIds')->willReturn(['3', '5']);
        $product->method('__call')->willReturnCallback(function (string $m, array $args) use (&$assigned, $product) {
            if ($m === 'setCategoryIds') {
                $assigned[] = $args[0];
            }
            return $product;
        });
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('get')->with('MH01')->willReturn($product);
        $repo->expects($this->exactly(3))->method('save')->with($product);
        $this->productRepo = $repo;

        $add = $this->tool()->execute(['action' => 'assign_product', 'category_id' => 9, 'sku' => 'MH01']);
        $this->tool()->execute(['action' => 'assign_product', 'category_id' => 3, 'sku' => 'MH01']);
        $remove = $this->tool()->execute(['action' => 'remove_product', 'category_id' => 3, 'sku' => 'MH01']);

        $this->assertSame([[3, 5, 9], [3, 5], [5]], $assigned);
        $this->assertSame('Added product MH01 to category 9.', $add['summary']);
        $this->assertSame('Removed product MH01 from category 3.', $remove['summary']);
    }

    public function testAssignIgnoresDryRun(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getCategoryIds')->willReturn([]);
        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('get')->willReturn($product);
        $repo->expects($this->once())->method('save');
        $this->productRepo = $repo;

        $result = $this->tool(true, true)->execute(['action' => 'assign_product', 'category_id' => 9, 'sku' => 'MH01']);
        $this->assertSame('success', $result['status']);
    }

    public function testRepositoryErrorIsReturned(): void
    {
        $this->categoryRepo->method('get')->willThrowException(new \RuntimeException('No such entity with id = 999'));
        $this->assertSame(
            ['status' => 'error', 'message' => 'No such entity with id = 999'],
            $this->tool()->execute(['action' => 'get', 'category_id' => 999])
        );
    }
}
