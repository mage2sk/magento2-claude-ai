<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Howto;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_dashboard';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_ClaudeAi::howto');
        $page->getConfig()->getTitle()->prepend(__('How to Use Claude AI'));
        return $page;
    }
}
