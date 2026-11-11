<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_values;
use function count;
use function is_string;
use function str_starts_with;

/**
 * Unit of work boundary of the bundle's writes (1.5, architecture bar rules 6 and 7).
 *
 * - `database.transaction: bundle` (default, standard hosts): the writer flushes `database.entity_manager` (the
 *   manager that maps the bundle entity).
 * - `database.transaction: host` (DDD hosts): {@see commit()} never flushes when the host calls the bundle; the
 *   host's use case owns the transaction and the events wait for its flush of that manager. The bundle's own admin
 *   controllers are a use case of their own: during a main request served by them the bundle still flushes.
 *
 * Events follow the **real** commit (LH-RE-M1): once flushed they go to the {@see RuntimeEnvOutbox}, which
 * {@see AfterCommitMiddleware} tells about the transactions of the bundle's connection. A rollback (also a savepoint
 * rollback, or a host transaction around a bundle-mode write) drops them; the outermost commit makes them committed.
 * Committed events are dispatched at the next safe point where DBAL reports no open transaction: right after a
 * bundle flush or a host flush, {@see dispatchPending()}, and the end of the request / message / command (also a
 * failed one: a committed write keeps its event, LH-RE-M1). Never inside a flush nor inside a transaction.
 *
 * Events of writes never flushed are discarded at every boundary; everything is dropped on `kernel.reset`
 * (FrankenPHP worker mode without kernel reset).
 *
 * @internal Use {@see RuntimeEnvPendingEventsInterface}
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class RuntimeEnvTransaction implements RuntimeEnvPendingEventsInterface, EventSubscriberInterface, ResetInterface
{
    private const BUNDLE_CONTROLLER_NAMESPACE = 'Nowo\\RuntimeEnvBundle\\Controller\\';

    /**
     * Events of writes not flushed yet (host-controlled transaction only).
     *
     * @var list<object>
     */
    // @igor-ignore - Request-scoped outbox: handed to the transaction outbox on flush, discarded at every boundary and reset().
    private array $unflushed = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly bool $hostControlsTransaction = false,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?RequestStack $requestStack = null,
        private readonly RuntimeEnvOutbox $outbox = new RuntimeEnvOutbox(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION  => ['onBoundary', -1024],
            KernelEvents::TERMINATE  => ['onBoundary', 1024],
            ConsoleEvents::ERROR     => ['onBoundary', -1024],
            ConsoleEvents::TERMINATE => ['onBoundary', 1024],
            // symfony/messenger is optional: event names as strings.
            'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent' => ['onBoundary', 1024],
            'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent'  => ['onBoundary', -1024],
        ];
    }

    public function isHostControlled(): bool
    {
        return $this->hostControlsTransaction;
    }

    /**
     * Ends one bundle write: flushes the bundle entity manager (unless the host controls the transaction) and records
     * the write events, dispatched once the write is committed.
     */
    public function commit(object ...$events): void
    {
        $flush = $this->flushesItself();
        if ($flush) {
            $this->entityManager->flush();
        }

        $this->record($flush, ...$events);
    }

    /**
     * Records the events of writes already handed to {@see commit()} (e.g. a revision restore on top of a save).
     */
    public function record(?bool $flushed = null, object ...$events): void
    {
        if ($events === []) {
            return;
        }

        if (!($flushed ?? $this->flushesItself())) {
            $this->unflushed = [...$this->unflushed, ...array_values($events)];

            return;
        }

        $this->outbox->hold(...$events);
        $this->dispatchPending();
    }

    /**
     * Whether the bundle flushes its own writes: always in `bundle` mode, and in `host` mode only while one of the
     * bundle's admin controllers serves the main request (the bundle UI owns that unit of work).
     */
    public function flushesItself(): bool
    {
        if (!$this->hostControlsTransaction) {
            return true;
        }

        $controller = $this->requestStack?->getMainRequest()?->attributes->get('_controller');

        return is_string($controller) && str_starts_with($controller, self::BUNDLE_CONTROLLER_NAMESPACE);
    }

    /**
     * Dispatches the events of committed writes when DBAL reports no open transaction on the bundle's connection.
     * A listener that throws cannot undo the commit: it is logged and the other events are still dispatched.
     */
    public function dispatchPending(): int
    {
        if ($this->outbox->countCommitted() === 0 || $this->entityManager->getConnection()->isTransactionActive()) {
            return 0;
        }

        $events = $this->outbox->takeCommitted();
        foreach ($events as $event) {
            try {
                $this->eventDispatcher?->dispatch($event);
            } catch (Throwable $e) {
                $this->logger->error('Runtime env: a listener failed after the commit.', [
                    'bundle'    => 'NowoRuntimeEnvBundle',
                    'event'     => $event::class,
                    'exception' => $e::class,
                ]);
            }
        }

        return count($events);
    }

    public function countPending(): int
    {
        return count($this->unflushed) + $this->outbox->countHeld() + $this->outbox->countCommitted();
    }

    /**
     * Doctrine `postFlush`: a flush of the bundle manager wrote the recorded changes; their events now follow the
     * transaction (if any) that flush ran in.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->unflushed !== [] && $args->getObjectManager() === $this->entityManager) {
            $events          = $this->unflushed;
            $this->unflushed = [];
            $this->outbox->hold(...$events);
        }

        $this->dispatchPending();
    }

    /**
     * End of a request, message or command (also a failed one): dispatches what was committed, discards the events of
     * writes never flushed.
     */
    public function onBoundary(): void
    {
        try {
            $this->dispatchPending();
        } finally {
            $this->discardUnflushed();
        }
    }

    /**
     * Drops the events of writes never flushed.
     */
    public function discardUnflushed(): void
    {
        $count = count($this->unflushed);
        if ($count === 0) {
            return;
        }

        $this->logger->warning('Runtime env write events discarded: the write was never flushed before the end of the request, message or command.', [
            'bundle' => 'NowoRuntimeEnvBundle',
            'events' => $count,
        ]);
        $this->unflushed = [];
    }

    public function reset(): void
    {
        $this->unflushed = [];
        $this->outbox->reset();
    }
}
