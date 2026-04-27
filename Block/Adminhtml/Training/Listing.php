<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml\Training;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\ClaudeAi\Model\ResourceModel\Training\CollectionFactory;

class Listing extends Template
{
    protected $_template = 'Panth_ClaudeAi::training/listing.phtml';

    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return \Panth\ClaudeAi\Model\Training[] */
    public function getTrainingItems(): array
    {
        $coll = $this->collectionFactory->create();
        $coll->setOrder('sort_order', 'ASC')->setOrder('training_id', 'DESC');
        return array_values($coll->getItems());
    }

    public function getNewUrl(): string
    {
        return $this->getUrl('claudeai/training/edit');
    }

    public function getEditUrl(int $id): string
    {
        return $this->getUrl('claudeai/training/edit', ['id' => $id]);
    }

    public function getDeleteUrl(int $id): string
    {
        return $this->getUrl('claudeai/training/delete', ['id' => $id]);
    }
}
