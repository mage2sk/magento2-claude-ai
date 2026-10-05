<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Block\Adminhtml;

use Magento\Framework\AuthorizationInterface;
use Panth\ClaudeAi\Block\Adminhtml\AiLauncher;
use Panth\ClaudeAi\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiLauncherTest extends TestCase
{
    use BlockContextTrait;

    protected function setUp(): void
    {
        $this->installObjectManager();
    }

    protected function tearDown(): void
    {
        $this->restoreObjectManager();
    }

    private function config(bool $enabled = true, string $key = 'k', bool $dryRun = false): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getApiKey')->willReturn($key);
        $config->method('getModel')->willReturn('claude-opus-4-7');
        $config->method('isDryRun')->willReturn($dryRun);
        return $config;
    }

    private function render(AiLauncher $block): string
    {
        return (new \ReflectionMethod($block, '_toHtml'))->invoke($block);
    }

    #[DataProvider('hiddenProvider')]
    public function testLauncherIsHiddenWhenNotUsable(bool $enabled, string $key): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects($this->never())->method('isAllowed');

        $block = new AiLauncher($this->blockContext([], $authorization), $this->config($enabled, $key));
        $this->assertSame('', $this->render($block));
    }

    public static function hiddenProvider(): array
    {
        return [
            'disabled' => [false, 'k'],
            'no key' => [true, ''],
        ];
    }

    public function testAclIsCheckedWhenConfigured(): void
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects($this->once())->method('isAllowed')->with('Panth_ClaudeAi::ai_chat')->willReturn(false);

        $block = new AiLauncher($this->blockContext([], $authorization), $this->config());
        $this->assertSame('', $this->render($block));
    }

    public function testUrlsAndSettings(): void
    {
        $block = new AiLauncher($this->blockContext([], null, [], 'FORMKEY'), $this->config(true, 'k', true));

        $this->assertSame('https://admin.test/claudeai/chat/send', $block->getSendUrl());
        $this->assertSame('https://admin.test/claudeai/chat/stream', $block->getStreamUrl());
        $this->assertSame('https://admin.test/claudeai/chat/upload', $block->getUploadUrl());
        $this->assertSame('https://admin.test/claudeai/chat/index', $block->getFullChatUrl());
        $this->assertSame('FORMKEY', $block->getFormKey());
        $this->assertSame('claude-opus-4-7', $block->getModel());
        $this->assertTrue($block->isDryRun());
    }

    #[DataProvider('positionProvider')]
    public function testLauncherPosition(?string $stored, string $expected): void
    {
        $block = new AiLauncher(
            $this->blockContext([], null, ['panth_claudeai/general/launcher_position' => $stored]),
            $this->config()
        );
        $this->assertSame($expected, $block->getLauncherPosition());
    }

    public static function positionProvider(): array
    {
        return [
            'floating' => ['floating', 'floating'],
            'header' => ['header', 'header'],
            'unset' => [null, 'header'],
            'garbage' => ['sidebar', 'header'],
        ];
    }
}
