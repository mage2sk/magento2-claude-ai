<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\Pricing;

class Activity extends Template
{
    protected $_template = 'Panth_ClaudeAi::activity.phtml';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly Pricing $pricing,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array<int,array<string,mixed>> */
    public function getRecentActivity(int $limit = 100): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_activity');
        if (!$conn->isTableExists($table)) {
            return [];
        }
        return $conn->fetchAll(
            "SELECT entity_id, conversation_id, actor_type, action, prompt, result, status, "
            . "affected_count, duration_ms, input_tokens, output_tokens, cache_read_tokens, "
            . "created_at "
            . "FROM {$table} ORDER BY created_at DESC LIMIT " . max(1, $limit)
        );
    }

    /** Compute USD cost for one activity row (assistant turns only). */
    public function rowCost(array $row): string
    {
        $in    = (int) ($row['input_tokens']      ?? 0);
        $out   = (int) ($row['output_tokens']     ?? 0);
        $cache = (int) ($row['cache_read_tokens'] ?? 0);
        if ($in === 0 && $out === 0 && $cache === 0) {
            return '';
        }
        return $this->pricing->format(
            $this->pricing->costFor($this->config->getModel(), $in, $out, $cache)
        );
    }
}
