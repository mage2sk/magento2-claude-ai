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

class View extends Template
{
    protected $_template = 'Panth_ClaudeAi::conversation/view.phtml';

    public const PAGE_SIZE = 20;

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
        $req = $this->getRequest();
        $cid = (string) $req->getParam('cid', '');
        if ($cid === '') {
            $cid = (string) $req->getParam('id', '');
        }
        return $cid;
    }

    public function getTotalMessages(): int
    {
        $cid = $this->getConversationId();
        if ($cid === '') {
            return 0;
        }
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_message');
        if (!$conn->isTableExists($table)) {
            return 0;
        }
        return (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE conversation_id = ?",
            [$cid]
        );
    }

    public function getPageSize(): int { return self::PAGE_SIZE; }

    public function getTotalPages(): int
    {
        $total = $this->getTotalMessages();
        return $total > 0 ? (int) ceil($total / self::PAGE_SIZE) : 1;
    }

    public function getCurrentPage(): int
    {
        $totalPages = $this->getTotalPages();
        $req = (int) $this->getRequest()->getParam('p', 0);
        if ($req <= 0) {
            return $totalPages;
        }
        return max(1, min($req, $totalPages));
    }

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
        $page  = $this->getCurrentPage();
        $size  = self::PAGE_SIZE;
        $offset = max(0, ($page - 1) * $size);
        $rows = $conn->fetchAll(
            "SELECT message_id, sequence, role, surface, content_json,
                    input_tokens, output_tokens, cache_read_tokens, cost_usd,
                    model, created_at
               FROM {$table}
              WHERE conversation_id = ?
              ORDER BY sequence ASC, message_id ASC
              LIMIT {$size} OFFSET {$offset}",
            [$cid]
        );
        foreach ($rows as &$r) {
            $r['blocks'] = $this->decodeBlocks((string) $r['content_json']);
        }
        return $rows;
    }

    public function getPageUrl(int $page): string
    {
        return $this->backendUrl->getUrl(
            'claudeai/conversation/view',
            ['cid' => $this->getConversationId(), 'p' => $page]
        );
    }

    public function getPaginationLinks(): array
    {
        $current = $this->getCurrentPage();
        $total   = $this->getTotalPages();
        if ($total <= 1) {
            return [];
        }
        if ($total <= 7) {
            return range(1, $total);
        }
        $links = [1];
        if ($current > 3) {
            $links[] = '...';
        }
        $start = max(2, $current - 1);
        $end   = min($total - 1, $current + 1);
        for ($i = $start; $i <= $end; $i++) {
            $links[] = $i;
        }
        if ($current < $total - 2) {
            $links[] = '...';
        }
        $links[] = $total;
        return $links;
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

    private function decodeBlocks(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [['type' => 'text', 'text' => $json]];
        }

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
