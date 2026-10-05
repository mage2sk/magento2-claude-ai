<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Model implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'claude-opus-4-7',   'label' => __('Claude Opus 4.7 (most capable)')],
            ['value' => 'claude-opus-4-6',   'label' => __('Claude Opus 4.6')],
            ['value' => 'claude-sonnet-4-6', 'label' => __('Claude Sonnet 4.6 (faster, cheaper)')],
            ['value' => 'claude-haiku-4-5',  'label' => __('Claude Haiku 4.5 (fastest)')],
        ];
    }
}
