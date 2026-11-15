<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\RuntimeSetting;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\RuntimeEnvBundle\Event\RuntimeEnvVariableChangedEvent;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Tests\Support\RuntimeSettingDatabaseTrait;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Clock\MockClock;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function json_encode;
use function str_repeat;

use const DATE_ATOM;

final class RuntimeEnvSettingStoreTest extends TestCase
{
    use RuntimeSettingDatabaseTrait;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-16 12:00:00');
    }

    public function testReadsEnabledVariablesOnly(): void
    {
        $connection = $this->database();
        $this->insertVariable($connection, 'API_TOKEN', 'enc:TSET-DETCADER');
        $this->insertVariable($connection, 'OFF_TOKEN', 'enc:x', false);
        $store = $this->store($connection);

        self::assertSame('REDACTED-TEST', $store->read(RuntimeSettingId::fromString('API_TOKEN'))?->current);
        self::assertNull($store->read(RuntimeSettingId::fromString('API_TOKEN'))?->previous);
        self::assertNull($store->read(RuntimeSettingId::fromString('OFF_TOKEN')), 'disabled = unset');
        self::assertNull($store->read(RuntimeSettingId::fromString('MISSING')));
        self::assertNull($store->read(RuntimeSettingId::fromString('chat.days')), 'namespace.key ids are not stored here');
    }

    public function testReadsThePreviousValueOfARotation(): void
    {
        $connection = $this->database();
        $expires    = new DateTimeImmutable('2026-11-16 13:00:00');
        $this->insertVariable($connection, 'API_TOKEN', 'enc:wen', true, 'enc:dlo', $expires);

        $stored = $this->store($connection)->read(RuntimeSettingId::fromString('API_TOKEN'));

        self::assertNotNull($stored);
        self::assertSame('new', $stored->current);
        self::assertSame('old', $stored->previous);
        self::assertEquals($expires, $stored->previousExpiresAt);
    }

    public function testTheVersionTokenChangesOnEveryWriteAndIsNeverReused(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection);
        $definition = $this->definition();

        self::assertSame(RuntimeEnvSettingStore::INITIAL_VERSION, $store->namespaceVersion('env'));
        $seen = [];
        foreach (['a', 'a', 'b'] as $value) {
            $change = $store->set($definition, $value, $this->context());
            self::assertNotContains($change->version, $seen, 'an identical write still bumps (Delta QS-D-I)');
            self::assertSame($change->version, $store->namespaceVersion('env'));
            $seen[] = $change->version;
        }
        self::assertSame(RuntimeEnvSettingStore::INITIAL_VERSION, $store->namespaceVersion('chat'));
    }

    public function testSetInsertsThenUpdatesEncryptedAndEnables(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection, clock: $this->clock);
        $definition = $this->definition();

        $first = $store->set($definition, 'one', $this->context());
        self::assertSame('enc:eno', $connection->fetchOne("SELECT value FROM runtime_env_variables WHERE name = 'API_TOKEN'"));
        self::assertNull($first->oldFingerprint);

        $connection->executeStatement("UPDATE runtime_env_variables SET enabled = 0, description = 'kept' WHERE name = 'API_TOKEN'");
        $second = $store->set($definition, 'two', $this->context());

        self::assertNull($second->oldFingerprint, 'a disabled value is unset');
        self::assertSame('two', $store->read($definition->id)?->current);
        self::assertSame('kept', $connection->fetchOne("SELECT description FROM runtime_env_variables WHERE name = 'API_TOKEN'"));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM runtime_env_variables'));
    }

    public function testFingerprintsForPlainValuesNoneForSecrets(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection);
        $plain      = $this->definition();
        $secret     = $this->definition('SECRET_TOKEN', true);

        $store->set($plain, 'one', $this->context());
        $change = $store->set($plain, 'two', $this->context());
        self::assertNotNull($change->oldFingerprint);
        self::assertNotNull($change->newFingerprint);
        self::assertFalse($change->secret);

        $store->set($secret, 'REDACTED-TEST-A', $this->context());
        $secretChange = $store->set($secret, 'REDACTED-TEST-B', $this->context());
        self::assertNull($secretChange->oldFingerprint);
        self::assertNull($secretChange->newFingerprint);
        self::assertTrue($secretChange->secret);
    }

    public function testUnsetDisablesTheRowAndAlwaysBumps(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection);
        $definition = $this->definition();
        $store->rotate($definition, 'one', $this->clock->now()->modify('+1 hour'), $this->context());
        $store->rotate($definition, 'two', $this->clock->now()->modify('+1 hour'), $this->context());

        $unset = $store->unset($definition, $this->context());
        self::assertSame(RuntimeSettingAction::Unset, $unset->action);
        self::assertNull($store->read($definition->id));
        self::assertSame([0, null], [(int) $connection->fetchOne('SELECT enabled FROM runtime_env_variables'), $connection->fetchOne('SELECT previous_value FROM runtime_env_variables')]);

        $missing = $store->unset($this->definition('NEVER_SET'), $this->context());
        self::assertNotSame($unset->version, $missing->version, 'unsetting an absent variable still bumps and audits');
    }

    public function testRotateKeepsTheStoredCiphertextAsPrevious(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection, clock: $this->clock);
        $definition = $this->definition();
        $expires    = new DateTimeImmutable('2026-11-16 13:00:00');

        $first = $store->rotate($definition, 'one', $expires, $this->context());
        self::assertSame(RuntimeSettingAction::Rotate, $first->action);
        self::assertNull($store->read($definition->id)?->previous, 'nothing to keep on the first rotation');

        $store->rotate($definition, 'two', $expires, $this->context());
        self::assertSame('enc:eno', $connection->fetchOne('SELECT previous_value FROM runtime_env_variables'));
        $stored = $store->read($definition->id);
        self::assertSame('two', $stored?->current);
        self::assertSame('one', $stored?->previous);
        self::assertEquals($expires, $stored?->previousExpiresAt);

        $connection->executeStatement('UPDATE runtime_env_variables SET enabled = 0');
        $store->rotate($definition, 'three', $expires, $this->context());
        self::assertNull($store->read($definition->id)?->previous, 'a disabled value is not kept');
    }

    public function testExpiredPreviousValuesArePurgedConditionally(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection, clock: $this->clock);
        $this->insertVariable($connection, 'OLD_TOKEN', 'enc:a', true, 'enc:b', new DateTimeImmutable('2026-11-16 11:00:00'));
        $this->insertVariable($connection, 'LIVE_TOKEN', 'enc:a', true, 'enc:b', new DateTimeImmutable('2026-11-16 14:00:00'));

        self::assertSame(0, $store->purgeExpired(new DateTimeImmutable('2026-11-16 10:00:00')));
        self::assertSame(RuntimeEnvSettingStore::INITIAL_VERSION, $store->namespaceVersion('env'), 'nothing purged, no bump');

        self::assertSame(1, $store->purgeExpired($this->clock->now()));
        self::assertNull($store->read(RuntimeSettingId::fromString('OLD_TOKEN'))?->previous);
        self::assertSame('b', $store->read(RuntimeSettingId::fromString('LIVE_TOKEN'))?->previous);
        self::assertNotSame(RuntimeEnvSettingStore::INITIAL_VERSION, $store->namespaceVersion('env'));
    }

    public function testAWritePurgesOnlyItsOwnRowTheSweepIsPurgeExpired(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection, clock: $this->clock);
        $this->insertVariable($connection, 'OLD_TOKEN', 'enc:a', true, 'enc:b', new DateTimeImmutable('2026-11-16 11:00:00'));
        $definition = $this->definition();
        $store->rotate($definition, 'one', new DateTimeImmutable('2026-11-16 12:00:00'), $this->context());
        $store->rotate($definition, 'two', new DateTimeImmutable('2026-11-16 12:00:00'), $this->context());

        self::assertSame('enc:b', $connection->fetchOne("SELECT previous_value FROM runtime_env_variables WHERE name = 'OLD_TOKEN'"), 'another row is left to purgeExpired() (QT-L-2)');
        self::assertNull($connection->fetchOne("SELECT previous_value FROM runtime_env_variables WHERE name = 'API_TOKEN'"), 'the written row expires at once (window 0) and is purged');

        self::assertSame(1, $store->purgeExpired($this->clock->now()));
        self::assertNull($connection->fetchOne("SELECT previous_value FROM runtime_env_variables WHERE name = 'OLD_TOKEN'"));
    }

    public function testRotationExpiriesAreUtcWhateverThePhpTimezone(): void
    {
        $default    = date_default_timezone_get();
        $connection = $this->database();
        $definition = $this->definition();

        try {
            // The CLI rotates in UTC with a 1 h window ...
            date_default_timezone_set('UTC');
            $store = $this->store($connection, clock: new MockClock('2026-11-15 10:00:00', 'UTC'));
            $store->set($definition, 'old-secret', $this->context());
            $store->rotate($definition, 'new-secret', new DateTimeImmutable('2026-11-15 11:00:00', new DateTimeZone('UTC')), $this->context());
            self::assertSame('2026-11-15 11:00:00', $connection->fetchOne('SELECT previous_expires_at FROM runtime_env_variables'));

            // ... a worker in New York reads it: the window still ends at 11:00 UTC (QT-L-1).
            date_default_timezone_set('America/New_York');
            $reader = $this->store($connection);
            $stored = $reader->read($definition->id);
            self::assertNotNull($stored);
            self::assertSame('2026-11-15T11:00:00+00:00', $stored->previousExpiresAt?->format(DATE_ATOM));
            self::assertNull($stored->previousAt(new DateTimeImmutable('2026-11-15 13:00:00', new DateTimeZone('UTC'))));
            self::assertSame('old-secret', $stored->previousAt(new DateTimeImmutable('2026-11-15 05:59:00')), '05:59 in New York is 10:59 UTC');

            // A purge from that worker at 13:00 UTC (08:00 local) removes it.
            self::assertSame(1, $reader->purgeExpired(new DateTimeImmutable('2026-11-15 08:00:00')));
        } finally {
            date_default_timezone_set($default);
        }
    }

    public function testAnUnreadableExpiryDropsThePreviousValue(): void
    {
        $connection = $this->database();
        $this->insertVariable($connection, 'API_TOKEN', 'enc:wen', true, 'enc:dlo', new DateTimeImmutable('2030-01-01'));
        $connection->executeStatement("UPDATE runtime_env_variables SET previous_expires_at = 'not a date'");

        self::assertNull($this->store($connection)->read(RuntimeSettingId::fromString('API_TOKEN'))?->previous);
    }

    public function testUnsetOfASecretEmptiesTheValue(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection);
        $store->set($this->definition('SECRET_TOKEN', true), 'REDACTED-TEST', $this->context());
        $store->set($this->definition(), 'plain', $this->context());

        $store->unset($this->definition('SECRET_TOKEN', true), $this->context());
        $store->unset($this->definition(), $this->context());

        self::assertSame('', $connection->fetchOne("SELECT value FROM runtime_env_variables WHERE name = 'SECRET_TOKEN'"), 'QT-I-4');
        self::assertSame('enc:nialp', $connection->fetchOne("SELECT value FROM runtime_env_variables WHERE name = 'API_TOKEN'"), 'a plain value stays for the admin');
    }

    public function testTheVersionRowIsEnsuredThenUpdated(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection);
        $connection->insert('runtime_env_versions', ['namespace' => 'env', 'token' => RuntimeEnvSettingStore::INITIAL_VERSION]);

        $change = $store->set($this->definition(), 'x', $this->context());

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM runtime_env_versions'));
        self::assertSame($change->version, $store->namespaceVersion('env'));
    }

    public function testWritesInsideTheCallersTransactionAreRefused(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection);
        $connection->beginTransaction();

        foreach ([
            fn (): RuntimeSettingChange => $store->set($this->definition(), 'x', $this->context()),
            static fn (): int => $store->purgeExpired(new DateTimeImmutable()),
        ] as $call) {
            try {
                $call();
                self::fail('Expected RuntimeSettingNotWritable.');
            } catch (RuntimeSettingNotWritable $e) {
                self::assertStringContainsString('own transaction', $e->getMessage());
            }
        }
        $connection->rollBack();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM runtime_env_variables'));
    }

    public function testNamespacedIdsCannotBeWritten(): void
    {
        $this->expectException(RuntimeSettingNotWritable::class);
        $this->expectExceptionMessage('environment variable names only');

        $this->store($this->database())->set(new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::Db, validator: new Unbounded()), '1', $this->context());
    }

    public function testAFailedWriteRollsBackValueAndVersion(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection);
        $definition = $this->definition();
        $store->set($definition, 'one', $this->context());
        $version = $store->namespaceVersion('env');
        $connection->executeStatement('DROP TABLE runtime_env_versions');
        $connection->executeStatement('CREATE TABLE runtime_env_versions (namespace VARCHAR(64) NOT NULL PRIMARY KEY, token VARCHAR(2) NOT NULL CHECK (length(token) < 3))');
        $connection->insert('runtime_env_versions', ['namespace' => 'env', 'token' => 'v0']);

        try {
            $store->set($definition, 'two', $this->context());
            self::fail('Expected the version write to fail.');
        } catch (DbalException) {
        }

        self::assertFalse($connection->isTransactionActive());
        self::assertSame('one', $store->read($definition->id)?->current, 'the value was rolled back with the version');
        self::assertSame('v0', $store->namespaceVersion('env'));
        self::assertNotSame('v0', $version);
    }

    public function testAuditLogAndBundleEventAfterCommitWithoutValues(): void
    {
        $connection = $this->database();
        $logger     = $this->logger();
        $events     = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$events, $connection): object {
            $events[] = [$event, $connection->isTransactionActive()];

            return $event;
        });
        $store  = $this->store($connection, $dispatcher, $logger, $this->clock);
        $secret = $this->definition('SECRET_TOKEN', true);

        $store->set($secret, 'REDACTED-TEST-A', $this->context());
        $store->set($secret, 'REDACTED-TEST-B', $this->context());

        self::assertCount(2, $events);
        [$first, $inTransaction] = $events[0];
        self::assertInstanceOf(RuntimeEnvVariableChangedEvent::class, $first);
        self::assertSame(RuntimeEnvVariableChangedEvent::CREATED, $first->action);
        self::assertSame('SECRET_TOKEN', $first->name);
        self::assertFalse($inTransaction, 'dispatched after the commit');
        self::assertSame(RuntimeEnvVariableChangedEvent::UPDATED, $events[1][0]->action);

        $text = (string) json_encode($logger->records);
        self::assertStringContainsString('cli:test', $text);
        self::assertStringNotContainsString('REDACTED', $text);
    }

    public function testAFailingListenerIsLoggedNotRethrown(): void
    {
        $connection = $this->database();
        $logger     = $this->logger();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('listener'));

        $change = $this->store($connection, $dispatcher, $logger)->set($this->definition(), 'x', $this->context());

        self::assertSame(RuntimeSettingAction::Set, $change->action);
        self::assertSame('error', $logger->records[1]['level']);
    }

    public function testWritesClearTheBagMemo(): void
    {
        $connection = $this->database();
        $repository = $this->createMock(RuntimeEnvVariableRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findAllEnabled')->willReturn([]);
        $bag   = new RuntimeEnvBag($repository, true);
        $store = new RuntimeEnvSettingStore($this->registryFor($connection), 'default', 'runtime_env_variables', 'runtime_env_versions', $this->cipher(), bag: $bag);
        $this->insertVariable($connection, 'OLD_TOKEN', 'enc:a', true, 'enc:b', new DateTimeImmutable('2020-01-01'));

        $bag->all();
        $store->set($this->definition(), 'x', $this->context());
        $bag->all();
        $store->purgeExpired(new DateTimeImmutable('2030-01-01'));
    }

    public function testUpdateDescription(): void
    {
        $connection = $this->database();
        $store      = $this->store($connection);
        $store->set($this->definition(), 'x', $this->context());

        $store->updateDescription('API_TOKEN', 'Payments API');

        self::assertSame('Payments API', $connection->fetchOne('SELECT description FROM runtime_env_variables'));
    }

    public function testANonOrmManagerIsRefused(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        $store = new RuntimeEnvSettingStore($registry, 'other', 'runtime_env_variables', 'runtime_env_versions', $this->cipher());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"other" is not a Doctrine ORM entity manager');

        $store->namespaceVersion('env');
    }

    public function testDefaultClockAndLogger(): void
    {
        $connection = $this->database();
        $store      = new RuntimeEnvSettingStore($this->registryFor($connection), 'default', 'runtime_env_variables', 'runtime_env_versions', $this->cipher());

        $store->set($this->definition(), 'x', $this->context());

        self::assertNotFalse($connection->fetchOne('SELECT updated_at FROM runtime_env_variables'));
    }

    private function definition(string $name = 'API_TOKEN', bool $secret = false): RuntimeSettingDefinition
    {
        return new RuntimeSettingDefinition($name, RuntimeSettingType::String, RuntimeSettingSource::Db, secret: $secret, validator: new Unbounded());
    }

    private function context(): RuntimeSettingWriteContext
    {
        return new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter(str_repeat('k', 32)));
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: mixed, message: string, context: array<mixed>}>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            /**
             * @param array<mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
