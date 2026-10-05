<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\ClaudeAi\Model\Config;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_chat';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        if (!$this->config->isEnabled()) {
            $this->messageManager->addWarningMessage(
                __('Claude AI is disabled. Enable it in Stores -> Configuration -> Panth Extensions -> Claude AI.')
            );
        }
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_ClaudeAi::ai_chat');
        $page->getConfig()->getTitle()->prepend(__('Ask Claude AI'));
        return $page;
    }
}
