<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\ToolRegistry;

class Chat extends Template
{
    protected $_template = 'Panth_ClaudeAi::chat.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ToolRegistry $tools,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('claudeai/chat/send');
    }

    public function getUploadUrl(): string
    {
        return $this->getUrl('claudeai/chat/upload');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'panth_claudeai']);
    }

    public function getFormKey(): string
    {
        return (string) $this->formKey->getFormKey();
    }

    public function isConfigured(): bool
    {
        return $this->config->getApiKey() !== '' && $this->config->isEnabled();
    }

    public function getModel(): string
    {
        return $this->config->getModel();
    }

    public function isDryRun(): bool
    {
        return $this->config->isDryRun();
    }

    /** @return array<int, array{name:string,description:string}> */
    public function getToolSummaries(): array
    {
        $list = [];
        foreach ($this->tools->all() as $tool) {
            $def = $tool->definition();
            $list[] = [
                'name' => $def['name'] ?? $tool->name(),
                'description' => $def['description'] ?? '',
            ];
        }
        return $list;
    }

    /** @return array<int, string> Suggested prompts shown as quick-start chips */
    public function getSuggestions(): array
    {
        return [
            'How many customers do we have?',
            'Show me the 10 most recent orders',
            'Find all products with "Hoodie" in the name',
            'Update the price of all products matching MH% to $39.99',
            'List products with stock at or below 3 units',
        ];
    }
}
