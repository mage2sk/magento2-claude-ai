<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

class QueryGuard
{
    public const MAX_ROWS = 100;

    private const MAX_EXECUTION_MS = 15000;

    private const BLOCKED_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'UPSERT',
        'DROP', 'ALTER', 'CREATE', 'RENAME', 'TRUNCATE',
        'GRANT', 'REVOKE', 'CALL', 'HANDLER', 'LOAD', 'LOCK', 'UNLOCK',
        'EXECUTE', 'PREPARE', 'DEALLOCATE', 'SET', 'RESET', 'FLUSH', 'KILL',
        'OPTIMIZE', 'ANALYZE', 'SHUTDOWN', 'INSTALL', 'UNINSTALL', 'DO', 'PROCEDURE',
        'INTO', 'OUTFILE', 'DUMPFILE', 'INFILE', 'LOAD_FILE', 'SHARE',
        'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS',
        'IS_FREE_LOCK', 'IS_USED_LOCK', 'MASTER_POS_WAIT', 'SOURCE_POS_WAIT',
        'WAIT_FOR_EXECUTED_GTID_SET', 'WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS',
        'SYS_EXEC', 'SYS_EVAL', 'SYS_GET', 'SYS_SET',
    ];

    private const BLOCKED_TABLES = [
        'admin_user', 'admin_user_session', 'admin_user_expiration', 'admin_passwords',
        'admin_adobe_ims_webapi', 'authorization_role', 'authorization_rule',
        'oauth_consumer', 'oauth_token', 'oauth_nonce', 'oauth_token_request_log',
        'integration', 'vault_payment_token', 'vault_payment_token_order_payment_link',
        'jwt_auth_revoked', 'password_reset_request_event', 'login_as_customer',
        'login_as_customer_assistance_allowed',
    ];

    private const BLOCKED_TABLE_PATTERNS = [
        'authorization_\w+', 'oauth_\w+', 'vault_payment_token\w*', 'admin_passwords\w*',
    ];

    private const SENSITIVE_IDENTIFIER =
        '/\b\w*(password|passwd|secret|token|api_?key|private_?key|cc_number|cc_cid|cc_ss_|additional_information)\w*\b/i';

    private const SENSITIVE_COLUMN =
        '/(password|passwd|secret|token|api_?key|private_?key|cc_number|cc_cid|cc_ss_|additional_information)/i';

    private const GRID_PRIVATE_IDENTIFIER =
        '/\b(email|telephone|billing_telephone|shipping_telephone|billing_full|shipping_full|billing_street)\b/i';

    private const GRID_PRIVATE_COLUMN = '/(email|telephone|billing_full|shipping_full|billing_street)/i';

    private const ALLOWED_INFORMATION_SCHEMA = ['TABLES', 'COLUMNS', 'STATISTICS', 'KEY_COLUMN_USAGE'];

    private const NON_ALIAS_WORDS = [
        'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'NATURAL', 'STRAIGHT_JOIN',
        'ON', 'USING', 'GROUP', 'ORDER', 'LIMIT', 'UNION', 'HAVING', 'WINDOW', 'FOR', 'USE',
        'FORCE', 'IGNORE', 'PARTITION', 'EXCEPT', 'INTERSECT',
    ];

    public function prepare(string $sql, int $limit, string $tablePrefix = ''): array
    {
        $limit = min(self::MAX_ROWS, max(1, $limit));
        $sql = trim($sql);
        if ($sql === '') {
            throw new \InvalidArgumentException('sql is required.');
        }

        $segments = $this->tokenize($sql);
        $analysis = $this->analysisText($segments);

        $trimmedAnalysis = rtrim($analysis);
        if (str_ends_with($trimmedAnalysis, ';')) {
            $sql = rtrim(rtrim($sql), ';');
            $segments = $this->tokenize($sql);
            $analysis = $this->analysisText($segments);
        }

        if (str_contains($analysis, ';')) {
            throw new \InvalidArgumentException('Only a single statement is allowed.');
        }
        if (str_contains($analysis, '/*') || str_contains($analysis, '--') || str_contains($analysis, '#')) {
            throw new \InvalidArgumentException('SQL comments are not allowed.');
        }
        if (str_contains($analysis, '@') || str_contains($analysis, ':=')) {
            throw new \InvalidArgumentException('Variables are not allowed.');
        }
        if (preg_match('/^\s*SELECT\b/i', $analysis) !== 1) {
            throw new \InvalidArgumentException('Only a single SELECT statement is allowed.');
        }

        foreach (self::BLOCKED_KEYWORDS as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $analysis) === 1) {
                throw new \InvalidArgumentException("Statement contains a forbidden keyword: {$kw}");
            }
        }

        if (preg_match('/\b(mysql|performance_schema|sys)\s*\./i', $analysis) === 1) {
            throw new \InvalidArgumentException('System schemas are not queryable.');
        }
        if (preg_match_all('/\binformation_schema\b(\s*\.\s*(\w+))?/i', $analysis, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $table = strtoupper((string) ($hit[2] ?? ''));
                if (!in_array($table, self::ALLOWED_INFORMATION_SCHEMA, true)) {
                    throw new \InvalidArgumentException(
                        'Only information_schema.TABLES, COLUMNS, STATISTICS and KEY_COLUMN_USAGE are queryable.'
                    );
                }
            }
        }

        $prefix = preg_quote($tablePrefix, '/');
        $tables = implode('|', array_merge(
            array_map(static fn(string $t) => preg_quote($t, '/'), self::BLOCKED_TABLES),
            self::BLOCKED_TABLE_PATTERNS
        ));
        if (preg_match('/\b(?:' . $prefix . ')?(' . $tables . ')\b/i', $analysis, $hit) === 1) {
            throw new \InvalidArgumentException("Table '{$hit[1]}' is not queryable: it holds credentials or access data.");
        }
        if (preg_match(self::SENSITIVE_IDENTIFIER, $analysis, $hit) === 1) {
            throw new \InvalidArgumentException("Column '{$hit[0]}' is not queryable: it holds secrets or credentials.");
        }

        $gridTable = $tablePrefix . 'customer_grid_flat';
        $usesGrid = preg_match('/\b' . preg_quote($gridTable, '/') . '\b/i', $analysis) === 1;
        if ($usesGrid && preg_match(self::GRID_PRIVATE_IDENTIFIER, $analysis, $hit) === 1) {
            throw new \InvalidArgumentException(
                "Column '{$hit[1]}' of customer_grid_flat is not queryable. Use the customers tool for customer lookups."
            );
        }

        $configTable = $tablePrefix . 'core_config_data';
        if (preg_match('/\.\s*`?' . preg_quote($configTable, '/') . '`?(?![\w$])/i', $analysis) === 1) {
            throw new \InvalidArgumentException('Reference core_config_data without a schema name.');
        }

        $out = $this->rewriteConfigTable($segments, $configTable);

        $out = preg_replace('/^\s*SELECT\b/i', 'SELECT /*+ MAX_EXECUTION_TIME(' . self::MAX_EXECUTION_MS . ') */', $out, 1)
            ?? $out;

        $limitRe = '/\bLIMIT\s+(\d+)(?:\s*,\s*(\d+)|\s+OFFSET\s+(\d+))?\s*$/i';
        if (preg_match($limitRe, $analysis) === 1 && preg_match($limitRe, $out) === 1) {
            $out = (string) preg_replace_callback($limitRe, static function (array $m) use ($limit): string {
                if (isset($m[2]) && $m[2] !== '') {
                    return 'LIMIT ' . (int) $m[1] . ', ' . min($limit, (int) $m[2]);
                }
                if (isset($m[3]) && $m[3] !== '') {
                    return 'LIMIT ' . min($limit, (int) $m[1]) . ' OFFSET ' . (int) $m[3];
                }
                return 'LIMIT ' . min($limit, (int) $m[1]);
            }, $out);
        } else {
            $out .= ' LIMIT ' . $limit;
        }

        return ['sql' => $out, 'customer_grid' => $usesGrid];
    }

    public function redactRows(array $rows, bool $customerGrid = false): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $col => $value) {
                $name = (string) $col;
                if (preg_match(self::SENSITIVE_COLUMN, $name) === 1
                    || ($customerGrid && preg_match(self::GRID_PRIVATE_COLUMN, $name) === 1)
                ) {
                    $row[$col] = '[redacted]';
                    continue;
                }
                if (is_string($value) && preg_match('/^\$(2[abxy]|argon2(id|i|d)|[156])\$/', $value) === 1) {
                    $row[$col] = '[redacted]';
                    continue;
                }
                if (is_string($value) && preg_match('/^\d+:\d+:[A-Za-z0-9+\/=]{16,}$/', $value) === 1) {
                    $row[$col] = '[redacted]';
                    continue;
                }
                if (is_string($value) && strlen($value) > 1024) {
                    $row[$col] = substr($value, 0, 1024) . '... [truncated]';
                }
            }
        }
        unset($row);
        return $rows;
    }

    private function tokenize(string $sql): array
    {
        $segments = [];
        $len = strlen($sql);
        $buf = '';
        $i = 0;
        while ($i < $len) {
            $ch = $sql[$i];
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                if ($buf !== '') {
                    $segments[] = ['code', $buf];
                    $buf = '';
                }
                $quote = $ch;
                $j = $i + 1;
                $closed = false;
                while ($j < $len) {
                    $c = $sql[$j];
                    if ($c === '\\' && $quote !== '`') {
                        $j += 2;
                        continue;
                    }
                    if ($c === $quote) {
                        if ($j + 1 < $len && $sql[$j + 1] === $quote) {
                            $j += 2;
                            continue;
                        }
                        $closed = true;
                        break;
                    }
                    $j++;
                }
                if (!$closed) {
                    throw new \InvalidArgumentException('Unterminated quoted string or identifier.');
                }
                $segments[] = [$quote === '`' ? 'ident' : 'literal', substr($sql, $i, $j - $i + 1)];
                $i = $j + 1;
                continue;
            }
            $buf .= $ch;
            $i++;
        }
        if ($buf !== '') {
            $segments[] = ['code', $buf];
        }
        return $segments;
    }

    private function analysisText(array $segments): string
    {
        $out = '';
        foreach ($segments as [$type, $text]) {
            if ($type === 'literal') {
                $out .= "''";
            } elseif ($type === 'ident') {
                $name = substr($text, 1, -1);
                if (preg_match('/^[A-Za-z0-9_$]+$/', $name) !== 1) {
                    throw new \InvalidArgumentException('Quoted identifiers may only contain letters, digits, _ and $.');
                }
                $out .= $name;
            } else {
                $out .= $text;
            }
        }
        return $out;
    }

    private function rewriteConfigTable(array $segments, string $configTable): string
    {
        $derived = sprintf(
            "(SELECT * FROM %s WHERE path NOT LIKE 'payment/%%'"
            . " AND LOWER(path) NOT REGEXP 'password|passwd|key|secret|token')",
            $configTable
        );
        $pattern = '/(?<![\w$`.])(`?' . preg_quote($configTable, '/') . '`?)(?![\w$`])(?!\s*\.)'
            . '((\s+AS)?\s+`?([A-Za-z_][\w$]*)`?)?/i';

        $out = '';
        $run = '';
        $flush = function () use (&$run, &$out, $pattern, $derived, $configTable): void {
            if ($run === '') {
                return;
            }
            $out .= (string) preg_replace_callback(
                $pattern,
                function (array $m) use ($derived, $configTable): string {
                    $alias = (string) ($m[4] ?? '');
                    $hasAs = ($m[3] ?? '') !== '';
                    if ($alias !== '' && ($hasAs || !in_array(strtoupper($alias), self::NON_ALIAS_WORDS, true))) {
                        return $derived . ' AS `' . $alias . '`';
                    }
                    return $derived . ' AS `' . $configTable . '`' . (string) ($m[2] ?? '');
                },
                $run
            );
            $run = '';
        };

        foreach ($segments as [$type, $text]) {
            if ($type === 'literal') {
                $flush();
                $out .= $text;
            } else {
                $run .= $text;
            }
        }
        $flush();
        return $out;
    }
}
