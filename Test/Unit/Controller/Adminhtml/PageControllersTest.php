<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Panth\ClaudeAi\Controller\Adminhtml\Activity\Index as ActivityIndex;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\Index as ChatIndex;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\Load;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\Send;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\Stream;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\Upload;
use Panth\ClaudeAi\Controller\Adminhtml\Checkpoint\Index as CheckpointIndex;
use Panth\ClaudeAi\Controller\Adminhtml\Conversation\Index as ConversationIndex;
use Panth\ClaudeAi\Controller\Adminhtml\Conversation\View as ConversationView;
use Panth\ClaudeAi\Controller\Adminhtml\Dashboard\Index as DashboardIndex;
use Panth\ClaudeAi\Controller\Adminhtml\Howto\Index as HowtoIndex;
use Panth\ClaudeAi\Controller\Adminhtml\Training\Index as TrainingIndex;
use Panth\ClaudeAi\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PageControllersTest extends TestCase
{
    use ContextBuilderTrait;

    private array $titles = [];
    private array $menus = [];

    private function pageFactory(): PageFactory
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($t) {
            $this->titles[] = (string) $t;
        });
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->method('setActiveMenu')->willReturnCallback(function ($menu) use ($page) {
            $this->menus[] = $menu;
            return $page;
        });
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);
        return $factory;
    }

    #[DataProvider('aclProvider')]
    public function testAdminResources(string $class, string $resource): void
    {
        $this->assertSame($resource, constant($class . '::ADMIN_RESOURCE'));
    }

    public static function aclProvider(): array
    {
        return [
            'activity' => [ActivityIndex::class, 'Panth_ClaudeAi::ai_activity'],
            'chat index' => [ChatIndex::class, 'Panth_ClaudeAi::ai_chat'],
            'chat load' => [Load::class, 'Panth_ClaudeAi::ai_chat'],
            'chat send' => [Send::class, 'Panth_ClaudeAi::ai_chat'],
            'chat stream' => [Stream::class, 'Panth_ClaudeAi::ai_chat'],
            'chat upload' => [Upload::class, 'Panth_ClaudeAi::ai_upload'],
            'checkpoint' => [CheckpointIndex::class, 'Panth_ClaudeAi::ai_checkpoint'],
            'conversations' => [ConversationIndex::class, 'Panth_ClaudeAi::ai_conversations'],
            'conversation view' => [ConversationView::class, 'Panth_ClaudeAi::ai_conversations'],
            'dashboard' => [DashboardIndex::class, 'Panth_ClaudeAi::ai_dashboard'],
            'howto' => [HowtoIndex::class, 'Panth_ClaudeAi::ai_dashboard'],
            'training' => [TrainingIndex::class, 'Panth_ClaudeAi::ai_training'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function testIndexPagesSetMenuAndTitle(string $class, string $menu, string $title): void
    {
        $controller = new $class($this->buildContext($this->request()), $this->pageFactory());
        $this->assertInstanceOf(Page::class, $controller->execute());
        $this->assertSame([$menu], $this->menus);
        $this->assertSame([$title], $this->titles);
    }

    public static function pageProvider(): array
    {
        return [
            'activity' => [ActivityIndex::class, 'Panth_ClaudeAi::ai_activity', 'Activity Log'],
            'checkpoint' => [CheckpointIndex::class, 'Panth_ClaudeAi::ai_checkpoint', 'Checkpoints & Restore'],
            'conversations' => [ConversationIndex::class, 'Panth_ClaudeAi::ai_conversations', 'Conversations'],
            'dashboard' => [DashboardIndex::class, 'Panth_ClaudeAi::ai_dashboard', 'AI Dashboard'],
            'howto' => [HowtoIndex::class, 'Panth_ClaudeAi::howto', 'How to Use Claude AI'],
            'training' => [TrainingIndex::class, 'Panth_ClaudeAi::ai_training', 'Training Examples'],
        ];
    }

    #[DataProvider('conversationTitleProvider')]
    public function testConversationViewTitle(array $params, string $expected): void
    {
        (new ConversationView($this->buildContext($this->request($params)), $this->pageFactory()))->execute();

        $this->assertSame(['Panth_ClaudeAi::ai_conversations'], $this->menus);
        $this->assertSame([$expected], $this->titles);
    }

    public static function conversationTitleProvider(): array
    {
        return [
            'cid param' => [['cid' => 'abc'], 'Conversation abc'],
            'id fallback' => [['id' => 'xyz'], 'Conversation xyz'],
            'long id truncated' => [['cid' => str_repeat('a', 30)], 'Conversation ' . str_repeat('a', 13) . '...'],
            'no id' => [[], 'Conversation'],
        ];
    }

    #[DataProvider('chatIndexProvider')]
    public function testChatIndexWarnsWhenDisabled(bool $enabled, int $warnings): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        $messageManager = $this->createMock(ManagerInterface::class);
        $messageManager->expects($this->exactly($warnings))->method('addWarningMessage');
        $context = $this->buildContext($this->request(), 7, 'fk', $messageManager);

        (new ChatIndex($context, $this->pageFactory(), $config))->execute();

        $this->assertSame(['Ask Claude AI'], $this->titles);
    }

    public static function chatIndexProvider(): array
    {
        return [
            'disabled' => [false, 1],
            'enabled' => [true, 0],
        ];
    }
}
