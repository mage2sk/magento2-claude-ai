<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Training;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_training';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_ClaudeAi::ai_training');
        $page->getConfig()->getTitle()->prepend(__('Training Examples'));
        return $page;
    }
}
