<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\ClaudeAi\Model\Config;

class Howto extends Template
{
    protected $_template = 'Panth_ClaudeAi::howto.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isConfigured(): bool
    {
        return $this->config->getApiKey() !== '' && $this->config->isEnabled();
    }

    public function isDryRun(): bool
    {
        return $this->config->isDryRun();
    }

    public function getChatUrl(): string { return $this->getUrl('claudeai/chat/index'); }
    public function getTrainingUrl(): string { return $this->getUrl('claudeai/training/index'); }
    public function getConfigUrl(): string { return $this->getUrl('adminhtml/system_config/edit', ['section' => 'panth_claudeai']); }
}
