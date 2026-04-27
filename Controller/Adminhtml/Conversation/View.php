<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Conversation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class View extends Action
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
        $cid = (string) $this->getRequest()->getParam('id', '');
        $title = $cid !== ''
            ? __('Conversation %1', mb_strimwidth($cid, 0, 16, '…'))
            : __('Conversation');
        $page->getConfig()->getTitle()->prepend($title);
        return $page;
    }
}
