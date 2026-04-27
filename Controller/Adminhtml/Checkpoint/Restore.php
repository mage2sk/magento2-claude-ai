<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Checkpoint;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Panth\ClaudeAi\Model\CheckpointService;

class Restore extends Action
{
    public const ADMIN_RESOURCE = 'Panth_ClaudeAi::ai_checkpoint';

    public function __construct(
        Context $context,
        private readonly CheckpointService $checkpoints
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $checkpointId = (string) $this->getRequest()->getParam('checkpoint_id', '');
        if ($checkpointId === '') {
            $this->messageManager->addErrorMessage(__('No checkpoint specified.'));
            return $redirect->setPath('*/*/');
        }
        try {
            $r = $this->checkpoints->restore($checkpointId);
            if (($r['status'] ?? '') === 'success') {
                $this->messageManager->addSuccessMessage(
                    __('Restored %1 records from checkpoint %2.', (int) ($r['affected_count'] ?? 0), $checkpointId)
                );
            } else {
                $this->messageManager->addErrorMessage((string) ($r['message'] ?? 'Restore failed.'));
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect->setPath('*/*/');
    }
}
