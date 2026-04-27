<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml\Conversation;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\Pricing;

/**
 * Renders one full conversation as a chat-style timeline.
 *
 * Pulls every row from panth_claudeai_message for the requested
 * conversation_id, decodes content_json, and exposes typed helpers
 * for the template so each content-block type (text, image, document,
 * tool_use, tool_result) renders cleanly.
 *
 * Image blocks ship their base64 source inline so they render without
 * needing a separate URL — exactly the bytes Claude saw.
 */
class View extends Template
{
    protected $_template = 'Panth_ClaudeAi::conversation/view.phtml';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly Pricing $pricing,
        private readonly BackendUrl $backendUrl,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getConversationId(): string
    {
        return (string) $this->getRequest()->getParam('id', '');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getMessages(): array
    {
        $cid = $this->getConversationId();
        if ($cid === '') {
            return [];
        }
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_message');
        if (!$conn->isTableExists($table)) {
            return [];
        }
        $rows = $conn->fetchAll(
            "SELECT message_id, sequence, role, surface, content_json,
                    input_tokens, output_tokens, cache_read_tokens, cost_usd,
                    model, created_at
               FROM {$table}
              WHERE conversation_id = ?
              ORDER BY sequence ASC, message_id ASC",
            [$cid]
        );
        foreach ($rows as &$r) {
            $r['blocks'] = $this->decodeBlocks((string) $r['content_json']);
        }
        return $rows;
    }

    public function getSummary(): array
    {
        $cid = $this->getConversationId();
        if ($cid === '') {
            return [];
        }
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_message');
        if (!$conn->isTableExists($table)) {
            return [];
        }
        $row = $conn->fetchRow(
            "SELECT MIN(created_at) AS started_at,
                    MAX(created_at) AS last_at,
                    COUNT(*)        AS turns,
                    SUM(IFNULL(input_tokens,0))      AS in_tokens,
                    SUM(IFNULL(output_tokens,0))     AS out_tokens,
                    SUM(IFNULL(cache_read_tokens,0)) AS cache_tokens,
                    SUM(IFNULL(cost_usd,0))          AS total_cost,
                    MAX(model)   AS model,
                    MAX(surface) AS surface
               FROM {$table}
              WHERE conversation_id = ?",
            [$cid]
        );
        return $row ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getAttachments(): array
    {
        $cid = $this->getConversationId();
        if ($cid === '') {
            return [];
        }
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_attachment');
        if (!$conn->isTableExists($table)) {
            return [];
        }
        $rows = $conn->fetchAll(
            "SELECT attachment_id, original_name, stored_path, mime_type,
                    size_bytes, created_at
               FROM {$table}
              WHERE conversation_id = ?
              ORDER BY created_at ASC",
            [$cid]
        );
        $base = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        foreach ($rows as &$r) {
            $r['url'] = rtrim($base, '/') . '/' . ltrim((string) $r['stored_path'], '/');
        }
        return $rows;
    }

    public function getBackUrl(): string
    {
        return $this->backendUrl->getUrl('claudeai/conversation/index');
    }

    public function formatCost(float $usd): string
    {
        return $this->pricing->format($usd);
    }

    /**
     * Decode the raw JSON we stored in MessageStore::record() back into
     * a normalised array of blocks the template can iterate over.
     *
     * @return array<int,array<string,mixed>>
     */
    private function decodeBlocks(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [['type' => 'text', 'text' => $json]];
        }
        // record() stores either a single block (associative with 'type') or an array of blocks.
        if (isset($decoded['type'])) {
            return [$decoded];
        }
        $out = [];
        foreach ($decoded as $b) {
            if (is_array($b)) {
                $out[] = $b;
            }
        }
        return $out;
    }

    public function imageDataUri(array $block): string
    {
        $src = $block['source'] ?? [];
        $type = (string) ($src['media_type'] ?? 'image/png');
        $data = (string) ($src['data'] ?? '');
        if ($data === '') {
            return '';
        }
        return 'data:' . $type . ';base64,' . $data;
    }

    public function prettyJson($val): string
    {
        if (is_string($val)) {
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                $val = $decoded;
            } else {
                return $val;
            }
        }
        return (string) json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
