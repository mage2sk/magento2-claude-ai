<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Framework\App\ResourceConnection;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\QueryGuard;

class DatabaseQuery implements ToolInterface
{
    private const MAX_ROWS = QueryGuard::MAX_ROWS;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly QueryGuard $guard
    ) {
    }

    public function name(): string { return 'database_query'; }

    public function definition(): array
    {
        return [
            'name' => 'database_query',
            'description' =>
                'Run one read-only SELECT against the Magento database when no dedicated tool covers a question (e.g. "how many rows in <third-party table>", "which entries are most recent", catalog and sales analytics). MySQL syntax. Only a single SELECT is accepted: no comments, no variables, no INTO/OUTFILE, no SLEEP/BENCHMARK, no second statement. Tables holding credentials or access data (admin users, ACL rules, OAuth, integrations, vault tokens) are blocked, columns such as password hashes and tokens are blocked or redacted, and core_config_data rows under payment/ or with password/key/secret/token in the path are hidden. Returns up to 100 rows. To inspect a table\'s columns: SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "x".',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sql'   => ['type' => 'string', 'description' => 'A single SELECT statement. No trailing semicolon required.'],
                    'limit' => ['type' => 'integer', 'description' => 'Max rows (default 100, capped at 100).'],
                ],
                'required' => ['sql'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $sql = trim((string) ($input['sql'] ?? ''));
            if ($sql === '') {
                return ['status' => 'error', 'message' => 'sql is required.'];
            }

            $limit = min(self::MAX_ROWS, max(1, (int) ($input['limit'] ?? self::MAX_ROWS)));
            $prefix = (string) substr(
                $this->resource->getTableName('core_config_data'),
                0,
                -strlen('core_config_data')
            );

            try {
                $prepared = $this->guard->prepare($sql, $limit, $prefix);
            } catch (\InvalidArgumentException $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }

            $conn = $this->resource->getConnection();
            $rows = $conn->fetchAll($prepared['sql']);
            $rows = array_slice($rows, 0, $limit);
            $rows = $this->guard->redactRows($rows, (bool) $prepared['customer_grid']);

            return [
                'status'         => 'success',
                'affected_count' => count($rows),
                'rows'           => $rows,
                'columns'        => $rows ? array_keys($rows[0]) : [],
                'sql_run'        => $prepared['sql'],
                'summary'        => sprintf('%d row(s) returned.', count($rows)),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
