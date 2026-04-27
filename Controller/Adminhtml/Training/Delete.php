<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Training;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Panth\ClaudeAi\Model\TrainingFactory;

class Delete extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_training';

    public function __construct(
        Context $context,
        private readonly TrainingFactory $trainingFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $id = (int) $this->getRequest()->getParam('id');
        if ($id > 0) {
            try {
                $t = $this->trainingFactory->create()->load($id);
                if ($t->getId()) {
                    $t->delete();
                    $this->messageManager->addSuccessMessage(__('Training example deleted.'));
                } else {
                    $this->messageManager->addErrorMessage(__('Training example not found.'));
                }
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }
        return $redirect->setPath('*/*/');
    }
}
