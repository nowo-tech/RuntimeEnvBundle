<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Persistence;

use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

use function array_slice;
use function array_values;
use function count;
use function strcasecmp;

/**
 * Per-transaction outbox of the bundle's write events (1.5, architecture bar rule 7; the InstanceSettings 1.11 /
 * LegalKit 1.10 design).
 *
 * {@see AfterCommitMiddleware} reports the **real** transactions of the bundle's connection and their savepoints. An
 * event whose write is flushed while a transaction is open is held by the innermost level: a commit of the outermost
 * transaction makes it "committed", a rollback (of the transaction, or of the savepoint that held it) drops it. An
 * event flushed outside any transaction is committed at once.
 *
 * Committed events are not dispatched from inside the driver commit (DBAL still reports the transaction as active
 * there, so a listener's own write would nest into a transaction that no longer exists): {@see RuntimeEnvTransaction}
 * takes them at the next safe point.
 *
 * Holds no service (no entity manager), so the middleware of the connection can depend on it. The state is keyed
 * weakly by the driver connection and dropped on kernel.reset (FrankenPHP worker mode without kernel reset).
 *
 * @internal
 */
final class RuntimeEnvOutbox implements TransactionObserverInterface, ResetInterface
{
    /**
     * Open transactions by driver connection, in the order they began: the real transaction, then one level per
     * savepoint, each with the events flushed while it was the innermost level.
     *
     * @var WeakMap<object, list<array{0: string|null, 1: list<object>}>>
     */
    // @igor-ignore - Events of the open transaction; committed or dropped when it ends, and on kernel.reset.
    private WeakMap $transactions;

    /**
     * Events of committed writes not dispatched yet.
     *
     * @var list<object>
     */
    // @igor-ignore - Committed events taken at the next safe point; dropped on kernel.reset.
    private array $committed = [];

    public function __construct()
    {
        $this->transactions = new WeakMap();
    }

    /**
     * Events of flushed writes: held by the open transaction, or committed at once when none is open.
     */
    public function hold(object ...$events): void
    {
        if ($events === []) {
            return;
        }

        $connection = null;
        foreach ($this->transactions as $open => $levels) {
            // The most recently started live transaction.
            $connection = $open;
        }

        $events = array_values($events);
        if ($connection === null) {
            $this->committed = [...$this->committed, ...$events];

            return;
        }

        $levels        = $this->transactions[$connection];
        $last          = count($levels) - 1;
        [$name, $held] = $levels[$last];
        $levels[$last] = [$name, [...$held, ...$events]];
        // @igor-ignore - Events of the open transaction; committed or dropped when it ends, and on kernel.reset.
        $this->transactions[$connection] = $levels;
    }

    /**
     * Takes the committed events (the caller dispatches them).
     *
     * @return list<object>
     */
    public function takeCommitted(): array
    {
        $events          = $this->committed;
        $this->committed = [];

        return $events;
    }

    /**
     * Number of committed events not taken yet.
     */
    public function countCommitted(): int
    {
        return count($this->committed);
    }

    /**
     * Number of events held by open transactions.
     */
    public function countHeld(): int
    {
        $count = 0;
        foreach ($this->transactions as $levels) {
            $count += count($this->flatten($levels));
        }

        return $count;
    }

    public function transactionStarted(object $connection): void
    {
        unset($this->transactions[$connection]);
        // @igor-ignore - Events of the open transaction; committed or dropped when it ends, and on kernel.reset.
        $this->transactions[$connection] = [[null, []]];
    }

    public function transactionEnded(object $connection, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        unset($this->transactions[$connection]);
        if ($committed) {
            $this->committed = [...$this->committed, ...$this->flatten($levels)];
        }
    }

    public function savepointCreated(object $connection, string $name): void
    {
        if (!isset($this->transactions[$connection])) {
            return;
        }

        $this->closeSavepoint($connection, $name, true);
        $levels   = $this->transactions[$connection];
        $levels[] = [$name, []];
        // @igor-ignore - Events of the open transaction; committed or dropped when it ends, and on kernel.reset.
        $this->transactions[$connection] = $levels;
    }

    public function savepointReleased(object $connection, string $name): void
    {
        $this->closeSavepoint($connection, $name, true);
    }

    public function savepointRolledBack(object $connection, string $name): void
    {
        $this->closeSavepoint($connection, $name, false);
    }

    /**
     * Drops every held and committed event (kernel.reset: a worker request that died mid-transaction).
     */
    public function reset(): void
    {
        $this->transactions = new WeakMap();
        $this->committed    = [];
    }

    private function closeSavepoint(object $connection, string $name, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        foreach ($levels as $index => [$level]) {
            if ($index > 0 && $level !== null && strcasecmp($level, $name) === 0) {
                $closed = $this->flatten(array_slice($levels, $index));
                $levels = array_slice($levels, 0, $index);
                if ($committed) {
                    $parent             = count($levels) - 1;
                    $levels[$parent][1] = [...$levels[$parent][1], ...$closed];
                }
                // @igor-ignore - Events of the open transaction; committed or dropped when it ends, and on kernel.reset.
                $this->transactions[$connection] = $levels;

                return;
            }
        }
    }

    /**
     * @param list<array{0: string|null, 1: list<object>}> $levels
     *
     * @return list<object>
     */
    private function flatten(array $levels): array
    {
        $events = [];
        foreach ($levels as [, $level]) {
            $events = [...$events, ...$level];
        }

        return $events;
    }
}
