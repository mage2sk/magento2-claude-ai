<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Model\Stats;

class Dashboard extends Template
{
    protected $_template = 'Panth_ClaudeAi::dashboard.phtml';

    public function __construct(
        Context $context,
        private readonly Stats $stats,
        private readonly Config $config,
        private readonly Pricing $pricing,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getStats(): array
    {
        return $this->stats->compute();
    }

    public function formatCost(float $usd): string
    {
        return $this->pricing->format($usd);
    }

    public function getChatUrl(): string
    {
        return $this->getUrl('claudeai/chat/index');
    }

    public function getActivityUrl(): string
    {
        return $this->getUrl('claudeai/activity/index');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'panth_claudeai']);
    }

    public function isConfigured(): bool
    {
        return $this->config->getApiKey() !== '' && $this->config->isEnabled();
    }

    public function percentChange(int $current, int $previous): string
    {
        if ($previous <= 0) {
            return $current > 0 ? '+100%' : '0%';
        }
        $change = round((($current - $previous) / $previous) * 100, 1);
        return ($change >= 0 ? '+' : '') . $change . '%';
    }
}
