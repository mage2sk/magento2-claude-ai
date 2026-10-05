<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\ResourceModel\Activity;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(
            \Panth\ClaudeAi\Model\Activity::class,
            \Panth\ClaudeAi\Model\ResourceModel\Activity::class
        );
    }
}
