<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml\Training;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

class Edit extends Template
{
    protected $_template = 'Panth_ClaudeAi::training/edit.phtml';

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getTraining(): ?\Panth\ClaudeAi\Model\Training
    {
        return $this->registry->registry('panth_claudeai_training');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('claudeai/training/save');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('claudeai/training/index');
    }

    public function getDeleteUrl(): string
    {
        $t = $this->getTraining();
        return $t && $t->getId() ? $this->getUrl('claudeai/training/delete', ['id' => $t->getId()]) : '';
    }
}
