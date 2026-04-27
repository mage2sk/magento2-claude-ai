<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Conversation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_conversations';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_ClaudeAi::ai_conversations');
        $page->getConfig()->getTitle()->prepend(__('Conversations'));
        return $page;
    }
}
