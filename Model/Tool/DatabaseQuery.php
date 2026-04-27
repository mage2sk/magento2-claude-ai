<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Framework\App\ResourceConnection;
use Panth\ClaudeAi\Model\Config;

/**
 * Read-only escape hatch — runs a SELECT against the Magento DB so the AI
 * can answer questions about ANY table (core or third-party) without a
 * dedicated tool. Hard rejects every mutating keyword. Result rows capped
 * to keep the response bounded.
 *
 * This is what unlocks "do depth checks on third-party modules" — the AI
 * can read their tables directly, then reason about what to do next using
 * the higher-level write tools (manage_products, manage_cms_pages, etc.).
 *
 * Why this is safe-by-default:
 *   - Statement is split on `;` and only the first chunk is allowed.
 *   - Reject list is exhaustive (INSERT/UPDATE/DELETE/DROP/ALTER/RENAME/
 *     TRUNCATE/CREATE/GRANT/REVOKE/REPLACE/CALL/HANDLER/LOAD/LOCK/EXECUTE).
 *   - Comments stripped before keyword inspection so a forbidden keyword
 *     hidden inside a /* ... *\/ block can't sneak through.
 *   - Hard LIMIT injected if the query doesn't already have one.
 *   - PDO runs the query unprepared (no params from AI input) so we never
 *     bind anything Claude wrote — the SQL itself is the input.
 */
class DatabaseQuery implements ToolInterface
{
    private const MAX_ROWS = 100;
    private const FORBIDDEN = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE',
        'DROP', 'ALTER', 'CREATE', 'RENAME', 'TRUNCATE',
        'GRANT', 'REVOKE',
        'CALL', 'HANDLER', 'LOAD', 'LOCK', 'UNLOCK',
        'EXECUTE', 'PREPARE', 'DEALLOCATE',
        'SET', 'RESET', 'FLUSH', 'KILL', 'OPTIMIZE',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    public function name(): string { return 'database_query'; }

    public function definition(): array
    {
        return [
            'name' => 'database_query',
            'description' =>
                'Run a SELECT against the Magento database for read-only inspection. Use this when no dedicated tool covers a question (e.g. "how many rows in <third-party table>", "what columns does <table> have", "which entries are most recent"). MySQL syntax. SELECT only — INSERT / UPDATE / DELETE / DROP / ALTER / TRUNCATE etc. are rejected. Returns up to 100 rows. To inspect a table\'s columns: SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "x".',
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

            $cleaned = $this->stripComments($sql);
            $firstStmt = trim(explode(';', $cleaned)[0] ?? '');
            if ($firstStmt === '') {
                return ['status' => 'error', 'message' => 'No SQL statement found.'];
            }

            // Must start with SELECT (or WITH / SHOW for inspection-friendly queries).
            $firstWord = strtoupper((string) (preg_split('/\s+/', $firstStmt, 2)[0] ?? ''));
            if (!in_array($firstWord, ['SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
                return ['status' => 'error', 'message' => "Only SELECT/WITH/SHOW/DESCRIBE/EXPLAIN allowed; got '{$firstWord}'."];
            }

            $upper = strtoupper(' ' . $firstStmt . ' ');
            foreach (self::FORBIDDEN as $kw) {
                if (str_contains($upper, ' ' . $kw . ' ')) {
                    return ['status' => 'error', 'message' => "Statement contains forbidden keyword: {$kw}"];
                }
            }

            // Inject LIMIT if the query doesn't already have one (best-effort).
            $limit = min(self::MAX_ROWS, max(1, (int) ($input['limit'] ?? self::MAX_ROWS)));
            if ($firstWord === 'SELECT' && stripos($firstStmt, ' LIMIT ') === false) {
                $firstStmt .= ' LIMIT ' . $limit;
            }

            $conn = $this->resource->getConnection();
            $rows = $conn->fetchAll($firstStmt);

            // Truncate any oversized cell so the response stays bounded —
            // 1 KB per cell, 100 rows max = ~100 KB upper bound.
            foreach ($rows as &$r) {
                foreach ($r as $k => $v) {
                    if (is_string($v) && strlen($v) > 1024) {
                        $r[$k] = substr($v, 0, 1024) . '… [truncated]';
                    }
                }
            }
            return [
                'status'         => 'success',
                'affected_count' => count($rows),
                'rows'           => $rows,
                'columns'        => $rows ? array_keys($rows[0]) : [],
                'sql_run'        => $firstStmt,
                'summary'        => sprintf('%d row(s) returned.', count($rows)),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function stripComments(string $sql): string
    {
        // Remove /* ... */ block comments and -- line comments before keyword scan.
        $sql = preg_replace('!/\*.*?\*/!s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--[^\r\n]*/m', ' ', $sql) ?? $sql;
        return $sql;
    }
}
