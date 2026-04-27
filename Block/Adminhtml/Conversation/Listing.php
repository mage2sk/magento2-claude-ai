<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml\Conversation;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Panth\ClaudeAi\Model\Pricing;

/**
 * Groups panth_claudeai_message rows by conversation_id so each chat
 * appears as ONE row in the admin grid (not one row per turn).
 *
 * Each row shows: when it started, how many turns, total cost, the
 * first user prompt as a preview, and a "View" link into the
 * full transcript page.
 */
class Listing extends Template
{
    protected $_template = 'Panth_ClaudeAi::conversation/listing.phtml';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly Pricing $pricing,
        private readonly BackendUrl $backendUrl,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getConversations(int $limit = 100): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_message');
        $att   = $this->resource->getTableName('panth_claudeai_attachment');
        if (!$conn->isTableExists($table)) {
            return [];
        }

        $rows = $conn->fetchAll(
            "SELECT conversation_id,
                    MIN(created_at)   AS started_at,
                    MAX(created_at)   AS last_at,
                    COUNT(*)          AS turns,
                    SUM(IFNULL(input_tokens,0))       AS in_tokens,
                    SUM(IFNULL(output_tokens,0))      AS out_tokens,
                    SUM(IFNULL(cache_read_tokens,0))  AS cache_read,
                    SUM(IFNULL(cache_write_tokens,0)) AS cache_write,
                    SUM(IFNULL(cost_usd,0))           AS total_cost,
                    MAX(model)        AS model,
                    MAX(surface)      AS surface,
                    MAX(admin_user_id) AS admin_user_id
               FROM {$table}
              GROUP BY conversation_id
              ORDER BY MAX(created_at) DESC
              LIMIT " . max(1, $limit)
        );

        if (!$rows) {
            return [];
        }

        $cids = array_column($rows, 'conversation_id');
        $place = implode(',', array_fill(0, count($cids), '?'));

        // First user prompt preview per conversation.
        $previews = [];
        $previewRows = $conn->fetchAll(
            "SELECT conversation_id, content_json
               FROM {$table}
              WHERE role = 'user' AND conversation_id IN ({$place})
              ORDER BY conversation_id, sequence ASC",
            $cids
        );
        foreach ($previewRows as $p) {
            $cid = (string) $p['conversation_id'];
            if (isset($previews[$cid])) {
                continue;
            }
            $previews[$cid] = $this->extractPreview((string) $p['content_json']);
        }

        // Attachment count per conversation.
        $attCount = [];
        if ($conn->isTableExists($att)) {
            $aRows = $conn->fetchAll(
                "SELECT conversation_id, COUNT(*) AS c
                   FROM {$att}
                  WHERE conversation_id IN ({$place})
                  GROUP BY conversation_id",
                $cids
            );
            foreach ($aRows as $a) {
                $attCount[(string) $a['conversation_id']] = (int) $a['c'];
            }
        }

        foreach ($rows as &$r) {
            $cid = (string) $r['conversation_id'];
            $r['preview']     = $previews[$cid] ?? '';
            $r['attachments'] = $attCount[$cid] ?? 0;
        }
        return $rows;
    }

    public function getViewUrl(string $conversationId): string
    {
        // Use `cid` not `id` — Magento backend reserves `id` for entity routing
        // and silently drops it for some controller chains, which leaves the
        // view page with no conversation ID to look up.
        return $this->backendUrl->getUrl(
            'claudeai/conversation/view',
            ['cid' => $conversationId]
        );
    }

    public function formatCost(float $usd): string
    {
        return $this->pricing->format($usd);
    }

    private function extractPreview(string $contentJson): string
    {
        $decoded = json_decode($contentJson, true);
        if (!is_array($decoded)) {
            return '';
        }
        // Either a single block (string content stored as one block) or array of blocks.
        if (isset($decoded['type'])) {
            $decoded = [$decoded];
        }
        foreach ($decoded as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                return mb_strimwidth((string) $block['text'], 0, 160, '…');
            }
        }
        return '';
    }
}
