<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Checkpoint extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('panth_claudeai_checkpoint', 'entity_id');
    }
}
