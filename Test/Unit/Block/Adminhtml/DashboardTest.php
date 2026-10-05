<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Block\Adminhtml;

use Panth\ClaudeAi\Block\Adminhtml\Dashboard;
use Panth\ClaudeAi\Block\Adminhtml\Howto;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Model\Stats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DashboardTest extends TestCase
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

    private function config(bool $enabled, string $key, bool $dryRun = false): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getApiKey')->willReturn($key);
        $config->method('isDryRun')->willReturn($dryRun);
        return $config;
    }

    private function block(?Config $config = null, ?Stats $stats = null): Dashboard
    {
        return new Dashboard(
            $this->blockContext(),
            $stats ?? $this->createStub(Stats::class),
            $config ?? $this->config(true, 'k'),
            new Pricing()
        );
    }

    #[DataProvider('percentProvider')]
    public function testPercentChange(int $current, int $previous, string $expected): void
    {
        $this->assertSame($expected, $this->block()->percentChange($current, $previous));
    }

    public static function percentProvider(): array
    {
        return [
            'growth from zero' => [5, 0, '+100%'],
            'nothing either month' => [0, 0, '0%'],
            'growth' => [15, 10, '+50%'],
            'decline' => [5, 20, '-75%'],
            'flat' => [10, 10, '+0%'],
            'fractional' => [10, 3, '+233.3%'],
        ];
    }

    public function testIsConfiguredNeedsKeyAndEnabledFlag(): void
    {
        $this->assertTrue($this->block($this->config(true, 'k'))->isConfigured());
        $this->assertFalse($this->block($this->config(false, 'k'))->isConfigured());
        $this->assertFalse($this->block($this->config(true, ''))->isConfigured());
        $this->assertTrue($this->block($this->config(false, 'k'))->hasApiKey());
        $this->assertFalse($this->block($this->config(true, ''))->hasApiKey());
    }

    public function testStatsUrlsAndCostFormatting(): void
    {
        $stats = $this->createMock(Stats::class);
        $stats->expects($this->once())->method('compute')->willReturn(['total_automations' => 3]);
        $block = $this->block(null, $stats);

        $this->assertSame(['total_automations' => 3], $block->getStats());
        $this->assertSame('$0.0050', $block->formatCost(0.005));
        $this->assertSame('https://admin.test/claudeai/chat/index', $block->getChatUrl());
        $this->assertSame('https://admin.test/claudeai/activity/index', $block->getActivityUrl());
        $this->assertSame('https://admin.test/adminhtml/system_config/edit?section=panth_claudeai', $block->getConfigUrl());
    }

    public function testHowtoBlock(): void
    {
        $howto = new Howto($this->blockContext(), $this->config(true, 'k', true));

        $this->assertTrue($howto->isConfigured());
        $this->assertTrue($howto->isDryRun());
        $this->assertSame('https://admin.test/claudeai/chat/index', $howto->getChatUrl());
        $this->assertSame('https://admin.test/claudeai/training/index', $howto->getTrainingUrl());
        $this->assertSame('https://admin.test/adminhtml/system_config/edit?section=panth_claudeai', $howto->getConfigUrl());
        $this->assertFalse((new Howto($this->blockContext(), $this->config(true, '')))->isConfigured());
    }
}
