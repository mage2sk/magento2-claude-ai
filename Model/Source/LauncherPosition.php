<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LauncherPosition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'header', 'label' => __('Page header')],
            ['value' => 'floating', 'label' => __('Floating corner button')],
        ];
    }
}
