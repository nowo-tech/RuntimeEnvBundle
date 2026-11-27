<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting\Provider;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;

use function array_diff;
use function array_keys;
use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function sort;
use function str_contains;
use function strlen;

/**
 * 1.10: one parameterised, read-only SQL query of a DBAL query provider, validated when the container is built.
 *
 * Rules (best effort, defence in depth: they catch configuration mistakes, they do not make arbitrary SQL safe; the
 * database grants are what bound the query, and on PostgreSQL a role with SELECT only can still create large objects,
 * take advisory locks or sleep through functions granted to PUBLIC, so revoke those or use a dedicated role):
 * - one statement starting with `SELECT`, at most {@see self::MAX_SQL_LENGTH} bytes, no control characters but
 *   whitespace;
 * - no `;`, no comments (`--`, `#`, `/*`), no string literals (`'`, `$$`), no backslash, no positional `?`, no `::`
 *   casts (use `CAST()`), no `%` (no container parameter or env value can change the SQL): constants go in
 *   `parameters`;
 * - `"…"` and backtick segments may only hold letters, digits, underscores and spaces (identifiers; on MySQL without
 *   ANSI_QUOTES a `"…"` segment is a string literal, harmless with that alphabet);
 * - no keyword that writes or locks ({@see self::FORBIDDEN_KEYWORDS}: INSERT, UPDATE, DELETE, INTO, FOR UPDATE /
 *   SHARE, LOCK, SET, CALL, ...) and no function of a family that writes, locks, sleeps, runs dynamic SQL or reaches
 *   files and other servers ({@see self::FORBIDDEN_FUNCTION_FAMILIES}: `lo_*`, `pg_*advisory*`, `pg_sleep*`,
 *   `dblink*`, `*_to_xml*`, `release_*lock*`, `*pos_wait`, `wait_for_*`, `NEXT VALUE FOR`, `load_*`, `xp_*`, `utl_*`,
 *   `OPENQUERY`, ...); quote an identifier that collides;
 * - named parameters only: the built-ins `:id`, `:namespace`, `:key` (the setting id and its parts) and the
 *   configured `parameters`; every configured parameter must be used, every used one must exist.
 */
final readonly class ReadOnlyQuery
{
    public const MAX_SQL_LENGTH = 4096;

    /** Parameters bound by the provider for every read. */
    public const BUILTIN_PARAMETERS = ['id', 'key', 'namespace'];

    public const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';

    public const PARAMETER_PATTERN = '/^[a-z_][a-z0-9_]{0,63}$/D';

    /** Whole words refused anywhere outside quoted identifiers (case-insensitive). */
    public const FORBIDDEN_KEYWORDS = [
        // Statements (cannot follow SELECT without ";", refused anyway).
        'alter', 'attach', 'call', 'copy', 'create', 'delete', 'detach', 'do', 'drop', 'exec', 'execute', 'grant',
        'handler', 'insert', 'load', 'lock', 'merge', 'pragma', 'rename', 'revoke', 'set', 'truncate', 'unlock',
        'update', 'upsert', 'vacuum',
        // Inside a SELECT: writes (SELECT ... INTO), row locks (FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE).
        'into', 'outfile', 'dumpfile', 'share',
    ];

    /**
     * Function families refused anywhere outside quoted identifiers (case-insensitive, audit ZDA-L1): matched by
     * prefix or pattern, not by exact name, so siblings (`pg_sleep_for`, `dblink_exec`, `lo_from_bytea`,
     * `pg_try_advisory_lock`, `release_all_locks`, ...) are refused too. Best effort: see the class docblock.
     */
    public const FORBIDDEN_FUNCTION_FAMILIES = [
        // PostgreSQL: sleep, large objects (writes), advisory locks, other servers, files, server control, sequences,
        // transaction ids, dynamic SQL through *_to_xml.
        'pg_sleep\w*', 'lo_\w+', 'pg_\w*advisory\w*', 'dblink\w*', 'pg_ls_\w+', 'pg_stat_file', 'pg_file_\w+',
        'pg_read_\w+', 'pg_reload_conf', 'pg_rotate_logfile', 'pg_switch_wal', 'pg_logical_\w+', 'pg_create_\w+',
        'pg_drop_\w+', 'pg_terminate_backend', 'pg_cancel_backend', 'pg_notify', 'set_config', '\w+_to_xml\w*',
        'txid_current\w*', 'pg_current_xact_id\w*', 'nextval', 'setval',
        // MySQL / MariaDB: sleep, named locks, replication waits, files.
        'sleep', 'benchmark', 'get_lock', 'release_\w*locks?', 'is_free_lock', 'is_used_lock', '\w*pos_wait',
        'wait_for_\w+', 'load_\w+',
        // SQLite: load_extension (load_*). SQL Server / Oracle: shell, procedures, packages, other servers, waits.
        'xp_\w+', 'sp_\w+', 'dbms_\w+', 'utl_\w+', 'openquery', 'openrowset', 'opendatasource', 'waitfor',
    ];

    /** @var list<string> named parameters the SQL uses, sorted */
    public array $usedParameters;

    /**
     * @param array<string, bool|float|int|string|null> $parameters configured constants, by name
     * @param int $maxLength longest value accepted (bytes), checked before anything parses it
     *
     * @throws InvalidReadOnlyQuery
     */
    public function __construct(
        public string $provider,
        public string $sql,
        public array $parameters = [],
        public string $valueColumn = 'value',
        public ?string $previousColumn = null,
        public ?string $previousExpiresColumn = null,
        public int $maxLength = RuntimeSettingType::MAX_LENGTH,
    ) {
        $this->usedParameters = self::validate($provider, $sql, $parameters);

        foreach (['value_column' => $valueColumn, 'previous_column' => $previousColumn, 'previous_expires_column' => $previousExpiresColumn] as $option => $column) {
            if ($column !== null && preg_match(self::IDENTIFIER_PATTERN, $column) !== 1) {
                throw InvalidReadOnlyQuery::because($provider, $option . ' must be a column name (letters, digits, underscores).');
            }
        }
        if (($previousColumn === null) !== ($previousExpiresColumn === null)) {
            throw InvalidReadOnlyQuery::because($provider, 'previous_column and previous_expires_column go together (a previous value needs its expiry).');
        }
        if ($maxLength < 1 || $maxLength > RuntimeSettingType::MAX_LENGTH) {
            throw InvalidReadOnlyQuery::because($provider, 'max_length must be between 1 and ' . RuntimeSettingType::MAX_LENGTH . '.');
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws InvalidReadOnlyQuery
     *
     * @return list<string> the named parameters the SQL uses, sorted
     */
    public static function validate(string $provider, string $sql, array $parameters): array
    {
        if ($sql === '' || strlen($sql) > self::MAX_SQL_LENGTH) {
            throw InvalidReadOnlyQuery::because($provider, 'sql must be 1 to ' . self::MAX_SQL_LENGTH . ' bytes.');
        }
        if (preg_match('/[^\t\n\r\x20-\x7E\x80-\xFF]/', $sql) === 1 || preg_match('//u', $sql) !== 1) {
            throw InvalidReadOnlyQuery::because($provider, 'sql must be valid UTF-8 without control characters.');
        }
        foreach ([';' => 'a ";" (one statement only)', '--' => 'a comment', '#' => 'a comment', '/*' => 'a comment', '*/' => 'a comment', "'" => 'a string literal (put constants in parameters)', '$' => 'a dollar-quoted string or variable', '\\' => 'a backslash', '?' => 'a positional parameter (use :name)', '::' => 'a "::" cast (use CAST())', '%' => 'a "%" (container parameters are not resolved in sql; use MOD() for modulo)'] as $needle => $what) {
            if (str_contains($sql, $needle)) {
                throw InvalidReadOnlyQuery::because($provider, 'sql must not contain ' . $what . '.');
            }
        }

        // Quoted identifiers: letters, digits, underscores, spaces. Then they are blanked out for the keyword check.
        if (preg_match_all('/"[^"]*"|`[^`]*`/', $sql, $quoted) !== false) {
            foreach ($quoted[0] as $identifier) {
                if (preg_match('/^["`][A-Za-z0-9_ ]{1,64}["`]$/D', $identifier) !== 1) {
                    throw InvalidReadOnlyQuery::because($provider, 'quoted identifiers may only contain letters, digits, underscores and spaces.');
                }
            }
        }
        $bare = (string) preg_replace('/"[^"]*"|`[^`]*`/', ' x ', $sql);
        if (str_contains($bare, '"') || str_contains($bare, '`')) {
            throw InvalidReadOnlyQuery::because($provider, 'sql has an unbalanced quoted identifier.');
        }

        if (preg_match('/^select\b/i', ltrim($bare)) !== 1) {
            throw InvalidReadOnlyQuery::because($provider, 'sql must be a single SELECT statement.');
        }
        if (preg_match('/\b(' . implode('|', self::FORBIDDEN_KEYWORDS) . ')\b/i', $bare, $match) === 1) {
            throw InvalidReadOnlyQuery::because($provider, 'sql must be read-only; it uses the keyword ' . $match[1] . '.');
        }
        if (preg_match('/\b(' . implode('|', self::FORBIDDEN_FUNCTION_FAMILIES) . ')\b/i', $bare, $match) === 1
            || preg_match('/\b(next\s+value\s+for)\b/i', $bare, $match) === 1
        ) {
            throw InvalidReadOnlyQuery::because($provider, 'sql must be read-only; it uses ' . $match[1] . ', which can write, lock, sleep, run dynamic SQL or reach files or other servers.');
        }

        // Named parameters: every ":" starts one.
        if (preg_match('/:(?![a-z_][a-z0-9_]*\b)/', $bare) === 1) {
            throw InvalidReadOnlyQuery::because($provider, 'every ":" must start a named parameter (lowercase letters, digits, underscores).');
        }
        preg_match_all('/:([a-z_][a-z0-9_]*)\b/', $bare, $names);
        $used = array_values(array_unique($names[1]));
        sort($used);
        if ($used === []) {
            throw InvalidReadOnlyQuery::because($provider, 'sql must select by at least one named parameter (:id, :namespace or :key).');
        }

        foreach ($parameters as $name => $value) {
            if (!is_string($name) || preg_match(self::PARAMETER_PATTERN, $name) !== 1 || in_array($name, self::BUILTIN_PARAMETERS, true)) {
                throw InvalidReadOnlyQuery::because($provider, 'parameter names must be lowercase snake case and not one of id, namespace, key.');
            }
            if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw InvalidReadOnlyQuery::because($provider, 'parameter "' . $name . '" must be a scalar.');
            }
        }

        $known   = [...self::BUILTIN_PARAMETERS, ...array_keys($parameters)];
        $unknown = array_diff($used, $known);
        if ($unknown !== []) {
            throw InvalidReadOnlyQuery::because($provider, 'sql uses unknown parameters: ' . implode(', ', $unknown) . '.');
        }
        $unused = array_diff(array_keys($parameters), $used);
        if ($unused !== []) {
            throw InvalidReadOnlyQuery::because($provider, 'parameters not used by sql: ' . implode(', ', $unused) . '.');
        }

        return $used;
    }
}
