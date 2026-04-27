<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\ToolRegistry;

class Chat extends Template
{
    protected $_template = 'Panth_ClaudeAi::chat.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ToolRegistry $tools,
        private readonly ResourceConnection $resource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('claudeai/chat/send');
    }

    public function getUploadUrl(): string
    {
        return $this->getUrl('claudeai/chat/upload');
    }

    public function getLoadUrl(): string
    {
        return $this->getUrl('claudeai/chat/load');
    }

    /**
     * Recent conversations for the resume sidebar — one entry per conversation.
     *
     * @return array<int,array{conversation_id:string,started_at:string,last_at:string,turns:int,preview:string}>
     */
    public function getRecentConversations(int $limit = 30): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_message');
        if (!$conn->isTableExists($table)) {
            return [];
        }
        $rows = $conn->fetchAll(
            "SELECT conversation_id,
                    MIN(created_at) AS started_at,
                    MAX(created_at) AS last_at,
                    COUNT(*)        AS turns
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
        $previewRows = $conn->fetchAll(
            "SELECT conversation_id, content_json
               FROM {$table}
              WHERE role = 'user' AND conversation_id IN ({$place})
              ORDER BY conversation_id, sequence ASC",
            $cids
        );
        $previews = [];
        foreach ($previewRows as $p) {
            $cid = (string) $p['conversation_id'];
            if (isset($previews[$cid])) {
                continue;
            }
            $decoded = json_decode((string) $p['content_json'], true);
            if (!is_array($decoded)) {
                $previews[$cid] = '';
                continue;
            }
            if (isset($decoded['type'])) {
                $decoded = [$decoded];
            }
            foreach ($decoded as $b) {
                if (is_array($b) && ($b['type'] ?? '') === 'text' && !empty($b['text'])) {
                    $previews[$cid] = mb_strimwidth((string) $b['text'], 0, 60, '…');
                    break;
                }
            }
            $previews[$cid] = $previews[$cid] ?? '';
        }
        $out = [];
        foreach ($rows as $r) {
            $cid = (string) $r['conversation_id'];
            $out[] = [
                'conversation_id' => $cid,
                'started_at'      => (string) $r['started_at'],
                'last_at'         => (string) $r['last_at'],
                'turns'           => (int) $r['turns'],
                'preview'         => $previews[$cid] ?? '',
            ];
        }
        return $out;
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'panth_claudeai']);
    }

    public function getFormKey(): string
    {
        return (string) $this->formKey->getFormKey();
    }

    public function isConfigured(): bool
    {
        return $this->config->getApiKey() !== '' && $this->config->isEnabled();
    }

    public function getModel(): string
    {
        return $this->config->getModel();
    }

    public function isDryRun(): bool
    {
        return $this->config->isDryRun();
    }

    /** @return array<int, array{name:string,description:string}> */
    public function getToolSummaries(): array
    {
        $list = [];
        foreach ($this->tools->all() as $tool) {
            $def = $tool->definition();
            $list[] = [
                'name' => $def['name'] ?? $tool->name(),
                'description' => $def['description'] ?? '',
            ];
        }
        return $list;
    }

    /** @return array<int, string> Suggested prompts shown as quick-start chips */
    public function getSuggestions(): array
    {
        return [
            'How many customers do we have?',
            'Show me the 10 most recent orders',
            'Find all products with "Hoodie" in the name',
            'Update the price of all products matching MH% to $39.99',
            'List products with stock at or below 3 units',
        ];
    }
}
