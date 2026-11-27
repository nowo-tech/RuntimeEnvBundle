<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\RuntimeSetting\Provider;

use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\InvalidReadOnlyQuery;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\ReadOnlyQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

final class ReadOnlyQueryTest extends TestCase
{
    public function testAcceptsAParameterisedSelect(): void
    {
        $query = new ReadOnlyQuery(
            'tenant',
            "SELECT \"value\", prev, prev_until FROM `tenant settings` t JOIN tenants x ON x.id = t.tenant_id\n\tWHERE t.name = :key AND x.code = :tenant AND t.ns = :namespace AND t.active = :active",
            ['tenant' => 'acme', 'active' => true],
            'value',
            'prev',
            'prev_until',
            1024,
        );

        self::assertSame(['active', 'key', 'namespace', 'tenant'], $query->usedParameters);
        self::assertSame('tenant', $query->provider);
        self::assertSame(['tenant' => 'acme', 'active' => true], $query->parameters);
        self::assertSame('prev', $query->previousColumn);
        self::assertSame('prev_until', $query->previousExpiresColumn);
        self::assertSame(1024, $query->maxLength);
    }

    public function testDefaults(): void
    {
        $query = new ReadOnlyQuery('p', '  select value from settings where id = :id');

        self::assertSame(['id'], $query->usedParameters);
        self::assertSame('value', $query->valueColumn);
        self::assertNull($query->previousColumn);
        self::assertSame(65536, $query->maxLength);
    }

    public function testFamilyNamesInsideQuotedIdentifiersAndLookAlikesAreAllowed(): void
    {
        $query = new ReadOnlyQuery('p', 'SELECT "lo_value" AS value, "lo_count", `sleep` + 1, sleeping, flower, sp FROM t WHERE "pg_sleep" = :key AND x IN ("lo_count", 1)');

        self::assertSame(['key'], $query->usedParameters);
    }

    public function testKeywordsInsideQuotedIdentifiersAreAllowed(): void
    {
        $query = new ReadOnlyQuery('p', 'SELECT "update" AS value FROM "set" WHERE "lock" = :key');

        self::assertSame(['key'], $query->usedParameters);
    }

    /**
     * @return iterable<string, array{string, string, 2?: array<string, mixed>}>
     */
    public static function refusedQueries(): iterable
    {
        yield 'empty' => ['', '1 to 4096 bytes'];
        yield 'too long' => ['SELECT value FROM t WHERE id = :id ' . str_repeat(' ', 4096), '1 to 4096 bytes'];
        yield 'control character' => ["SELECT value FROM t WHERE id = :id\x00", 'control characters'];
        yield 'invalid UTF-8' => ["SELECT value FROM t WHERE id = :id \xC3", 'valid UTF-8'];
        yield 'two statements' => ['SELECT value FROM t WHERE id = :id; DELETE FROM t', '";"'];
        yield 'line comment' => ['SELECT value FROM t WHERE id = :id -- x', 'a comment'];
        yield 'hash comment' => ['SELECT value FROM t WHERE id = :id # x', 'a comment'];
        yield 'block comment' => ['SELECT value /* x */ FROM t WHERE id = :id', 'a comment'];
        yield 'block comment end' => ['SELECT value */ FROM t WHERE id = :id', 'a comment'];
        yield 'string literal' => ["SELECT value FROM t WHERE id = :id AND kind = 'x'", 'string literal'];
        yield 'dollar quote' => ['SELECT $$x$$ AS value FROM t WHERE id = :id', 'dollar'];
        yield 'backslash' => ['SELECT value FROM t WHERE id = :id AND x = \\N', 'backslash'];
        yield 'positional' => ['SELECT value FROM t WHERE id = ?', 'positional'];
        yield 'cast' => ['SELECT value::text AS value FROM t WHERE id = :id', '"::" cast'];
        yield 'percent' => ['SELECT value FROM %table% WHERE id = :id', 'a "%"'];
        yield 'not a select' => ['WITH x AS (SELECT 1) SELECT value FROM x WHERE id = :id', 'single SELECT'];
        yield 'selected' => ['SELECTED value FROM t WHERE id = :id', 'single SELECT'];
        yield 'into' => ['SELECT value INTO @v FROM t WHERE id = :id', 'keyword INTO'];
        yield 'for update' => ['SELECT value FROM t WHERE id = :id FOR UPDATE', 'keyword UPDATE'];
        yield 'for share' => ['SELECT value FROM t WHERE id = :id FOR share', 'keyword share'];
        yield 'function pg_sleep' => ['SELECT pg_sleep(10) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_sleep_for' => ['SELECT pg_sleep_for(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_sleep_until' => ['SELECT PG_SLEEP_UNTIL(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function lo_from_bytea' => ['SELECT lo_from_bytea(0, x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function lo_put' => ['SELECT lo_put(1, 0, x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function lo_unlink' => ['SELECT lo_unlink(1) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function lo_import' => ['SELECT lo_import(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_try_advisory_lock' => ['SELECT pg_try_advisory_lock(42) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_advisory_lock_shared' => ['SELECT pg_advisory_lock_shared(42) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_advisory_unlock_all' => ['SELECT pg_advisory_unlock_all() AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function dblink_exec' => ['SELECT dblink_exec(x, y) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function dblink_connect' => ['SELECT dblink_connect(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_ls_dir' => ['SELECT pg_ls_dir(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_stat_file' => ['SELECT pg_stat_file(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_read_binary_file' => ['SELECT pg_read_binary_file(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_file_write' => ['SELECT pg_file_write(x, y, z) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_reload_conf' => ['SELECT pg_reload_conf() AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_rotate_logfile' => ['SELECT pg_rotate_logfile() AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_switch_wal' => ['SELECT pg_switch_wal() AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_logical_emit_message' => ['SELECT pg_logical_emit_message(x, y, z) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_create_restore_point' => ['SELECT pg_create_restore_point(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_drop_replication_slot' => ['SELECT pg_drop_replication_slot(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_terminate_backend' => ['SELECT pg_terminate_backend(1) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_notify' => ['SELECT pg_notify(x, y) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function set_config' => ['SELECT pg_catalog.set_config(x, y, z) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function query_to_xml' => ['SELECT query_to_xml(chr(115), true, true, x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function table_to_xmlschema' => ['SELECT table_to_xmlschema(x, true, true, y) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function txid_current' => ['SELECT txid_current() AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function pg_current_xact_id' => ['SELECT pg_current_xact_id() AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function nextval' => ['SELECT nextval(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function setval' => ['SELECT setval(x, 1) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function sleep' => ['SELECT SLEEP(1) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function benchmark' => ['SELECT BENCHMARK(1, x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function get_lock' => ['SELECT GET_LOCK(x, 1) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function release_lock' => ['SELECT RELEASE_LOCK(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function release_all_locks' => ['SELECT release_all_locks() AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function is_free_lock' => ['SELECT IS_FREE_LOCK(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function source_pos_wait' => ['SELECT source_pos_wait(x, y) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function master_pos_wait' => ['SELECT MASTER_POS_WAIT(x, y) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function wait_for_executed_gtid_set' => ['SELECT wait_for_executed_gtid_set(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function load_file' => ['SELECT LOAD_FILE(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function load_extension' => ['SELECT load_extension(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function xp_dirtree' => ['SELECT xp_dirtree(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function sp_executesql' => ['SELECT sp_executesql(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function dbms_lock' => ['SELECT dbms_lock.sleep(1) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function utl_http' => ['SELECT utl_http.request(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function openquery' => ['SELECT OPENQUERY(x, y) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function openrowset' => ['SELECT OPENROWSET(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'function waitfor' => ['SELECT WAITFOR(x) AS value FROM t WHERE id = :id', 'which can write, lock'];
        yield 'next value for' => ["SELECT NEXT  VALUE\nFOR seq AS value FROM t WHERE id = :id", 'uses NEXT  VALUE'];
        yield 'quoted call' => ['SELECT "lo_from_bytea"(0, x) AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'quoted advisory lock' => ['SELECT "pg_try_advisory_lock"(7) AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'schema-qualified quoted call' => ['SELECT pg_catalog."lo_from_bytea"(0, x) AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'unicode-escaped quoted call' => ['SELECT U&"lo_from_bytea"(0, x) AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'quoted call with a space' => ['SELECT "lo_from_bytea" (0, x) AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'quoted call with a newline' => ["SELECT \"pg_sleep_for\"\n(x) AS value FROM t WHERE id = :id", 'quoted function names are not allowed'];
        yield 'quoted call with a non-breaking space' => ["SELECT \"pg_sleep\"\u{00A0}(1) AS value FROM t WHERE id = :id", 'quoted function names are not allowed'];
        yield 'backtick release_all_locks' => ['SELECT `release_all_locks`() AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'backtick sleep' => ['SELECT `sleep`(0) AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'backtick get_lock' => ['SELECT `get_lock`(x, 1) AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'bracketed call' => ['SELECT [xp_dirtree](x) AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'quoted harmless-looking call' => ['SELECT "upper"(x) AS value FROM t WHERE id = :id', 'quoted function names are not allowed'];
        yield 'bad quoted identifier' => ['SELECT "va-lue" FROM t WHERE id = :id', 'quoted identifiers'];
        yield 'unbalanced quote' => ['SELECT "value FROM t WHERE id = :id', 'unbalanced'];
        yield 'assignment' => ['SELECT @x := 1 AS value FROM t WHERE id = :id', 'named parameter'];
        yield 'uppercase parameter' => ['SELECT value FROM t WHERE id = :Id', 'named parameter'];
        yield 'no parameter' => ['SELECT value FROM t', 'at least one named parameter'];
        yield 'unknown parameter' => ['SELECT value FROM t WHERE id = :tenant', 'unknown parameters: tenant'];
        yield 'unused parameter' => ['SELECT value FROM t WHERE id = :id', 'not used by sql: tenant', ['tenant' => 'x']];
        yield 'builtin parameter name' => ['SELECT value FROM t WHERE id = :id', 'not one of id', ['id' => 'x']];
        yield 'bad parameter name' => ['SELECT value FROM t WHERE id = :id', 'lowercase snake case', ['Tenant' => 'x']];
        yield 'numeric parameter name' => ['SELECT value FROM t WHERE id = :id', 'lowercase snake case', [0 => 'x']];
        yield 'non-scalar parameter' => ['SELECT value FROM t WHERE id = :id AND x = :tenant', 'must be a scalar', ['tenant' => new stdClass()]];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('refusedQueries')]
    public function testRefuses(string $sql, string $reason, array $parameters = []): void
    {
        $this->expectException(InvalidReadOnlyQuery::class);
        $this->expectExceptionMessage($reason);

        /* @phpstan-ignore argument.type (the refused parameters are the point) */
        new ReadOnlyQuery('tenant', $sql, $parameters);
    }

    public function testTheMessageNamesTheProviderButNotTheSql(): void
    {
        try {
            new ReadOnlyQuery('tenant', 'SELECT secret_column FROM t WHERE id = :id; DROP TABLE t');
            self::fail('Expected an exception.');
        } catch (InvalidReadOnlyQuery $e) {
            self::assertStringStartsWith('nowo_runtime_env.providers.tenant: ', $e->getMessage());
            self::assertStringNotContainsString('secret_column', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string, int, string}>
     */
    public static function refusedColumns(): iterable
    {
        yield 'value column' => ['va lue', null, null, 10, 'value_column'];
        yield 'previous column' => ['value', 'p-x', 'e', 10, 'previous_column'];
        yield 'expires column' => ['value', 'p', '1e', 10, 'previous_expires_column'];
        yield 'previous without expiry' => ['value', 'p', null, 10, 'go together'];
        yield 'expiry without previous' => ['value', null, 'e', 10, 'go together'];
        yield 'zero length' => ['value', null, null, 0, 'max_length'];
        yield 'too long' => ['value', null, null, 65537, 'max_length'];
    }

    #[DataProvider('refusedColumns')]
    public function testRefusesColumns(string $value, ?string $previous, ?string $expires, int $maxLength, string $reason): void
    {
        $this->expectException(InvalidReadOnlyQuery::class);
        $this->expectExceptionMessage($reason);

        new ReadOnlyQuery('tenant', 'SELECT value FROM t WHERE id = :id', [], $value, $previous, $expires, $maxLength);
    }
}
