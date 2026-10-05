<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Training;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\ClaudeAi\Model\TrainingFactory;

class Edit extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_training';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly TrainingFactory $trainingFactory,
        private readonly \Magento\Framework\Registry $registry
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $training = $this->trainingFactory->create();
        if ($id > 0) {
            $training->load($id);
            if (!$training->getId()) {
                $this->messageManager->addErrorMessage(__('Training example does not exist.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }
        $this->registry->register('panth_claudeai_training', $training);

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_ClaudeAi::ai_training');
        $title = $training->getId() ? __('Edit Training Example') : __('New Training Example');
        $page->getConfig()->getTitle()->prepend($title);
        return $page;
    }
}
