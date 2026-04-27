<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Backfill missing conversation_id values in panth_claudeai_message and
 * panth_claudeai_activity. Pre-1.3.1, the chat JS sent an empty string on
 * the very first turn and the controller's `??` fallback didn't catch it
 * — so every chat went in with an empty conversation_id, making the
 * transcript View page unreachable. After this patch every legacy row
 * has a synthetic ID so the new Conversations + View pages can find them.
 *
 * Strategy: rows with empty cid are grouped by admin_user_id (best signal
 * we have) and stamped with one fresh ID per admin. If admin_user_id is
 * also missing, we fall back to a single bucket. Idempotent — re-runs
 * are no-ops once cids are filled.
 */
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
