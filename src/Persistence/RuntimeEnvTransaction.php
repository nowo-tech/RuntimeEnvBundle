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

use function array_values;
use function count;
use function is_string;
use function str_starts_with;

/**
 * Unit of work boundary of the bundle's writes (1.5, architecture bar rules 6 and 7).
 *
 * - `database.transaction: bundle` (default, standard hosts): the writer flushes `database.entity_manager` (the
 *   manager that maps the bundle entity), then the write events are dispatched.
 * - `database.transaction: host` (DDD hosts): {@see commit()} never flushes when the host calls the bundle; the
 *   host's use case owns the transaction. The events wait for the host's flush of that manager and are dispatched
 *   once no transaction is open any more (the host committed). The bundle's own admin controllers are a use case of
 *   their own: during a main request served by them the bundle still flushes its writes.
 *
 * Events are never dispatched inside a flush nor while a transaction that may still roll back is open. The recorded
 * events are request state: dispatched or discarded at every request / message / command boundary and on
 * `kernel.reset` (FrankenPHP worker mode without kernel reset).
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
    // @igor-ignore - Request-scoped outbox: dispatched or discarded at every request/message/command boundary and reset().
    private array $unflushed = [];

    /**
     * Events of flushed writes, dispatched once the connection has no open transaction.
     *
     * @var list<object>
     */
    // @igor-ignore - Request-scoped outbox: dispatched or discarded at every request/message/command boundary and reset().
    private array $flushed = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly bool $hostControlsTransaction = false,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION  => ['discardPending', -1024],
            KernelEvents::TERMINATE  => ['onBoundary', 1024],
            ConsoleEvents::ERROR     => ['discardPending', -1024],
            ConsoleEvents::TERMINATE => ['onBoundary', 1024],
            // symfony/messenger is optional: event names as strings.
            'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent' => ['onBoundary', 1024],
            'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent'  => ['discardPending', -1024],
        ];
    }

    public function isHostControlled(): bool
    {
        return $this->hostControlsTransaction;
    }

    /**
     * Ends one bundle write: flushes the bundle entity manager (unless the host controls the transaction) and records
     * the write events, dispatched as soon as the write is committed.
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
     * Records the events of writes already handed to {@see commit()} (the repository flushed them, or the host will).
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

        $this->flushed = [...$this->flushed, ...array_values($events)];
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

    public function dispatchPending(): int
    {
        if ($this->flushed === [] || !$this->entityManager->isOpen()) {
            return 0;
        }

        if ($this->entityManager->getConnection()->isTransactionActive()) {
            return 0;
        }

        $events        = $this->flushed;
        $this->flushed = [];
        foreach ($events as $event) {
            $this->eventDispatcher?->dispatch($event);
        }

        return count($events);
    }

    public function countPending(): int
    {
        return count($this->unflushed) + count($this->flushed);
    }

    /**
     * Doctrine `postFlush`: a host flush of the bundle manager writes the recorded changes; they are dispatched now
     * when that flush committed, else after the host's commit ({@see dispatchPending()} or the boundary).
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->unflushed !== [] && $args->getObjectManager() === $this->entityManager) {
            $this->flushed   = [...$this->flushed, ...$this->unflushed];
            $this->unflushed = [];
        }

        $this->dispatchPending();
    }

    /**
     * End of a request, message or command: dispatches what the host committed, discards the rest.
     */
    public function onBoundary(): void
    {
        if ($this->countPending() === 0) {
            return;
        }

        try {
            $this->dispatchPending();
        } finally {
            $this->discardPending();
        }
    }

    /**
     * Failed request, message or command: the recorded writes may have been rolled back.
     */
    public function discardPending(): void
    {
        $count = $this->countPending();
        if ($count === 0) {
            return;
        }

        $this->logger->warning('Runtime env write events discarded: the write was not committed before the end of the request, message or command.', [
            'bundle' => 'NowoRuntimeEnvBundle',
            'events' => $count,
        ]);
        $this->reset();
    }

    public function reset(): void
    {
        $this->unflushed = [];
        $this->flushed   = [];
    }
}
