<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Persistence;

/**
 * Write events (`Nowo\RuntimeEnvBundle\Event\RuntimeEnvVariableChangedEvent`) recorded by the bundle and not dispatched yet (1.5).
 *
 * Events are dispatched only once the write is committed, tracked on the real transactions of the bundle's connection:
 * a rollback (or a savepoint rollback) drops them. With `database.transaction: host` the bundle never flushes: the
 * events wait for the host's flush, follow the transaction it ran in, and are dispatched after its outermost commit on
 * the next `postFlush` outside a transaction or at the end of the request / Messenger message / console command. A
 * host that commits in the middle of a long process can call {@see dispatchPending()} right after its commit.
 */
interface RuntimeEnvPendingEventsInterface
{
    /**
     * Dispatches the events of committed writes when the bundle's connection has no open transaction.
     *
     * @return int The number of events dispatched (0 while a transaction is still open or nothing is pending)
     */
    public function dispatchPending(): int;

    /**
     * Number of recorded events not dispatched yet.
     */
    public function countPending(): int;
}
