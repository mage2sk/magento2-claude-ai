<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Panth\ClaudeAi\Model\QueryGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueryGuardTest extends TestCase
{
    private const HINT = 'SELECT /*+ MAX_EXECUTION_TIME(15000) */';

    private const DERIVED = "(SELECT * FROM core_config_data WHERE path NOT LIKE 'payment/%'"
        . " AND LOWER(path) NOT REGEXP 'password|passwd|key|secret|token')";

    private QueryGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new QueryGuard();
    }

    public function testPlainSelectGetsHintAndLimit(): void
    {
        $result = $this->guard->prepare('SELECT sku FROM catalog_product_entity', 50);

        $this->assertSame(self::HINT . ' sku FROM catalog_product_entity LIMIT 50', $result['sql']);
        $this->assertFalse($result['customer_grid']);
    }

    #[DataProvider('limitProvider')]
    public function testLimitIsClamped(string $sql, int $limit, string $expectedTail): void
    {
        $result = $this->guard->prepare($sql, $limit);
        $this->assertStringEndsWith($expectedTail, $result['sql']);
    }

    public static function limitProvider(): array
    {
        return [
            'appended limit capped at max rows' => ['SELECT 1', 5000, ' 1 LIMIT 100'],
            'appended limit floor of one' => ['SELECT 1', 0, ' 1 LIMIT 1'],
            'existing limit reduced' => ['SELECT * FROM t LIMIT 500', 20, 'FROM t LIMIT 20'],
            'existing smaller limit kept' => ['SELECT * FROM t LIMIT 5', 20, 'FROM t LIMIT 5'],
            'offset comma form' => ['SELECT * FROM t LIMIT 10, 500', 30, 'LIMIT 10, 30'],
            'offset keyword form' => ['SELECT * FROM t LIMIT 500 OFFSET 20', 30, 'LIMIT 30 OFFSET 20'],
        ];
    }

    public function testTrailingSemicolonIsStripped(): void
    {
        $this->assertSame(self::HINT . ' 1 LIMIT 100', $this->guard->prepare('SELECT 1;  ', 100)['sql']);
    }

    public function testStringLiteralContentIsNotInspected(): void
    {
        $sql = "SELECT title FROM cms_page WHERE title = 'admin_user; DROP -- x'";
        $result = $this->guard->prepare($sql, 10);

        $this->assertSame(
            self::HINT . " title FROM cms_page WHERE title = 'admin_user; DROP -- x' LIMIT 10",
            $result['sql']
        );
    }

    public function testColumnNamesContainingKeywordsAreAllowed(): void
    {
        $result = $this->guard->prepare('SELECT updated_at, created_at FROM sales_order', 10);
        $this->assertStringContainsString('updated_at, created_at', $result['sql']);
    }

    #[DataProvider('rejectedProvider')]
    public function testRejectedStatements(string $sql, string $message, string $prefix = ''): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->guard->prepare($sql, 10, $prefix);
    }

    public static function rejectedProvider(): array
    {
        return [
            'empty' => ['   ', 'sql is required.'],
            'two statements' => ['SELECT 1; SELECT 2', 'Only a single statement is allowed.'],
            'line comment' => ['SELECT 1 -- hi', 'SQL comments are not allowed.'],
            'block comment' => ['SELECT /* x */ 1', 'SQL comments are not allowed.'],
            'hash comment' => ['SELECT 1 # hi', 'SQL comments are not allowed.'],
            'user variable' => ['SELECT @a', 'Variables are not allowed.'],
            'assignment' => ['SELECT a := 1', 'Variables are not allowed.'],
            'not a select' => ['SHOW TABLES', 'Only a single SELECT statement is allowed.'],
            'update' => ['UPDATE t SET a = 1', 'Only a single SELECT statement is allowed.'],
            'sleep' => ['SELECT * FROM t WHERE SLEEP(5)', 'forbidden keyword: SLEEP'],
            'into outfile' => ["SELECT * FROM t INTO OUTFILE '/tmp/x'", 'forbidden keyword: INTO'],
            'union delete' => ['SELECT 1 UNION DELETE FROM t', 'forbidden keyword: DELETE'],
            'mysql schema' => ['SELECT * FROM mysql.user', 'System schemas are not queryable.'],
            'performance schema' => ['SELECT * FROM performance_schema . threads', 'System schemas are not queryable.'],
            'information schema routines' => [
                'SELECT * FROM information_schema.ROUTINES',
                'Only information_schema.TABLES, COLUMNS, STATISTICS and KEY_COLUMN_USAGE are queryable.',
            ],
            'bare information schema' => [
                'SELECT information_schema FROM t',
                'Only information_schema.TABLES',
            ],
            'admin table' => ['SELECT username FROM admin_user', "Table 'admin_user' is not queryable"],
            'admin session table' => ['SELECT * FROM admin_user_session', "Table 'admin_user_session' is not queryable"],
            'backticked admin table' => ['SELECT * FROM `admin_user`', "Table 'admin_user' is not queryable"],
            'prefixed admin table' => ['SELECT * FROM m2_admin_user', "Table 'admin_user' is not queryable", 'm2_'],
            'oauth pattern' => ['SELECT * FROM oauth_whatever', "Table 'oauth_whatever' is not queryable"],
            'authorization pattern' => ['SELECT * FROM authorization_role', "Table 'authorization_role' is not queryable"],
            'password column' => ['SELECT password_hash FROM customer_entity', "Column 'password_hash' is not queryable"],
            'token column' => ['SELECT rp_token FROM customer_entity', "Column 'rp_token' is not queryable"],
            'payment info column' => ['SELECT additional_information FROM sales_order_payment', "Column 'additional_information'"],
            'grid email' => ['SELECT email FROM customer_grid_flat', "Column 'email' of customer_grid_flat is not queryable"],
            'grid phone' => ['SELECT billing_telephone FROM customer_grid_flat', "Column 'billing_telephone' of customer_grid_flat"],
            'schema qualified config' => ['SELECT * FROM magento.core_config_data', 'Reference core_config_data without a schema name.'],
            'unterminated literal' => ["SELECT 'abc", 'Unterminated quoted string or identifier.'],
            'unterminated identifier' => ['SELECT `abc', 'Unterminated quoted string or identifier.'],
            'odd quoted identifier' => ['SELECT `a b` FROM t', 'Quoted identifiers may only contain letters, digits, _ and $.'],
        ];
    }

    public function testAllowedInformationSchemaTable(): void
    {
        $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME = 'x'";
        $this->assertStringStartsWith(self::HINT, $this->guard->prepare($sql, 10)['sql']);
    }

    public function testCustomerGridWithoutPrivateColumnsIsFlagged(): void
    {
        $result = $this->guard->prepare('SELECT entity_id, name FROM customer_grid_flat', 10);
        $this->assertTrue($result['customer_grid']);
    }

    public function testPrefixedCustomerGridIsDetected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->guard->prepare('SELECT email FROM pre_customer_grid_flat', 10, 'pre_');
    }

    public function testConfigTableIsWrappedInFilteredDerivedTable(): void
    {
        $result = $this->guard->prepare('SELECT path, value FROM core_config_data WHERE scope_id = 0', 10);

        $this->assertSame(
            self::HINT . ' path, value FROM ' . self::DERIVED . ' AS `core_config_data` WHERE scope_id = 0 LIMIT 10',
            $result['sql']
        );
    }

    #[DataProvider('aliasProvider')]
    public function testConfigTableAliasIsPreserved(string $sql, string $expectedFragment): void
    {
        $this->assertStringContainsString($expectedFragment, $this->guard->prepare($sql, 10)['sql']);
    }

    public static function aliasProvider(): array
    {
        return [
            'bare alias' => ['SELECT c.value FROM core_config_data c', self::DERIVED . ' AS `c`'],
            'as alias' => ['SELECT cfg.value FROM core_config_data AS cfg', self::DERIVED . ' AS `cfg`'],
            'no alias' => ['SELECT value FROM core_config_data', self::DERIVED . ' AS `core_config_data`'],
        ];
    }

    public function testConfigTableWithLiteralFilterKeepsLiteral(): void
    {
        $sql = "SELECT value FROM core_config_data WHERE path = 'web/unsecure/base_url'";
        $result = $this->guard->prepare($sql, 10);

        $this->assertStringContainsString(
            ' AS `core_config_data` WHERE path = ' . "'web/unsecure/base_url'",
            $result['sql']
        );
    }

    public function testPrefixedConfigTableIsWrapped(): void
    {
        $result = $this->guard->prepare('SELECT value FROM m2_core_config_data', 10, 'm2_');
        $this->assertStringContainsString('(SELECT * FROM m2_core_config_data WHERE', $result['sql']);
        $this->assertStringContainsString('AS `m2_core_config_data`', $result['sql']);
    }

    public function testRedactRowsHidesSecrets(): void
    {
        $long = str_repeat('a', 1100);
        $rows = [
            [
                'sku' => 'MH01',
                'password_hash' => 'whatever',
                'api_key' => 'x',
                'bcrypt' => '$2y$10$abcdefghijklmnopqrstuv',
                'argon' => '$argon2id$v=19$m=65536',
                'encrypted' => '0:3:ABCDEFGHIJKLMNOPQRSTUVWX',
                'body' => $long,
                'email' => 'a@example.com',
                'qty' => 5,
            ],
            'not-a-row',
        ];

        $out = $this->guard->redactRows($rows);

        $this->assertSame('MH01', $out[0]['sku']);
        $this->assertSame('[redacted]', $out[0]['password_hash']);
        $this->assertSame('[redacted]', $out[0]['api_key']);
        $this->assertSame('[redacted]', $out[0]['bcrypt']);
        $this->assertSame('[redacted]', $out[0]['argon']);
        $this->assertSame('[redacted]', $out[0]['encrypted']);
        $this->assertSame(str_repeat('a', 1024) . '... [truncated]', $out[0]['body']);
        $this->assertSame('a@example.com', $out[0]['email']);
        $this->assertSame(5, $out[0]['qty']);
        $this->assertSame('not-a-row', $out[1]);
    }

    public function testRedactRowsHidesGridPrivateColumnsOnlyForGrid(): void
    {
        $rows = [['email' => 'a@example.com', 'billing_full' => 'Street 1', 'name' => 'Ann']];

        $grid = $this->guard->redactRows($rows, true);
        $this->assertSame('[redacted]', $grid[0]['email']);
        $this->assertSame('[redacted]', $grid[0]['billing_full']);
        $this->assertSame('Ann', $grid[0]['name']);

        $plain = $this->guard->redactRows($rows, false);
        $this->assertSame('a@example.com', $plain[0]['email']);
    }
}
