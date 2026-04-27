<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\Model\AbstractModel;

class Activity extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Panth\ClaudeAi\Model\ResourceModel\Activity::class);
    }
}
