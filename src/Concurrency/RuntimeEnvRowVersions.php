<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

use function is_numeric;
use function sprintf;

/**
 * 1.9 (REQ-RUNTIME-003 rule 4): the optimistic row version of the manage page's edit form.
 *
 * - {@see current()} reads the version from the database (never the identity map: a manager that kept a loaded row
 *   across requests, in worker mode with the kernel reset off, cannot make a stale page look current).
 * - {@see saveEdit()} runs one unit of transaction-doctrine's runner: the compare-and-set
 *   `UPDATE … SET row_version = row_version + 1 WHERE id = ? AND row_version = ?`, then the write. The compare-and-set
 *   takes the row lock, so a second save of the same page waits for the first one's commit and then matches no row. On
 *   a failure the runner rolls the connection back to the level it found, also after a MySQL / MariaDB deadlock that
 *   destroyed the savepoint of the flush (it closes the connection when that rollback fails, audit ZH-M1), and
 *   rethrows: a lock conflict as a `TransientStorageFailure` (409 `CONFLICT_001` / `CONFLICT_002` on the manage
 *   pages), a stale page as {@see StaleEditException}.
 * - {@see claim()} is the same compare-and-set as a unit of its own, for the registered runtime settings, whose store
 *   refuses to write inside a transaction of the caller.
 *
 * Works on the connection of `database.entity_manager` (a dedicated manager of a DDD host or the host's default one);
 * no manager is flushed, cleared or reset here. Stateless: one instance serves every request of a worker.
 */
final readonly class RuntimeEnvRowVersions
{
    public function __construct(
        private ManagerRegistry $registry,
        private string $entityManagerName,
        private string $variablesTable,
        private ?int $retryAfterSeconds = 1,
    ) {
    }

    /**
     * The current row version of variable `$id`, or null when the row does not exist.
     */
    public function current(int $id): ?int
    {
        $value = $this->connection()->fetchOne(
            sprintf('SELECT row_version FROM %s WHERE id = ?', $this->variablesTable),
            [$id],
        );

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Checks and advances the row version, then runs `$write`, in one transaction.
     *
     * @template T
     *
     * @param int $id the edited variable
     * @param int $expected the row version of the edited page (the form's hidden field)
     * @param callable(): T $write the write (the writer's flush of `database.entity_manager`)
     *
     * @throws StaleEditException when someone saved the variable since the page was opened (nothing written)
     *
     * @return T
     */
    public function saveEdit(int $id, int $expected, callable $write): mixed
    {
        $connection = $this->connection();

        return DoctrineTransactionRunner::forConnection($connection, $this->retryAfterSeconds)->run(function () use ($connection, $id, $expected, $write): mixed {
            self::advance($connection, $this->variablesTable, $id, $expected);

            return $write();
        });
    }

    /**
     * Checks and advances the row version in a unit of its own (committed when this returns).
     *
     * @throws StaleEditException when someone saved the variable since the page was opened (nothing written)
     *
     * @return int the row version after the claim
     */
    public function claim(int $id, int $expected): int
    {
        $connection = $this->connection();
        DoctrineTransactionRunner::forConnection($connection, $this->retryAfterSeconds)->run(function () use ($connection, $id, $expected): void {
            self::advance($connection, $this->variablesTable, $id, $expected);
        });

        return $expected + 1;
    }

    /**
     * One atomic `UPDATE … SET row_version = row_version + 1 WHERE id = ?`, with `AND row_version = ?` when `$expected`
     * is given (a page's save). Without `$expected` (a write that has no page behind it) the version advances
     * unchecked, so pages opened before that write are refused as stale instead of overwriting it.
     *
     * @throws StaleEditException when `$expected` is given and no longer the row's version (or the row is gone)
     */
    public static function advance(Connection $connection, string $table, int $id, ?int $expected = null): void
    {
        $sql    = sprintf('UPDATE %s SET row_version = row_version + 1 WHERE id = ?', $table);
        $params = [$id];
        if ($expected !== null) {
            $sql .= ' AND row_version = ?';
            $params[] = $expected;
        }

        $affected = (int) $connection->executeStatement($sql, $params);
        if ($expected !== null && $affected !== 1) {
            throw new StaleEditException($id, $expected);
        }
    }

    private function connection(): Connection
    {
        $manager = $this->registry->getManager($this->entityManagerName);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('Entity manager "%s" is not a Doctrine ORM entity manager.', $this->entityManagerName));
        }

        return $manager->getConnection();
    }
}
