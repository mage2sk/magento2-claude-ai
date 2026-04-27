<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\Model\AbstractModel;

class Checkpoint extends AbstractModel
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_RESTORED = 'restored';
    public const STATUS_EXPIRED  = 'expired';

    protected function _construct()
    {
        $this->_init(\Panth\ClaudeAi\Model\ResourceModel\Checkpoint::class);
    }
}
