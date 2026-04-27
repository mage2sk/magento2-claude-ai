<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\ClaudeAi\Model\ResourceModel\Checkpoint\CollectionFactory;

class Checkpoint extends Template
{
    protected $_template = 'Panth_ClaudeAi::checkpoint.phtml';

    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return \Panth\ClaudeAi\Model\Checkpoint[] */
    public function getCheckpoints(int $limit = 100): array
    {
        $coll = $this->collectionFactory->create();
        $coll->setOrder('created_at', 'DESC')->setPageSize($limit);
        return array_values($coll->getItems());
    }

    public function getRestoreUrl(string $checkpointId): string
    {
        return $this->getUrl('claudeai/checkpoint/restore', ['checkpoint_id' => $checkpointId]);
    }
}
