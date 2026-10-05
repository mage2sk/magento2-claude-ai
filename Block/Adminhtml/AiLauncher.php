<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\ClaudeAi\Model\Config;

class AiLauncher extends Template
{
    protected $_template = 'Panth_ClaudeAi::launcher.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml()
    {
        if (!$this->config->isEnabled() || $this->config->getApiKey() === '') {
            return '';
        }
        if (!$this->_authorization->isAllowed('Panth_ClaudeAi::ai_chat')) {
            return '';
        }
        return parent::_toHtml();
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('claudeai/chat/send');
    }

    public function getStreamUrl(): string
    {
        return $this->getUrl('claudeai/chat/stream');
    }

    public function getUploadUrl(): string
    {
        return $this->getUrl('claudeai/chat/upload');
    }

    public function getFullChatUrl(): string
    {
        return $this->getUrl('claudeai/chat/index');
    }

    public function getFormKey(): string
    {
        return (string) $this->formKey->getFormKey();
    }

    public function getModel(): string
    {
        return $this->config->getModel();
    }

    public function isDryRun(): bool
    {
        return $this->config->isDryRun();
    }

    public function getLauncherPosition(): string
    {
        $value = (string) $this->_scopeConfig->getValue('panth_claudeai/general/launcher_position');
        return $value === 'floating' ? 'floating' : 'header';
    }
}
