<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\ResourceModel\Training;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'training_id';

    protected function _construct()
    {
        $this->_init(
            \Panth\ClaudeAi\Model\Training::class,
            \Panth\ClaudeAi\Model\ResourceModel\Training::class
        );
    }
}
