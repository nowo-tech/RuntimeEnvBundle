<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\RuntimeSetting\Provider;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\DbalQueryValueProvider;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\ReadOnlyQuery;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fopen;
use function fwrite;
use function rewind;
use function str_repeat;

use const DATE_ATOM;

/**
 * 1.10: the DBAL query provider on SQLite (real SQL, real binding); other platforms in
 * tests/Integration/Database/DbalQueryValueProviderDatabaseTest.
 */
final class DbalQueryValueProviderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE tenant_setting (tenant VARCHAR(16) NOT NULL, ns VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, value TEXT NULL, prev TEXT NULL, prev_until VARCHAR(64) NULL, weight INTEGER NULL, active BOOLEAN NOT NULL)');
        $this->insert('acme', 'env', 'API_URL', 'https://acme.example');
        $this->insert('acme', 'chat', 'retention_days', '45', '30', '2026-11-27 13:00:00');
        $this->insert('beta', 'env', 'API_URL', 'https://beta.example');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testReadsTheRowForTheIdAndTheConfiguredConstants(): void
    {
        $provider = $this->provider('SELECT value FROM tenant_setting WHERE tenant = :tenant AND name = :key AND ns = :namespace', ['tenant' => 'beta']);

        self::assertSame('https://beta.example', $provider->read(RuntimeSettingId::fromString('API_URL'))?->current);
        self::assertNull($provider->read(RuntimeSettingId::fromString('OTHER')));
    }

    public function testBindsTheFullIdAndTypedConstants(): void
    {
        $this->connection->executeStatement("UPDATE tenant_setting SET weight = 3 WHERE name = 'API_URL' AND tenant = 'acme'");
        $provider = $this->provider(
            'SELECT value FROM tenant_setting WHERE tenant || :sep || name = :id_with_tenant AND weight = :weight AND active = :active AND (:nothing IS NULL) AND :ratio > 0 AND name = :id',
            ['sep' => '/', 'id_with_tenant' => 'acme/API_URL', 'weight' => 3, 'active' => true, 'nothing' => null, 'ratio' => 1.5],
        );

        self::assertSame('https://acme.example', $provider->read(RuntimeSettingId::fromString('API_URL'))?->current);
    }

    public function testANullValueIsNoValue(): void
    {
        $this->insert('gamma', 'env', 'API_URL', null);

        self::assertNull($this->provider('SELECT value FROM tenant_setting WHERE tenant = :tenant AND name = :key', ['tenant' => 'gamma'])->read(RuntimeSettingId::fromString('API_URL')));
    }

    public function testNumbersAndBooleansBecomeStrings(): void
    {
        self::assertSame('7', $this->provider('SELECT 7 AS value FROM tenant_setting WHERE name = :key LIMIT 1')->read(RuntimeSettingId::fromString('API_URL'))?->current);
        self::assertSame('2.5', $this->provider('SELECT 2.5 AS value FROM tenant_setting WHERE name = :key LIMIT 1')->read(RuntimeSettingId::fromString('API_URL'))?->current);
    }

    public function testMoreThanOneRowIsAFailure(): void
    {
        $this->expectException(RuntimeSettingSourceFailure::class);
        $this->expectExceptionMessage('Runtime value provider "tenant": the query returned more than one row.');

        $this->provider('SELECT value FROM tenant_setting WHERE name = :key')->read(RuntimeSettingId::fromString('API_URL'));
    }

    public function testTheRotationWindowComesFromTheConfiguredColumns(): void
    {
        $stored = $this->rotation()->read(RuntimeSettingId::fromString('chat.retention_days'));

        self::assertSame('45', $stored?->current);
        self::assertSame('30', $stored?->previousAt(new DateTimeImmutable('2026-11-27 12:59:59 UTC')));
        self::assertNull($stored?->previousAt(new DateTimeImmutable('2026-11-27 13:00:00 UTC')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expiries(): iterable
    {
        yield 'unix timestamp' => ['1795770000', '2026-11-27T09:00:00+00:00'];
        yield 'ISO with Z' => ['2026-11-27T09:00:00Z', '2026-11-27T09:00:00+00:00'];
        yield 'with offset' => ['2026-11-27 10:00:00+01', '2026-11-27T09:00:00+00:00'];
        yield 'with fraction and offset' => ['2026-11-27 10:00:00.123456+01:00', '2026-11-27T09:00:00+00:00'];
    }

    #[DataProvider('expiries')]
    public function testExpiryFormats(string $raw, string $expected): void
    {
        $this->connection->executeStatement('UPDATE tenant_setting SET prev_until = :raw WHERE name = :name', ['raw' => $raw, 'name' => 'retention_days']);

        $expires = $this->rotation()->read(RuntimeSettingId::fromString('chat.retention_days'))?->previousExpiresAt;

        self::assertSame($expected, $expires?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function testExpiriesWithoutOffsetUseTheConfiguredZone(): void
    {
        $provider = new DbalQueryValueProvider($this->connection, $this->query('SELECT value, prev, prev_until FROM tenant_setting WHERE name = :key', [], 'prev', 'prev_until'), 'Europe/Madrid');

        $expires = $provider->read(RuntimeSettingId::fromString('chat.retention_days'))?->previousExpiresAt;

        self::assertSame('2026-11-27T12:00:00+00:00', $expires?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function testAPreviousValueWithoutExpiryIsNotAccepted(): void
    {
        $this->connection->executeStatement("UPDATE tenant_setting SET prev_until = NULL WHERE name = 'retention_days'");

        $stored = $this->rotation()->read(RuntimeSettingId::fromString('chat.retention_days'));

        self::assertSame('45', $stored?->current);
        self::assertNull($stored?->previous);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badExpiries(): iterable
    {
        yield 'words' => ['tomorrow'];
        yield 'too long' => [str_repeat('1', 65)];
        yield 'impossible date' => ['2026-13-45 99:99:99'];
    }

    #[DataProvider('badExpiries')]
    public function testABadExpiryIsAFailure(string $raw): void
    {
        $this->connection->executeStatement('UPDATE tenant_setting SET prev_until = :raw WHERE name = :name', ['raw' => $raw, 'name' => 'retention_days']);

        $this->expectException(RuntimeSettingSourceFailure::class);

        $this->rotation()->read(RuntimeSettingId::fromString('chat.retention_days'));
    }

    public function testAnOversizedValueIsAFailureBeforeParsing(): void
    {
        $this->insert('big', 'env', 'API_URL', str_repeat('x', 11));
        $provider = new DbalQueryValueProvider($this->connection, $this->query('SELECT value FROM tenant_setting WHERE tenant = :tenant AND name = :key', ['tenant' => 'big'], maxLength: 10));

        $this->expectException(RuntimeSettingSourceFailure::class);
        $this->expectExceptionMessage('the value is longer than 10 bytes');

        $provider->read(RuntimeSettingId::fromString('API_URL'));
    }

    public function testAMissingColumnIsAFailure(): void
    {
        $this->expectException(RuntimeSettingSourceFailure::class);
        $this->expectExceptionMessage('the result has no "value" column');

        $this->provider('SELECT name FROM tenant_setting WHERE tenant = :tenant AND name = :key', ['tenant' => 'beta'])->read(RuntimeSettingId::fromString('API_URL'));
    }

    public function testColumnsMatchCaseInsensitivelyWhenUnambiguous(): void
    {
        $provider = new DbalQueryValueProvider($this->connection, $this->query('SELECT value AS "VALUE" FROM tenant_setting WHERE tenant = :tenant AND name = :key', ['tenant' => 'beta'], valueColumn: 'Value'));

        self::assertSame('https://beta.example', $provider->read(RuntimeSettingId::fromString('API_URL'))?->current);
    }

    public function testAmbiguousFoldedColumnsAreAFailure(): void
    {
        $provider = new DbalQueryValueProvider($this->connection, $this->query('SELECT value AS "VALUE", name AS "value" FROM tenant_setting WHERE tenant = :tenant AND name = :key', ['tenant' => 'beta'], valueColumn: 'Value'));

        $this->expectException(RuntimeSettingSourceFailure::class);

        $provider->read(RuntimeSettingId::fromString('API_URL'));
    }

    public function testAFailingQueryNeverLeaksTheSqlOrTheValues(): void
    {
        $provider = $this->provider('SELECT value FROM missing_table WHERE name = :key AND tenant = :tenant', ['tenant' => 'REDACTED-TEST-TENANT']);

        try {
            $provider->read(RuntimeSettingId::fromString('API_URL'));
            self::fail('Expected an exception.');
        } catch (RuntimeSettingSourceFailure $e) {
            self::assertMatchesRegularExpression('/^Runtime value provider "tenant": the query failed \([A-Za-z\\\\]+\)\.$/', $e->getMessage());
            self::assertStringNotContainsString('missing_table', $e->getMessage());
            self::assertStringNotContainsString('REDACTED', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testLobStreamsAreReadWithinTheBound(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, 'from-a-lob');
        rewind($stream);

        self::assertSame('from-a-lob', $this->fakeRows([['value' => $stream]])->read(RuntimeSettingId::fromString('API_URL'))?->current);

        $long = fopen('php://memory', 'r+');
        self::assertNotFalse($long);
        fwrite($long, str_repeat('x', 11));
        rewind($long);

        $this->expectException(RuntimeSettingSourceFailure::class);
        $this->expectExceptionMessage('longer than 10 bytes');

        $this->fakeRows([['value' => $long]], 10)->read(RuntimeSettingId::fromString('API_URL'));
    }

    public function testANonScalarColumnIsAFailure(): void
    {
        $this->expectException(RuntimeSettingSourceFailure::class);
        $this->expectExceptionMessage('the value column is not a readable scalar');

        $this->fakeRows([['value' => ['nested']]])->read(RuntimeSettingId::fromString('API_URL'));
    }

    public function testABooleanColumnIsOneOrZero(): void
    {
        self::assertSame('1', $this->fakeRows([['value' => true]])->read(RuntimeSettingId::fromString('API_URL'))?->current);
        self::assertSame('0', $this->fakeRows([['value' => false]])->read(RuntimeSettingId::fromString('API_URL'))?->current);
    }

    public function testAFetchFailureIsSanitisedToo(): void
    {
        $driverResult = $this->createMock(DriverResult::class);
        $driverResult->method('fetchAssociative')->willThrowException(new RuntimeException('SELECT REDACTED'));
        $driverResult->expects(self::once())->method('free');

        try {
            $this->providerOver($driverResult)->read(RuntimeSettingId::fromString('API_URL'));
            self::fail('Expected an exception.');
        } catch (RuntimeSettingSourceFailure $e) {
            self::assertSame('Runtime value provider "tenant": the query failed (RuntimeException).', $e->getMessage());
        }
    }

    /**
     * ZDA-L3: inside the caller's transaction the read runs in a savepoint; a failure rolls back to it and the
     * caller's transaction stays usable.
     */
    public function testInsideATransactionTheReadUsesASavepoint(): void
    {
        $this->connection->beginTransaction();
        $this->connection->executeStatement("UPDATE tenant_setting SET value = 'uncommitted' WHERE tenant = 'beta'");

        self::assertSame('uncommitted', $this->provider('SELECT value FROM tenant_setting WHERE tenant = :tenant AND name = :key', ['tenant' => 'beta'])->read(RuntimeSettingId::fromString('API_URL'))?->current);
        self::assertTrue($this->connection->isTransactionActive());
        self::assertSame(1, $this->connection->getTransactionNestingLevel());

        try {
            $this->provider('SELECT value FROM missing_table WHERE name = :key')->read(RuntimeSettingId::fromString('API_URL'));
            self::fail('Expected an exception.');
        } catch (RuntimeSettingSourceFailure $e) {
            self::assertNull($e->getPrevious());
        }

        // The caller's unit of work goes on and commits.
        $this->connection->executeStatement("UPDATE tenant_setting SET value = 'committed' WHERE tenant = 'beta'");
        $this->connection->commit();
        self::assertSame('committed', $this->connection->fetchOne("SELECT value FROM tenant_setting WHERE tenant = 'beta'"));
    }

    public function testASavepointThatCannotBeCreatedIsAFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('createSavepoint')->willThrowException(new RuntimeException('SAVEPOINT REDACTED'));
        $connection->expects(self::never())->method('executeQuery');

        $this->expectException(RuntimeSettingSourceFailure::class);
        $this->expectExceptionMessage('Runtime value provider "tenant": the savepoint could not be created (RuntimeException).');

        (new DbalQueryValueProvider($connection, $this->query('SELECT value FROM t WHERE name = :key')))->read(RuntimeSettingId::fromString('API_URL'));
    }

    public function testALostSavepointClosesTheConnectionAndRethrowsTheOriginalFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('createSavepoint')->with(DbalQueryValueProvider::SAVEPOINT);
        $connection->method('executeQuery')->willThrowException(new RuntimeException('SELECT REDACTED'));
        $connection->expects(self::once())->method('rollbackSavepoint')->with(DbalQueryValueProvider::SAVEPOINT)->willThrowException(new RuntimeException('lost'));
        $connection->expects(self::once())->method('close');

        $this->expectException(RuntimeSettingSourceFailure::class);
        $this->expectExceptionMessage('Runtime value provider "tenant": the query failed (RuntimeException).');

        (new DbalQueryValueProvider($connection, $this->query('SELECT value FROM t WHERE name = :key')))->read(RuntimeSettingId::fromString('API_URL'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function fakeRows(array $rows, int $maxLength = 65536): DbalQueryValueProvider
    {
        $driverResult = $this->createMock(DriverResult::class);
        $driverResult->method('fetchAssociative')->willReturnOnConsecutiveCalls(...[...$rows, false]);

        return $this->providerOver($driverResult, $maxLength);
    }

    private function providerOver(DriverResult $driverResult, int $maxLength = 65536): DbalQueryValueProvider
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(static fn (): Result => new Result($driverResult, $connection));

        return new DbalQueryValueProvider($connection, $this->query('SELECT value FROM t WHERE name = :key', maxLength: $maxLength));
    }

    private function rotation(): DbalQueryValueProvider
    {
        return new DbalQueryValueProvider($this->connection, $this->query('SELECT value, prev, prev_until FROM tenant_setting WHERE ns = :namespace AND name = :key', [], 'prev', 'prev_until'));
    }

    /**
     * @param array<string, bool|float|int|string|null> $parameters
     */
    private function provider(string $sql, array $parameters = []): DbalQueryValueProvider
    {
        return new DbalQueryValueProvider($this->connection, $this->query($sql, $parameters));
    }

    /**
     * @param array<string, bool|float|int|string|null> $parameters
     */
    private function query(string $sql, array $parameters = [], ?string $previous = null, ?string $expires = null, int $maxLength = 65536, string $valueColumn = 'value'): ReadOnlyQuery
    {
        return new ReadOnlyQuery('tenant', $sql, $parameters, $valueColumn, $previous, $expires, $maxLength);
    }

    private function insert(string $tenant, string $namespace, string $name, ?string $value, ?string $previous = null, ?string $until = null): void
    {
        $this->connection->insert('tenant_setting', ['tenant' => $tenant, 'ns' => $namespace, 'name' => $name, 'value' => $value, 'prev' => $previous, 'prev_until' => $until, 'active' => true], ['active' => 'boolean']);
    }
}
