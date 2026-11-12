<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Nowo\RuntimeEnvBundle\Event\RuntimeEnvVariableChangedEvent;
use Nowo\RuntimeEnvBundle\Persistence\RuntimeEnvOutbox;
use Nowo\RuntimeEnvBundle\Persistence\RuntimeEnvTransaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use const PHP_INT_MAX;

#[CoversClass(RuntimeEnvTransaction::class)]
final class RuntimeEnvTransactionTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private bool $transactionActive = false;

    #[Test]
    public function bundleModeFlushesThenDispatchesAfterCommit(): void
    {
        $em          = $this->manager(flushes: true);
        $transaction = new RuntimeEnvTransaction($em, $this->dispatcher());

        self::assertFalse($transaction->isHostControlled());
        $transaction->commit($event = new RuntimeEnvVariableChangedEvent('k', 'u', new DateTimeImmutable()));

        self::assertSame([$event], $this->dispatched);
        self::assertSame(0, $transaction->countPending());
    }

    #[Test]
    public function bundleModeInsideAHostTransactionFollowsItsCommitOrRollback(): void
    {
        $outbox      = new RuntimeEnvOutbox();
        $driver      = new stdClass();
        $transaction = new RuntimeEnvTransaction($this->manager(), $this->dispatcher(), outbox: $outbox);

        // Host transaction rolled back: the event is dropped, also at the next boundary (LG-PBK-M1).
        $outbox->transactionStarted($driver);
        $this->transactionActive = true;
        $transaction->record(true, new RuntimeEnvVariableChangedEvent('rolled', 'back', new DateTimeImmutable()));
        $transaction->commit();
        self::assertSame(1, $transaction->countPending());
        self::assertSame(0, $transaction->dispatchPending());
        $outbox->transactionEnded($driver, false);
        $this->transactionActive = false;
        $transaction->onBoundary();
        self::assertSame([], $this->dispatched);
        self::assertSame(0, $transaction->countPending());

        // Host transaction committed: dispatched once DBAL reports no open transaction.
        $outbox->transactionStarted($driver);
        $this->transactionActive = true;
        $transaction->record(true, $event = new RuntimeEnvVariableChangedEvent('k', 'u', new DateTimeImmutable()));
        $outbox->transactionEnded($driver, true);
        self::assertSame(0, $transaction->dispatchPending(), 'DBAL still inside its commit');
        $this->transactionActive = false;
        self::assertSame(1, $transaction->dispatchPending());
        self::assertSame([$event], $this->dispatched);
    }

    #[Test]
    public function aFailedRequestKeepsTheEventsOfCommittedWritesAndDropsTheUnflushedOnes(): void
    {
        $outbox      = new RuntimeEnvOutbox();
        $driver      = new stdClass();
        $em          = $this->manager();
        $transaction = new RuntimeEnvTransaction($em, $this->dispatcher(), true, outbox: $outbox);

        // Host use case: write, flush inside its transaction, commit.
        $transaction->commit($committed = new RuntimeEnvVariableChangedEvent('committed', 'u', new DateTimeImmutable()));
        $outbox->transactionStarted($driver);
        $this->transactionActive = true;
        $transaction->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));
        self::assertSame(1, $transaction->countPending(), 'another manager flushed: still unflushed');
        $transaction->postFlush(new PostFlushEventArgs($em));
        $outbox->transactionEnded($driver, true);
        $this->transactionActive = false;

        // A later write never flushed, then the request fails (kernel.exception, LG-PBK-L1).
        $transaction->commit(new RuntimeEnvVariableChangedEvent('never', 'flushed', new DateTimeImmutable()));
        $transaction->onBoundary();
        self::assertSame([$committed], $this->dispatched);
        self::assertSame(0, $transaction->countPending());
    }

    #[Test]
    public function aThrowingListenerIsLoggedAndTheOtherEventsAreStillDispatched(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            if ($event instanceof RuntimeEnvVariableChangedEvent && $event->name === 'boom') {
                throw new RuntimeException('listener');
            }
            $this->dispatched[] = $event;

            return $event;
        });
        $transaction = new RuntimeEnvTransaction($this->manager(), $dispatcher, logger: $logger);

        $transaction->record(true, new RuntimeEnvVariableChangedEvent('boom', 'u', new DateTimeImmutable()), $ok = new RuntimeEnvVariableChangedEvent('ok', 'u', new DateTimeImmutable()));
        self::assertSame([$ok], $this->dispatched);
    }

    #[Test]
    public function hostModeNeverFlushesAndWaitsForTheHostFlushOfTheBundleManager(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $em->method('getConnection')->willReturn($connection);
        $transaction = new RuntimeEnvTransaction($em, $this->dispatcher(), hostControlsTransaction: true);
        self::assertTrue($transaction->isHostControlled());

        $transaction->commit(new RuntimeEnvVariableChangedEvent('k', 'u', new DateTimeImmutable()));
        self::assertSame(0, $transaction->dispatchPending(), 'not flushed yet');

        $transaction->postFlush(new PostFlushEventArgs($this->createStub(EntityManagerInterface::class)));
        self::assertSame([], $this->dispatched, 'another manager flushed');

        $transaction->postFlush(new PostFlushEventArgs($em));
        self::assertCount(1, $this->dispatched);
    }

    #[Test]
    public function hostModeStillFlushesDuringTheBundleOwnAdminRequests(): void
    {
        $stack = new RequestStack();
        $em    = $this->manager(flushes: true);

        $transaction = new RuntimeEnvTransaction($em, $this->dispatcher(), true, requestStack: $stack);
        self::assertFalse($transaction->flushesItself(), 'no request (CLI / host use case)');

        $hostRequest = Request::create('/host');
        $hostRequest->attributes->set('_controller', 'App\\Controller\\PublishController::__invoke');
        $stack->push($hostRequest);
        self::assertFalse($transaction->flushesItself(), 'host controller: the host commits');
        $stack->pop();

        $adminRequest = Request::create('/admin/runtime-env');
        $adminRequest->attributes->set('_controller', 'Nowo\\RuntimeEnvBundle\\Controller\\RuntimeEnvManageController::create');
        $stack->push($adminRequest);
        self::assertTrue($transaction->flushesItself());

        $transaction->commit(new RuntimeEnvVariableChangedEvent('k', 'u', new DateTimeImmutable()));
        self::assertCount(1, $this->dispatched, 'flushed by the bundle UI: dispatched after its commit');
    }

    #[Test]
    public function boundaryDispatchesCommittedAndDiscardsTheRestWithAWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $transaction = new RuntimeEnvTransaction($this->manager(), $this->dispatcher(), true, $logger);

        $transaction->onBoundary();
        $transaction->discardUnflushed();
        $transaction->commit(new RuntimeEnvVariableChangedEvent('never', 'flushed', new DateTimeImmutable()));
        $transaction->onBoundary();

        self::assertSame([], $this->dispatched);
        self::assertSame(0, $transaction->countPending());
    }

    #[Test]
    public function resetKeepsNothing(): void
    {
        $this->transactionActive = true;
        $transaction             = new RuntimeEnvTransaction($this->manager());
        $transaction->commit(new RuntimeEnvVariableChangedEvent('k', 'u', new DateTimeImmutable()));
        self::assertSame(1, $transaction->countPending());

        $transaction->reset();
        self::assertSame(0, $transaction->countPending());

        $this->transactionActive = false;
        $transaction->commit(new RuntimeEnvVariableChangedEvent('k', 'u', new DateTimeImmutable()));
        self::assertSame(0, $transaction->countPending(), 'no dispatcher: dispatched to nobody');
    }

    #[Test]
    public function subscribesToEveryBoundary(): void
    {
        $events = RuntimeEnvTransaction::getSubscribedEvents();
        self::assertSame(['onRequest', PHP_INT_MAX], $events[KernelEvents::REQUEST]);
        self::assertSame(['onFinishRequest', PHP_INT_MAX], $events[KernelEvents::FINISH_REQUEST]);
        self::assertSame([['onHttpTerminateStarted', PHP_INT_MAX], ['onHttpTerminate', 1024]], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onHttpException', -1024], $events[KernelEvents::EXCEPTION]);
        self::assertSame(['onConsoleCommand', PHP_INT_MAX], $events[ConsoleEvents::COMMAND]);
        self::assertSame([['onConsoleErrorStarted', PHP_INT_MAX], ['onConsoleError', -1024]], $events[ConsoleEvents::ERROR]);
        self::assertSame([['onConsoleTerminateStarted', PHP_INT_MAX], ['onConsoleTerminate', 1024]], $events[ConsoleEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', PHP_INT_MAX], $events[RuntimeEnvTransaction::MESSENGER_WORKER_MESSAGE_RECEIVED]);
        self::assertSame([['onMessageFinishedStarted', PHP_INT_MAX], ['onMessageFinished', 1024]], $events[RuntimeEnvTransaction::MESSENGER_WORKER_MESSAGE_HANDLED]);
        self::assertSame([['onMessageFinishedStarted', PHP_INT_MAX], ['onMessageFinished', -1024]], $events[RuntimeEnvTransaction::MESSENGER_WORKER_MESSAGE_FAILED]);
        self::assertSame(['onMessageFinishedStarted', PHP_INT_MAX], $events[RuntimeEnvTransaction::MESSENGER_WORKER_MESSAGE_SKIPPED]);
        self::assertSame(['onWorkerStopped', PHP_INT_MAX], $events[RuntimeEnvTransaction::MESSENGER_WORKER_STOPPED]);
        self::assertCount(12, $events);
    }

    /**
     * Audits NM / NL2-v: unflushed events are discarded at the OUTERMOST boundary only. A nested command, a request
     * handled inside a command, a message inside a command, a command inside a request, an unknown nested command and
     * a failed nested command keep the outer unit's unflushed events; the outermost boundary still discards them.
     */
    #[Test]
    public function nestedBoundariesKeepTheOuterUnitsUnflushedEvents(): void
    {
        $transaction = new RuntimeEnvTransaction($this->manager(), $this->dispatcher(), true);
        $outer       = $this->commandEvent('app:outer');
        $transaction->onConsoleCommand($outer);
        $transaction->commit(new RuntimeEnvVariableChangedEvent('outer', 'u', new DateTimeImmutable()));

        // A nested command (succeeds, then one that fails).
        foreach ([false, true] as $fails) {
            $nested = $this->commandEvent('app:nested');
            $transaction->onConsoleCommand($nested);
            if ($fails) {
                $error = new ConsoleErrorEvent($nested->getInput(), $nested->getOutput(), new RuntimeException('failed'), $nested->getCommand());
                $transaction->onConsoleErrorStarted($error);
                $transaction->onConsoleError($error);
            }
            $this->terminate($transaction, $nested);
            self::assertSame(1, $transaction->countPending(), 'a nested command keeps the outer events');
        }

        // An unknown nested command: console.error without a command, no terminate.
        $unknown = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('not found'));
        $transaction->onConsoleErrorStarted($unknown);
        $transaction->onConsoleError($unknown);
        self::assertSame(1, $transaction->countPending(), 'an unknown nested command keeps the outer events');

        // A request handled in-process inside the command, failing.
        $kernel  = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');
        $transaction->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $transaction->onHttpException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('failed')));
        $transaction->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $transaction->onHttpTerminate();
        self::assertSame(1, $transaction->countPending(), 'a request inside a command keeps the outer events');

        // A message handled inside the command (messenger:consume): each message is its own unit.
        $transaction->onMessageReceived();
        $transaction->onMessageFinishedStarted();
        $transaction->onMessageFinished();
        self::assertSame(0, $transaction->countPending(), 'a message is its own unit of work');

        $transaction->commit(new RuntimeEnvVariableChangedEvent('outer-again', 'u', new DateTimeImmutable()));
        $this->terminate($transaction, $outer);
        self::assertSame(0, $transaction->countPending(), 'the outermost command discards');

        // A command run from a controller: the request is the outer unit.
        $transaction->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $transaction->commit(new RuntimeEnvVariableChangedEvent('request', 'u', new DateTimeImmutable()));
        $inRequest = $this->commandEvent('app:in-request');
        $transaction->onConsoleCommand($inRequest);
        $this->terminate($transaction, $inRequest);
        self::assertSame(1, $transaction->countPending(), 'a command inside a request keeps the request events');
        $transaction->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $transaction->onHttpTerminate();
        self::assertSame(0, $transaction->countPending(), 'the outermost request discards');

        // A message stopped without handled / failed: the worker stop ends it.
        $transaction->onMessageReceived();
        $transaction->onWorkerStopped();
        $transaction->commit(new RuntimeEnvVariableChangedEvent('after-worker', 'u', new DateTimeImmutable()));
        $transaction->onHttpTerminate();
        self::assertSame(0, $transaction->countPending(), 'nothing runs: the boundary discards');
    }

    /**
     * Audit NL2-v: a command whose console.error listener throws never terminates; its frame is dropped at the next
     * main request, which is the outermost unit again. kernel.reset keeps the frames (only the events are dropped).
     */
    #[Test]
    public function aFailedRunIsHealedAndResetKeepsTheFrames(): void
    {
        $transaction = new RuntimeEnvTransaction($this->manager(), $this->dispatcher(), true);
        $failed      = $this->commandEvent('app:failed');
        $transaction->onConsoleCommand($failed);
        $transaction->onConsoleErrorStarted(new ConsoleErrorEvent($failed->getInput(), $failed->getOutput(), new RuntimeException('failed'), $failed->getCommand()));

        $kernel  = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/');
        $transaction->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $transaction->commit(new RuntimeEnvVariableChangedEvent('request', 'u', new DateTimeImmutable()));
        $transaction->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $transaction->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $transaction->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $transaction->onHttpTerminate();
        self::assertSame(0, $transaction->countPending(), 'the failed run was healed: the request is the outermost unit');

        $outer = $this->commandEvent('app:outer');
        $transaction->onConsoleCommand($outer);
        $transaction->reset();
        $transaction->commit(new RuntimeEnvVariableChangedEvent('after-reset', 'u', new DateTimeImmutable()));
        $transaction->onHttpTerminate();
        self::assertSame(1, $transaction->countPending(), 'kernel.reset keeps the running command: a request inside it does not discard');
        $this->terminate($transaction, $outer);
        self::assertSame(0, $transaction->countPending());
    }

    private function commandEvent(string $name): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($name), new ArrayInput([]), new NullOutput());
    }

    private function terminate(RuntimeEnvTransaction $transaction, ConsoleCommandEvent $started): void
    {
        $command = $started->getCommand();
        self::assertInstanceOf(Command::class, $command);
        $terminate = new ConsoleTerminateEvent($command, $started->getInput(), $started->getOutput(), 0);
        $transaction->onConsoleTerminateStarted($terminate);
        $transaction->onConsoleTerminate($terminate);
    }

    private function manager(bool $flushes = false): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(fn (): bool => $this->transactionActive);
        if ($flushes) {
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects(self::once())->method('flush');
        } else {
            $em = $this->createStub(EntityManagerInterface::class);
        }
        $em->method('getConnection')->willReturn($connection);

        return $em;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->dispatched[] = $event;

            return $event;
        });

        return $dispatcher;
    }
}
