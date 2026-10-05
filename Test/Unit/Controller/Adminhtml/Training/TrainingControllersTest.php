<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\Training;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Panth\ClaudeAi\Controller\Adminhtml\Training\Delete;
use Panth\ClaudeAi\Controller\Adminhtml\Training\Edit;
use Panth\ClaudeAi\Controller\Adminhtml\Training\Save;
use Panth\ClaudeAi\Model\Training;
use Panth\ClaudeAi\Model\TrainingFactory;
use Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\ContextBuilderTrait;
use PHPUnit\Framework\TestCase;

class TrainingControllersTest extends TestCase
{
    use ContextBuilderTrait;

    private array $store = [];
    private bool $saved = false;
    private bool $deleted = false;

    private function training(array $existing = [], ?\Throwable $saveError = null): Training
    {
        $t = $this->createStub(Training::class);
        $t->method('load')->willReturnCallback(function ($id) use ($existing, $t) {
            if (isset($existing[$id])) {
                $this->store = $existing[$id];
            }
            return $t;
        });
        $t->method('getId')->willReturnCallback(fn() => $this->store['training_id'] ?? null);
        $t->method('getData')->willReturnCallback(fn($key = '') => $key === '' ? $this->store : ($this->store[$key] ?? null));
        $t->method('setData')->willReturnCallback(function ($data) use ($t) {
            $this->store = $data;
            return $t;
        });
        $t->method('save')->willReturnCallback(function () use ($t, $saveError) {
            if ($saveError) {
                throw $saveError;
            }
            $this->saved = true;
            $this->store['training_id'] = $this->store['training_id'] ?? 31;
            return $t;
        });
        $t->method('delete')->willReturnCallback(function () use ($t) {
            $this->deleted = true;
            return $t;
        });
        return $t;
    }

    private function factory(Training $training): TrainingFactory
    {
        $factory = $this->createStub(TrainingFactory::class);
        $factory->method('create')->willReturn($training);
        return $factory;
    }

    public function testAclResources(): void
    {
        $this->assertSame('Panth_ClaudeAi::ai_training', Delete::ADMIN_RESOURCE);
        $this->assertSame('Panth_ClaudeAi::ai_training', Save::ADMIN_RESOURCE);
        $this->assertSame('Panth_ClaudeAi::ai_training', Edit::ADMIN_RESOURCE);
    }

    public function testDeleteWithoutIdOnlyRedirects(): void
    {
        $factory = $this->createMock(TrainingFactory::class);
        $factory->expects($this->never())->method('create');

        (new Delete($this->buildContext($this->request()), $factory))->execute();

        $this->assertSame([], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testDeleteExistingAndMissing(): void
    {
        (new Delete($this->buildContext($this->request(['id' => '4'])), $this->factory($this->training([4 => ['training_id' => 4]]))))->execute();
        $this->assertTrue($this->deleted);
        $this->assertSame([['success', 'Training example deleted.']], $this->messages);

        $this->messages = [];
        $this->deleted = false;
        $this->store = [];
        (new Delete($this->buildContext($this->request(['id' => '5'])), $this->factory($this->training())))->execute();
        $this->assertFalse($this->deleted);
        $this->assertSame([['error', 'Training example not found.']], $this->messages);
    }

    public function testDeleteExceptionIsReported(): void
    {
        $t = $this->createStub(Training::class);
        $t->method('load')->willThrowException(new \RuntimeException('db down'));

        (new Delete($this->buildContext($this->request(['id' => '4'])), $this->factory($t)))->execute();
        $this->assertSame([['error', 'db down']], $this->messages);
    }

    public function testSaveWithoutPostRedirects(): void
    {
        $factory = $this->createMock(TrainingFactory::class);
        $factory->expects($this->never())->method('create');

        (new Save($this->buildContext($this->request()), $factory))->execute();
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testSaveNewExampleNormalisesFields(): void
    {
        $post = [
            'title' => '  Discount  ',
            'user_message' => ' Discount hoodies ',
            'expected_outcome' => ' Use get_products ',
            'category' => '   ',
            'status' => '5',
            'sort_order' => '20',
        ];

        (new Save($this->buildContext($this->request([], '', $post)), $this->factory($this->training())))->execute();

        $this->assertTrue($this->saved);
        $this->assertSame('Discount', $this->store['title']);
        $this->assertSame('Discount hoodies', $this->store['user_message']);
        $this->assertNull($this->store['category']);
        $this->assertSame(0, $this->store['status']);
        $this->assertSame(20, $this->store['sort_order']);
        $this->assertSame([['success', 'Training example saved.']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testSaveAndContinueEditingRedirectsBackToRecord(): void
    {
        $post = ['training_id' => '9', 'title' => 'T', 'user_message' => 'U', 'expected_outcome' => 'E', 'status' => '1'];
        $existing = [9 => ['training_id' => 9, 'usage_count' => 4, 'title' => 'Old']];

        (new Save($this->buildContext($this->request(['back' => 'edit'], '', $post)), $this->factory($this->training($existing))))->execute();

        $this->assertSame(4, $this->store['usage_count']);
        $this->assertSame('T', $this->store['title']);
        $this->assertSame(1, $this->store['status']);
        $this->assertSame(['*/*/edit', ['id' => 9]], $this->redirect);
    }

    public function testSaveValidationErrors(): void
    {
        $cases = [
            [['title' => ' ', 'user_message' => 'U', 'expected_outcome' => 'E'], 'Title is required.'],
            [['title' => 'T', 'user_message' => '', 'expected_outcome' => 'E'], 'User message is required.'],
            [['title' => 'T', 'user_message' => 'U'], 'Expected outcome is required.'],
            [['training_id' => '77', 'title' => 'T', 'user_message' => 'U', 'expected_outcome' => 'E'], 'Training example not found.'],
        ];
        foreach ($cases as [$post, $message]) {
            $this->messages = [];
            $this->store = [];
            (new Save($this->buildContext($this->request([], '', $post)), $this->factory($this->training())))->execute();
            $this->assertSame([['error', $message]], $this->messages);
            $this->assertSame(['*/*/edit', ['id' => (int) ($post['training_id'] ?? 0)]], $this->redirect);
        }
        $this->assertFalse($this->saved);
    }

    public function testSaveExceptionIsReported(): void
    {
        $post = ['title' => 'T', 'user_message' => 'U', 'expected_outcome' => 'E'];
        (new Save($this->buildContext($this->request([], '', $post)), $this->factory($this->training([], new \RuntimeException('Duplicate')))))->execute();

        $this->assertSame([['error', 'Duplicate']], $this->messages);
    }

    private function pageFactory(array &$titles, array &$menus): PageFactory
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($t) use (&$titles) {
            $titles[] = (string) $t;
        });
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->method('setActiveMenu')->willReturnCallback(function ($menu) use (&$menus, $page) {
            $menus[] = $menu;
            return $page;
        });
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);
        return $factory;
    }

    public function testEditMissingRecordRedirects(): void
    {
        $titles = [];
        $menus = [];
        $registry = $this->createMock(Registry::class);
        $registry->expects($this->never())->method('register');

        (new Edit($this->buildContext($this->request(['id' => '8'])), $this->pageFactory($titles, $menus), $this->factory($this->training()), $registry))
            ->execute();

        $this->assertSame([['error', 'Training example does not exist.']], $this->messages);
        $this->assertSame(['*/*/', []], $this->redirect);
        $this->assertSame([], $titles);
    }

    public function testEditRegistersRecordAndSetsTitle(): void
    {
        $titles = [];
        $menus = [];
        $training = $this->training([8 => ['training_id' => 8]]);
        $registry = $this->createMock(Registry::class);
        $registry->expects($this->once())->method('register')->with('panth_claudeai_training', $training);

        (new Edit($this->buildContext($this->request(['id' => '8'])), $this->pageFactory($titles, $menus), $this->factory($training), $registry))
            ->execute();

        $this->assertSame(['Edit Training Example'], $titles);
        $this->assertSame(['Panth_ClaudeAi::ai_training'], $menus);
    }

    public function testNewRecordTitle(): void
    {
        $titles = [];
        $menus = [];
        (new Edit($this->buildContext($this->request()), $this->pageFactory($titles, $menus), $this->factory($this->training()), $this->createStub(Registry::class)))
            ->execute();

        $this->assertSame(['New Training Example'], $titles);
    }
}
