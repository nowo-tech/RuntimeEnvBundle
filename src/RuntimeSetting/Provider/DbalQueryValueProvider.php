<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting\Provider;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Throwable;

use function array_change_key_case;
use function array_key_exists;
use function count;
use function is_bool;
use function is_float;
use function is_int;
use function is_resource;
use function is_string;
use function preg_match;
use function stream_get_contents;
use function strlen;
use function strtolower;

use const CASE_LOWER;

/**
 * 1.10: a read-only runtime value provider (nowo-tech/runtime-setting-contract 1.1) that runs one parameterised,
 * validated SELECT on a DBAL connection: `nowo_runtime_env.providers.<name>`.
 *
 * - The SQL and its constants come from configuration only ({@see ReadOnlyQuery}); the setting id is bound as
 *   `:id` / `:namespace` / `:key`, never interpolated.
 * - At most one row: a second row is a failure (ambiguous configuration), never "the first one wins".
 * - Values are bounded (`max_length`) before anything parses them; non-scalar columns are refused; NULL or no row is
 *   "no value".
 * - Every failure is a {@see RuntimeSettingSourceFailure} naming the provider and the rule, never the SQL, the
 *   parameters or a value, and never chaining the driver's exception (which may quote them).
 * - Stateless (worker mode): the connection is the host's, nothing is kept between calls.
 *
 * Run it with a database user that can only SELECT the tables it reads, and set a statement timeout for that user
 * (PostgreSQL `ALTER ROLE ... SET statement_timeout`, MySQL / MariaDB `max_execution_time` / `max_statement_time`).
 */
final readonly class DbalQueryValueProvider implements RuntimeValueProviderInterface
{
    /** Longest expiry representation accepted. */
    public const MAX_EXPIRY_LENGTH = 64;

    private const EXPIRY_PATTERN = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)?$/D';

    private DateTimeZone $timezone;

    /**
     * @param string $expiresTimezone zone of expiry values without an offset (the bundle's own store writes UTC)
     */
    public function __construct(
        private Connection $connection,
        private ReadOnlyQuery $query,
        string $expiresTimezone = 'UTC',
    ) {
        $this->timezone = new DateTimeZone($expiresTimezone);
    }

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        [$params, $types] = $this->parameters($id);

        try {
            $result = $this->connection->executeQuery($this->query->sql, $params, $types);
            try {
                $row    = $result->fetchAssociative();
                $second = $row === false ? false : $result->fetchAssociative();
            } finally {
                $result->free();
            }
        } catch (Throwable $e) {
            // The driver's message may quote the SQL or the bound values: class only, no chain.
            throw $this->failure('the query failed (' . $e::class . ')');
        }

        if ($row === false) {
            return null;
        }
        if ($second !== false) {
            throw $this->failure('the query returned more than one row');
        }

        $current = $this->string($row, $this->query->valueColumn, $this->query->maxLength, 'value');
        if ($current === null) {
            return null;
        }
        if ($this->query->previousColumn === null || $this->query->previousExpiresColumn === null) {
            return new StoredRuntimeValue($current);
        }

        $previous = $this->string($row, $this->query->previousColumn, $this->query->maxLength, 'previous value');
        $expires  = $this->string($row, $this->query->previousExpiresColumn, self::MAX_EXPIRY_LENGTH, 'previous expiry');
        // A previous value without its expiry (or the reverse) is not accepted: fail closed for that candidate only.
        if ($previous === null || $expires === null) {
            return new StoredRuntimeValue($current);
        }

        return new StoredRuntimeValue($current, $previous, $this->expiry($expires));
    }

    /**
     * @return array{0: array<string, bool|float|int|string|null>, 1: array<string, ParameterType>} (DBAL 3: its int constants)
     */
    private function parameters(RuntimeSettingId $id): array
    {
        $values = [];
        $types  = [];
        foreach ($this->query->usedParameters as $name) {
            $value = match ($name) {
                'id'        => $id->toString(),
                'namespace' => $id->namespace,
                'key'       => $id->key,
                default     => $this->query->parameters[$name] ?? null,
            };
            $values[$name] = is_float($value) ? (string) $value : $value;
            $types[$name]  = match (true) {
                $value === null => ParameterType::NULL,
                is_bool($value) => ParameterType::BOOLEAN,
                is_int($value)  => ParameterType::INTEGER,
                default         => ParameterType::STRING,
            };
        }

        return [$values, $types];
    }

    /**
     * The column as a bounded string; null for SQL NULL.
     *
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column, int $maxLength, string $what): ?string
    {
        $value = $this->column($row, $column);

        $stream = is_resource($value);
        if ($stream) {
            // LOB streams (Oracle, some PDO drivers): read one byte more than allowed to detect an overflow.
            $value = stream_get_contents($value, $maxLength + 1);
        }

        $string = match (true) {
            $value === null                  => null,
            is_string($value)                => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) && !$stream      => $value ? '1' : '0',
            default                          => throw $this->failure('the ' . $what . ' column is not a readable scalar'),
        };

        if ($string !== null && strlen($string) > $maxLength) {
            throw $this->failure('the ' . $what . ' is longer than ' . $maxLength . ' bytes');
        }

        return $string;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function column(array $row, string $column): mixed
    {
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        // Drivers fold unquoted column names (PostgreSQL lower case, Oracle upper case): one case-insensitive match.
        $folded = array_change_key_case($row, CASE_LOWER);
        if (count($folded) === count($row) && array_key_exists(strtolower($column), $folded)) {
            return $folded[strtolower($column)];
        }

        throw $this->failure('the result has no "' . $column . '" column');
    }

    private function expiry(string $value): DateTimeImmutable
    {
        if (preg_match('/^\d{1,12}$/D', $value) === 1) {
            return new DateTimeImmutable('@' . $value);
        }
        if (preg_match(self::EXPIRY_PATTERN, $value) !== 1) {
            throw $this->failure('the previous expiry is not a date-time or a Unix timestamp');
        }

        try {
            return new DateTimeImmutable($value, $this->timezone);
        } catch (Throwable) {
            throw $this->failure('the previous expiry is not a valid date-time');
        }
    }

    private function failure(string $reason): RuntimeSettingSourceFailure
    {
        return RuntimeSettingSourceFailure::because('Runtime value provider "' . $this->query->provider . '": ' . $reason . '.');
    }
}
