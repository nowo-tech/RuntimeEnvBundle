<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\RuntimeEnvBundle\Event\RuntimeEnvVariableChangedEvent;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function is_array;
use function is_string;
use function sprintf;

/**
 * 1.6: nowo-tech/runtime-setting-contract source and writer over the variables table (`{prefix}_variables`).
 *
 * - **Ids.** Only environment-variable ids (`API_TOKEN`, the contract's `env` namespace) live here; the variable name is
 *   the key, stored verbatim and re-checked with `===` on every fetched row (REQ-SEC-006). `namespace.key` ids are not
 *   stored by this source (read: unset; write: refused).
 * - **Reads** go through DBAL on every call (never an ORM identity map, audit QS-L-1), the version token and the value
 *   from the same connection. A disabled variable is unset.
 * - **Writes** run in their own transaction on the connection of `database.entity_manager`, through transaction-doctrine's
 *   runner (one attempt; a deadlock surfaces as a TransientStorageFailure, 503 on the bundle's routes). No entity manager
 *   is flushed, cleared or reset. A call inside a transaction the caller opened is refused (contract rule; audit lessons
 *   QL-M1 / QL-M2). Every write, also one that changes nothing, purges the expired previous values of the table and
 *   writes a new, never reused version token in the same transaction (Delta QS-D-I).
 * - **Values** are encrypted with the entity's `#[Encrypted]` format ({@see RuntimeEnvValueCipherInterface}); a rotation
 *   moves the stored ciphertext to `previous_value` as is.
 * - **Audit** after the commit: a log line (actor, name, action, fingerprints; none for secrets) and the bundle's
 *   {@see RuntimeEnvVariableChangedEvent} (name only), so 1.5 listeners keep working.
 *
 * Stateless: one instance serves every request of a long-running worker.
 */
final readonly class RuntimeEnvSettingStore implements RuntimeValueSourceInterface, RuntimeSettingWriterInterface
{
    /** Token of a namespace that was never written. */
    public const INITIAL_VERSION = '0';

    private LoggerInterface $logger;

    public function __construct(
        private ManagerRegistry $registry,
        private string $entityManagerName,
        private string $variablesTable,
        private string $versionsTable,
        private RuntimeEnvValueCipherInterface $cipher,
        private ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
        private ?RuntimeEnvBag $bag = null,
        private ?int $retryAfterSeconds = 1,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function namespaceVersion(string $namespace): string
    {
        $row = $this->connection()->fetchAssociative(
            sprintf('SELECT namespace, token FROM %s WHERE namespace = ?', $this->versionsTable),
            [$namespace],
        );

        // Exact re-check of the key (REQ-SEC-006).
        if (!is_array($row) || $row['namespace'] !== $namespace || !is_string($row['token'])) {
            return self::INITIAL_VERSION;
        }

        return $row['token'];
    }

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        if (!$id->isEnvName()) {
            return null;
        }

        $connection = $this->connection();
        $row        = $this->fetchRow($connection, $id->key, false);
        if ($row === null || !$row['enabled']) {
            return null;
        }

        $current  = $this->cipher->decrypt($row['value']);
        $previous = $row['previous_value'];
        $expires  = $row['previous_expires_at'];

        return $previous !== null && $expires instanceof DateTimeImmutable
            ? new StoredRuntimeValue($current, $this->cipher->decrypt($previous), $expires)
            : new StoredRuntimeValue($current);
    }

    public function set(RuntimeSettingDefinition $definition, string $value, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        return $this->write($definition, RuntimeSettingAction::Set, $context, function (Connection $connection, ?array $row, DateTimeImmutable $now) use ($definition, $value): string {
            return $this->store($connection, $definition->key(), $row, $this->cipher->encrypt($value), null, null, $now);
        }, $value);
    }

    public function unset(RuntimeSettingDefinition $definition, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        return $this->write($definition, RuntimeSettingAction::Unset, $context, function (Connection $connection, ?array $row, DateTimeImmutable $now) use ($definition): string {
            if ($row === null) {
                return RuntimeEnvVariableChangedEvent::UPDATED;
            }

            // Unset = disabled (the row and its description stay for the admin; the source reads it as unset). A secret's
            // value is emptied too (audit QT-I-4): re-enabling it needs a new value, never the old ciphertext.
            $connection->update($this->variablesTable, ($definition->secret ? ['value' => ''] : []) + [
                'enabled'             => false,
                'previous_value'      => null,
                'previous_expires_at' => null,
                'updated_at'          => $now,
            ], ['name' => $row['name']], $this->types());
            $this->advanceRowVersion($connection, $row['name']);

            return RuntimeEnvVariableChangedEvent::UPDATED;
        }, null);
    }

    public function rotate(RuntimeSettingDefinition $definition, string $value, DateTimeImmutable $previousExpiresAt, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        return $this->write($definition, RuntimeSettingAction::Rotate, $context, function (Connection $connection, ?array $row, DateTimeImmutable $now) use ($definition, $value, $previousExpiresAt): string {
            $keepsPrevious = $row !== null && $row['enabled'];

            return $this->store(
                $connection,
                $definition->key(),
                $row,
                $this->cipher->encrypt($value),
                $keepsPrevious ? $row['value'] : null,
                $keepsPrevious ? $previousExpiresAt : null,
                $now,
            );
        }, $value);
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        $connection = $this->ownTransactionConnection();

        /** @var int $purged */
        $purged = DoctrineTransactionRunner::forConnection($connection, $this->retryAfterSeconds)->run(function () use ($connection, $now): int {
            $purged = $this->purge($connection, $now);
            if ($purged > 0) {
                $this->bump($connection);
            }

            return $purged;
        });

        if ($purged > 0) {
            // @igor-ignore - Clears the bag's per-request memo after a committed write; no state kept here.
            $this->bag?->clearRuntimeCache();
            $this->logger->info('Runtime env: {count} expired previous value(s) purged.', ['count' => $purged]);
        }

        return $purged;
    }

    /**
     * 1.6: changes the free-form description of a variable (admin UI). It is not a setting value: no version change.
     * 1.9: the same statement advances the row version of the edit page (REQ-RUNTIME-003 rule 4).
     */
    public function updateDescription(string $name, ?string $description): void
    {
        $this->connection()->executeStatement(
            sprintf('UPDATE %s SET description = ?, row_version = row_version + 1 WHERE name = ?', $this->variablesTable),
            [$description, $name],
        );
    }

    /**
     * @param callable(Connection, array{name: string, value: string, enabled: bool, previous_value: ?string, previous_expires_at: ?DateTimeImmutable}|null, DateTimeImmutable): string $mutation
     *                                                                                                                                                                                            returns the bundle event action
     */
    private function write(RuntimeSettingDefinition $definition, RuntimeSettingAction $action, RuntimeSettingWriteContext $context, callable $mutation, ?string $newValue): RuntimeSettingChange
    {
        if (!$definition->id->isEnvName()) {
            throw new RuntimeSettingNotWritable(sprintf('Runtime setting "%s" cannot be stored in the runtime env variables table: it stores environment variable names only (e.g. API_TOKEN).', $definition->idString()));
        }

        $connection = $this->ownTransactionConnection();
        $now        = $this->now();

        /** @var array{0: ?string, 1: string, 2: string} $result */
        $result = DoctrineTransactionRunner::forConnection($connection, $this->retryAfterSeconds)->run(function () use ($connection, $definition, $mutation, $now): array {
            $row        = $this->fetchRow($connection, $definition->key(), true);
            $oldCurrent = $row !== null && $row['enabled'] ? $this->cipher->decrypt($row['value']) : null;
            $event      = $mutation($connection, $row, $now);
            // Only the row being written (already locked): a table-wide purge here makes two writes of different
            // variables deadlock on MySQL / MariaDB (audit QT-L-2). The global sweep is purgeExpired()'s job.
            $this->purge($connection, $now, $definition->key());

            return [$oldCurrent, $this->bump($connection), $event];
        });
        [$oldCurrent, $token, $event] = $result;

        // @igor-ignore - Clears the bag's per-request memo after a committed write; no state kept here.
        $this->bag?->clearRuntimeCache();

        $change = new RuntimeSettingChange(
            $definition->id,
            $action,
            $context->fingerprint($definition, $oldCurrent),
            $context->fingerprint($definition, $newValue),
            $token,
            false,
            $definition->secret,
        );
        $this->audit($definition, $change, $context, $event, $now);

        return $change;
    }

    /**
     * Inserts or updates the row with a new current value (enabled) and the given previous value.
     *
     * @param array{name: string, value: string, enabled: bool, previous_value: ?string, previous_expires_at: ?DateTimeImmutable}|null $row
     */
    private function store(Connection $connection, string $name, ?array $row, string $value, ?string $previous, ?DateTimeImmutable $expires, DateTimeImmutable $now): string
    {
        $data = [
            'value'               => $value,
            'enabled'             => true,
            'previous_value'      => $previous,
            'previous_expires_at' => $expires instanceof DateTimeImmutable ? $this->utc($expires) : null,
            'updated_at'          => $now,
        ];

        if ($row === null) {
            // 1.9: an id from the entity's sequence where the ORM maps one (PostgreSQL with DBAL 3).
            $connection->insert($this->variablesTable, RuntimeEnvVariableId::forInsert($this->manager(), $connection) + ['name' => $name, 'description' => null] + $data, $this->types());

            return RuntimeEnvVariableChangedEvent::CREATED;
        }

        $connection->update($this->variablesTable, $data, ['name' => $row['name']], $this->types());
        $this->advanceRowVersion($connection, $row['name']);

        return RuntimeEnvVariableChangedEvent::UPDATED;
    }

    /**
     * 1.9 (REQ-RUNTIME-003 rule 4): a write of the store makes the edit pages opened before it stale (same transaction,
     * the row is already locked by the write).
     */
    private function advanceRowVersion(Connection $connection, string $name): void
    {
        $connection->executeStatement(
            sprintf('UPDATE %s SET row_version = row_version + 1 WHERE name = ?', $this->variablesTable),
            [$name],
        );
    }

    /**
     * Conditional purge (Delta QS): only rows whose previous value expired at `$now` (UTC), in one statement; only the
     * row `$name` when given.
     */
    private function purge(Connection $connection, DateTimeImmutable $now, ?string $name = null): int
    {
        $sql    = 'UPDATE %s SET previous_value = NULL, previous_expires_at = NULL WHERE previous_expires_at IS NOT NULL AND previous_expires_at <= ?';
        $params = [$this->utc($now)];
        $types  = [Types::DATETIME_IMMUTABLE];
        if ($name !== null) {
            $sql .= ' AND name = ?';
            $params[] = $name;
            $types[]  = Types::STRING;
        }

        return (int) $connection->executeStatement(sprintf($sql, $this->variablesTable), $params, $types);
    }

    /**
     * Rotation expiries are stored as UTC wall-clock time (the column has no zone): every writer, purger and reader
     * agrees whatever its PHP default timezone (audit QT-L-1).
     */
    private function utc(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Writes a new, never reused token for the `env` namespace (same transaction as the write).
     */
    private function bump(Connection $connection): string
    {
        // The row exists first (no INSERT race, audit QT-L-3), then the UPDATE locks it and serialises the writers.
        RuntimeEnvVersionRow::ensure($connection, $this->versionsTable, RuntimeSettingId::ENV_NAMESPACE);
        $token = RuntimeEnvVersionBumper::newToken();
        $connection->update($this->versionsTable, ['token' => $token], ['namespace' => RuntimeSettingId::ENV_NAMESPACE]);

        return $token;
    }

    /**
     * The row of `$name` (exact match re-checked in PHP), locked for update inside a write.
     *
     * @return array{name: string, value: string, enabled: bool, previous_value: ?string, previous_expires_at: ?DateTimeImmutable}|null
     */
    private function fetchRow(Connection $connection, string $name, bool $lock): ?array
    {
        $platform = $connection->getDatabasePlatform();
        $select   = sprintf('SELECT name, value, enabled, previous_value, previous_expires_at FROM %s WHERE name = ?', $this->variablesTable);
        $row      = $connection->fetchAssociative($select, [$name]);
        // Lock an EXISTING row only (a record lock on the unique key; MySQL, MariaDB, PostgreSQL): a locking read of a
        // missing name takes a gap lock on MySQL / MariaDB, and two first writes of different names would deadlock on
        // their inserts. A missing row is inserted directly (a concurrent insert of the same name is a 409). SQLite
        // locks the whole database for a write anyway.
        $row = $lock && is_array($row) && !$platform instanceof SQLitePlatform ? $connection->fetchAssociative($select . ' FOR UPDATE', [$name]) : $row;
        if (!is_array($row) || $row['name'] !== $name || !is_string($row['value'])) {
            return null;
        }

        $previous = $row['previous_value'];
        $raw      = $row['previous_expires_at'];
        // UTC wall-clock time (QT-L-1); an unreadable expiry drops the previous value (fail closed).
        $expires = is_string($raw) ? DateTimeImmutable::createFromFormat('!' . $platform->getDateTimeFormatString(), $raw, new DateTimeZone('UTC')) : false;

        return [
            'name'                => $name,
            'value'               => $row['value'],
            'enabled'             => (bool) Type::getType(Types::BOOLEAN)->convertToPHPValue($row['enabled'], $platform),
            'previous_value'      => is_string($previous) ? $previous : null,
            'previous_expires_at' => $expires instanceof DateTimeImmutable ? $expires : null,
        ];
    }

    private function audit(RuntimeSettingDefinition $definition, RuntimeSettingChange $change, RuntimeSettingWriteContext $context, string $event, DateTimeImmutable $now): void
    {
        $this->logger->info('Runtime env setting {name} {action} by {actor}.', [
            'name'            => $definition->key(),
            'action'          => $change->action->value,
            'actor'           => $context->actor,
            'secret'          => $definition->secret,
            'old_fingerprint' => $change->oldFingerprint,
            'new_fingerprint' => $change->newFingerprint,
        ]);

        try {
            $this->eventDispatcher?->dispatch(new RuntimeEnvVariableChangedEvent($definition->key(), $event, $now));
        } catch (Throwable $e) {
            $this->logger->error('Runtime env setting {name} changed, but a RuntimeEnvVariableChangedEvent listener failed.', [
                'name'      => $definition->key(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * The connection for a write: refused inside a transaction the caller opened.
     */
    private function ownTransactionConnection(): Connection
    {
        $connection = $this->connection();
        if ($connection->isTransactionActive()) {
            throw RuntimeSettingNotWritable::insideTransaction();
        }

        return $connection;
    }

    private function connection(): Connection
    {
        return $this->manager()->getConnection();
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager($this->entityManagerName);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('Entity manager "%s" is not a Doctrine ORM entity manager.', $this->entityManagerName));
        }

        return $manager;
    }

    /**
     * @return array<string, string>
     */
    private function types(): array
    {
        return [
            'enabled'             => Types::BOOLEAN,
            'previous_expires_at' => Types::DATETIME_IMMUTABLE,
            'updated_at'          => Types::DATETIME_IMMUTABLE,
        ];
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
