<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\RuntimeEnvBundle\Worker\UnitOfWorkFrames;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_values;
use function count;
use function is_string;
use function str_starts_with;

use const PHP_INT_MAX;

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
 * Events of writes never flushed are discarded at the OUTERMOST boundary only (1.5.1, audits NM / NL2-v / NL2-v2): a
 * nested command, a command run from a handler or a controller, or a request handled inside a command or a message
 * only dispatches what was committed, so the outer unit's use case may still flush. Which unit is outermost is tracked
 * by {@see UnitOfWorkFrames} (fed at `PHP_INT_MAX`: console frames keyed by Command + input that save and restore the
 * outer state, failing frames healed at the next unit, a main-request depth, a Messenger flag). Its state describes the
 * units still running and is NOT dropped by `kernel.reset`; the pending events are.
 *
 * @internal Use {@see RuntimeEnvPendingEventsInterface}
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class RuntimeEnvTransaction implements RuntimeEnvPendingEventsInterface, EventSubscriberInterface, ResetInterface
{
    private const BUNDLE_CONTROLLER_NAMESPACE = 'Nowo\\RuntimeEnvBundle\\Controller\\';

    /** Messenger event names (class names), subscribed without requiring symfony/messenger. */
    public const MESSENGER_WORKER_MESSAGE_RECEIVED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent';
    public const MESSENGER_WORKER_MESSAGE_HANDLED  = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent';
    public const MESSENGER_WORKER_MESSAGE_FAILED   = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent';
    public const MESSENGER_WORKER_MESSAGE_SKIPPED  = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageSkipEvent';
    public const MESSENGER_WORKER_STOPPED          = 'Symfony\\Component\\Messenger\\Event\\WorkerStoppedEvent';

    /**
     * Events of writes not flushed yet (host-controlled transaction only).
     *
     * @var list<object>
     */
    // @igor-ignore - Request-scoped outbox: handed to the transaction outbox on flush, discarded at every boundary and reset().
    private array $unflushed = [];

    /** Units of work running in this process (process-lifetime, not dropped by {@see reset()}). */
    private readonly UnitOfWorkFrames $frames;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly bool $hostControlsTransaction = false,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?RequestStack $requestStack = null,
        private readonly RuntimeEnvOutbox $outbox = new RuntimeEnvOutbox(),
    ) {
        $this->frames = new UnitOfWorkFrames();
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Bookkeeping at PHP_INT_MAX (no other listener can throw or stop it first), boundaries at their priorities.
            KernelEvents::REQUEST        => ['onRequest', PHP_INT_MAX],
            KernelEvents::FINISH_REQUEST => ['onFinishRequest', PHP_INT_MAX],
            // kernel.terminate is the HTTP boundary; kernel.exception at -1024 may never run (FrameworkBundle's error
            // listener at -128 sets a response and stops propagation): it only dispatches what was committed.
            KernelEvents::EXCEPTION  => ['onHttpException', -1024],
            KernelEvents::TERMINATE  => [['onHttpTerminateStarted', PHP_INT_MAX], ['onHttpTerminate', 1024]],
            ConsoleEvents::COMMAND   => ['onConsoleCommand', PHP_INT_MAX],
            ConsoleEvents::ERROR     => [['onConsoleErrorStarted', PHP_INT_MAX], ['onConsoleError', -1024]],
            ConsoleEvents::TERMINATE => [['onConsoleTerminateStarted', PHP_INT_MAX], ['onConsoleTerminate', 1024]],
            // symfony/messenger is optional: event names as strings.
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => ['onMessageReceived', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => [['onMessageFinishedStarted', PHP_INT_MAX], ['onMessageFinished', 1024]],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => [['onMessageFinishedStarted', PHP_INT_MAX], ['onMessageFinished', -1024]],
            // Only dispatched by messenger:failed:retry: the message is no longer in flight.
            self::MESSENGER_WORKER_MESSAGE_SKIPPED => ['onMessageFinishedStarted', PHP_INT_MAX],
            self::MESSENGER_WORKER_STOPPED         => ['onWorkerStopped', PHP_INT_MAX],
        ];
    }

    /** A main request starts (bookkeeping). */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->frames->requestStarted();
        }
    }

    /** A main request finished (bookkeeping). */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->frames->requestFinished();
        }
    }

    /**
     * A request failed: dispatches what was committed (a committed write keeps its event, LH-RE-M1). Unflushed events
     * are never discarded here (1.5.1): a sub-request, a request handled inside a command, a message or another main
     * request must not drop the outer unit's events, and the outermost request discards them at `kernel.terminate`.
     */
    public function onHttpException(ExceptionEvent $event): void
    {
        $this->dispatchPending();
    }

    /**
     * Top-level `kernel.terminate` (bookkeeping): forgets a request depth left behind when `kernel.finish_request`
     * never came (a throwing `kernel.exception` listener), so the boundary below stays the outermost one.
     */
    public function onHttpTerminateStarted(): void
    {
        $this->frames->requestTerminated();
    }

    /**
     * End of a request (after `kernel.finish_request`): a full boundary only outside a command, a message and another
     * main request.
     */
    public function onHttpTerminate(): void
    {
        $this->onBoundary($this->frames->isIdle());
    }

    /** A console run starts (bookkeeping). */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->frames->commandStarted($event);
    }

    /** A console run failed (bookkeeping: its frame is marked failing until its own terminate). */
    public function onConsoleErrorStarted(ConsoleErrorEvent $event): void
    {
        $this->frames->commandFailed($event);
    }

    /**
     * Failure of a console run: a full boundary only for the outermost run (an unknown command only when nothing else
     * runs).
     */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->onBoundary($this->frames->isOutermost($event));
    }

    /** A console run ends (bookkeeping: its frame is removed, the outermost verdict kept for the boundary). */
    public function onConsoleTerminateStarted(ConsoleTerminateEvent $event): void
    {
        $this->frames->commandTerminated($event);
    }

    /** End of a console run: a full boundary only for the outermost run. */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->onBoundary($this->frames->isOutermost($event));
    }

    /** A Messenger message is received (bookkeeping). */
    public function onMessageReceived(): void
    {
        $this->frames->messageReceived();
    }

    /** A Messenger message is handled, failed or skipped (bookkeeping); each message is its own unit of work. */
    public function onMessageFinishedStarted(): void
    {
        $this->frames->messageFinished();
    }

    /** End of a Messenger message (handled or failed): a full boundary, each message is its own unit of work. */
    public function onMessageFinished(): void
    {
        $this->onBoundary();
    }

    /** The worker stopped (possibly after a message without handled / failed). */
    public function onWorkerStopped(): void
    {
        $this->frames->workerStopped();
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
     * End of a request, message or command (also a failed one): dispatches what was committed and, at the OUTERMOST
     * boundary only, discards the events of writes never flushed (a nested boundary keeps them for the outer unit,
     * whose use case may still flush).
     */
    public function onBoundary(bool $outermost = true): void
    {
        try {
            $this->dispatchPending();
        } finally {
            if ($outermost) {
                $this->discardUnflushed();
            }
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

    /**
     * `kernel.reset`: drops the pending events. The unit-of-work frames are kept: they describe the units still running
     * (a reset between the messages of `messenger:consume` must not forget the command).
     */
    public function reset(): void
    {
        $this->unflushed = [];
        $this->outbox->reset();
    }
}
