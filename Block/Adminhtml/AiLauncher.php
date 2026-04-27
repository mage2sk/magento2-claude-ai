<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\ClaudeAi\Model\Config;

/**
 * Floating "Ask Claude" launcher injected into every admin page. Click →
 * opens a popup chat panel right where the admin is — no navigation
 * required. Talks to the same /claudeai/chat/send + /upload endpoints
 * the dedicated Ask Claude page uses.
 *
 * Hidden automatically for admin users without the Claude AI ACL resource
 * via _toHtml() — a launcher they couldn't actually use would just be
 * confusing.
 */
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

    /**
     * Render nothing for admins without access — and nothing when the
     * module is disabled or the API key is missing.
     */
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
}
