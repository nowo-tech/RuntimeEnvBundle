<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Persistence;

use DateTimeImmutable;
use Nowo\RuntimeEnvBundle\Event\RuntimeEnvVariableChangedEvent;
use Nowo\RuntimeEnvBundle\Persistence\RuntimeEnvOutbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(RuntimeEnvOutbox::class)]
final class RuntimeEnvOutboxTest extends TestCase
{
    #[Test]
    public function withoutATransactionEventsAreCommittedAtOnce(): void
    {
        $outbox = new RuntimeEnvOutbox();
        $outbox->hold();
        $outbox->hold($event = new RuntimeEnvVariableChangedEvent('a', 'created', new DateTimeImmutable()));

        self::assertSame(1, $outbox->countCommitted());
        self::assertSame([$event], $outbox->takeCommitted());
        self::assertSame(0, $outbox->countCommitted());
    }

    #[Test]
    public function savepointsCommitIntoTheirParentOrDropTheirEvents(): void
    {
        $outbox = new RuntimeEnvOutbox();
        $driver = new stdClass();
        $outbox->savepointCreated($driver, 'IGNORED'); // no transaction: ignored

        $outbox->transactionStarted($driver);
        $outbox->hold($outer = new RuntimeEnvVariableChangedEvent('outer', 'created', new DateTimeImmutable()));
        $outbox->savepointCreated($driver, 'DOCTRINE_2');
        $outbox->hold($released = new RuntimeEnvVariableChangedEvent('released', 'created', new DateTimeImmutable()));
        $outbox->savepointReleased($driver, 'doctrine_2');
        $outbox->savepointCreated($driver, 'DOCTRINE_2');
        $outbox->hold(new RuntimeEnvVariableChangedEvent('rolled-back', 'created', new DateTimeImmutable()));
        $outbox->savepointCreated($driver, 'DOCTRINE_3');
        $outbox->hold(new RuntimeEnvVariableChangedEvent('inner-of-rolled-back', 'created', new DateTimeImmutable()));
        $outbox->savepointRolledBack($driver, 'DOCTRINE_2');
        $outbox->savepointRolledBack($driver, 'UNKNOWN');
        self::assertSame(2, $outbox->countHeld());

        $outbox->transactionEnded($driver, true);
        self::assertSame(0, $outbox->countHeld());
        self::assertSame([$outer, $released], $outbox->takeCommitted());
    }

    #[Test]
    public function aReusedSavepointNameClosesTheOpenOneFirst(): void
    {
        $outbox = new RuntimeEnvOutbox();
        $driver = new stdClass();
        $outbox->transactionStarted($driver);
        $outbox->savepointCreated($driver, 'SP');
        $outbox->hold($first = new RuntimeEnvVariableChangedEvent('first', 'created', new DateTimeImmutable()));
        $outbox->savepointCreated($driver, 'SP');
        $outbox->hold(new RuntimeEnvVariableChangedEvent('second', 'created', new DateTimeImmutable()));
        $outbox->savepointRolledBack($driver, 'SP');
        $outbox->transactionEnded($driver, true);

        self::assertSame([$first], $outbox->takeCommitted());
    }

    #[Test]
    public function rollbackAndResetDropEverything(): void
    {
        $outbox = new RuntimeEnvOutbox();
        $driver = new stdClass();
        $outbox->transactionStarted($driver);
        $outbox->hold(new RuntimeEnvVariableChangedEvent('a', 'created', new DateTimeImmutable()));
        $outbox->transactionEnded($driver, false);
        self::assertSame([], $outbox->takeCommitted());

        $outbox->hold(new RuntimeEnvVariableChangedEvent('b', 'created', new DateTimeImmutable()));
        $outbox->transactionStarted($driver);
        $outbox->hold(new RuntimeEnvVariableChangedEvent('c', 'created', new DateTimeImmutable()));
        $outbox->reset();
        self::assertSame(0, $outbox->countCommitted() + $outbox->countHeld());
        $outbox->transactionEnded($driver, true);
        self::assertSame([], $outbox->takeCommitted());
    }
}
