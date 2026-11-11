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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;

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
        self::assertSame(['onBoundary', 1024], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onBoundary', -1024], $events[KernelEvents::EXCEPTION]);
        self::assertCount(6, $events);
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
