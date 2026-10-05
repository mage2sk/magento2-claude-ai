<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\Model\AbstractModel;

class Training extends AbstractModel
{
    public const STATUS_ACTIVE   = 1;
    public const STATUS_DISABLED = 0;

    protected function _construct()
    {
        $this->_init(\Panth\ClaudeAi\Model\ResourceModel\Training::class);
    }
}
