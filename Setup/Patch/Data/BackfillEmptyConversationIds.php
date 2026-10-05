<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class BackfillEmptyConversationIds implements DataPatchInterface
{
    public function __construct(private readonly ResourceConnection $resource) {}

    public static function getDependencies(): array { return []; }
    public function getAliases(): array { return []; }

    public function apply(): self
    {
        $conn = $this->resource->getConnection();
        foreach (['panth_claudeai_message', 'panth_claudeai_activity'] as $tableName) {
            $table = $this->resource->getTableName($tableName);
            if (!$conn->isTableExists($table)) {
                continue;
            }
            $rows = $conn->fetchAll(
                "SELECT DISTINCT IFNULL(admin_user_id, 0) AS uid
                   FROM {$table}
                  WHERE conversation_id IS NULL OR conversation_id = ''"
            );
            foreach ($rows as $r) {
                $cid = 'legacy_' . bin2hex(random_bytes(6));
                $conn->update(
                    $table,
                    ['conversation_id' => $cid],
                    [
                        '(conversation_id IS NULL OR conversation_id = "")',
                        'IFNULL(admin_user_id, 0) = ?' => (int) $r['uid'],
                    ]
                );
            }
        }
        return $this;
    }
}
