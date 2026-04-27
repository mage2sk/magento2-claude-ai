<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Effort implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'low',    'label' => __('Low — fast, scoped tasks')],
            ['value' => 'medium', 'label' => __('Medium — balanced')],
            ['value' => 'high',   'label' => __('High — recommended for agentic work')],
            ['value' => 'xhigh',  'label' => __('Extra High — Opus 4.7 only, deeper reasoning')],
            ['value' => 'max',    'label' => __('Max — Opus only, ceiling effort')],
        ];
    }
}
