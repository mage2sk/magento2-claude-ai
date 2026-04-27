<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Controller\Adminhtml\Training;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Panth\ClaudeAi\Model\TrainingFactory;

class Save extends Action
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
        $data = $this->getRequest()->getPostValue();
        if (!$data) {
            return $redirect->setPath('*/*/');
        }

        try {
            $id = (int) ($data['training_id'] ?? 0);
            $training = $this->trainingFactory->create();
            if ($id > 0) {
                $training->load($id);
                if (!$training->getId()) {
                    throw new \RuntimeException('Training example not found.');
                }
            }
            $training->setData(array_merge($training->getData(), [
                'title'            => trim((string) ($data['title'] ?? '')),
                'user_message'     => trim((string) ($data['user_message'] ?? '')),
                'expected_outcome' => trim((string) ($data['expected_outcome'] ?? '')),
                'category'         => trim((string) ($data['category'] ?? '')) ?: null,
                'status'           => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
                'sort_order'       => (int) ($data['sort_order'] ?? 0),
            ]));

            if ($training->getData('title') === '') {
                throw new \RuntimeException('Title is required.');
            }
            if ($training->getData('user_message') === '') {
                throw new \RuntimeException('User message is required.');
            }
            if ($training->getData('expected_outcome') === '') {
                throw new \RuntimeException('Expected outcome is required.');
            }

            $training->save();
            $this->messageManager->addSuccessMessage(__('Training example saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['id' => (int) $training->getId()]);
            }
            return $redirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/edit', ['id' => (int) ($data['training_id'] ?? 0)]);
        }
    }
}
