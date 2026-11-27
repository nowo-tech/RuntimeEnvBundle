<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Database;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\DbalQueryValueProvider;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\ReadOnlyQuery;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function getenv;
use function is_string;

use const DATE_ATOM;

/**
 * 1.10: the DBAL query provider on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 with native column types (CI job "Real
 * databases"; locally set RE_MYSQL_URL / RE_MARIADB_URL / RE_POSTGRES_URL). SQLite runs in the unit suite.
 */
#[Group('database')]
final class DbalQueryValueProviderDatabaseTest extends TestCase
{
    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        $this->connection?->executeStatement('DROP TABLE IF EXISTS host_setting');
        $this->connection?->close();
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function databases(): iterable
    {
        $any = false;
        foreach (['MySQL' => 'RE_MYSQL_URL', 'MariaDB' => 'RE_MARIADB_URL', 'PostgreSQL' => 'RE_POSTGRES_URL'] as $name => $variable) {
            $url = getenv($variable);
            if (is_string($url) && $url !== '') {
                $any = true;

                yield $name => [$url];
            }
        }
        if (!$any) {
            yield 'none configured' => [null];
        }
    }

    #[DataProvider('databases')]
    public function testReadsNativeTypesAndTheRotationWindow(?string $url): void
    {
        $connection = $this->connect($url);
        $provider   = new DbalQueryValueProvider($connection, new ReadOnlyQuery(
            'host',
            'SELECT val AS value, prev, prev_until FROM host_setting WHERE tenant = :tenant AND ns = :namespace AND name = :key AND active = :active',
            ['tenant' => 'acme', 'active' => true],
            'value',
            'prev',
            'prev_until',
        ));

        $stored = $provider->read(RuntimeSettingId::fromString('chat.retention_days'));
        self::assertSame('45', $stored?->current);
        self::assertSame('30', $stored?->previous);
        self::assertSame('2026-11-27T13:00:00+00:00', $stored?->previousExpiresAt?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));

        self::assertNull($provider->read(RuntimeSettingId::fromString('chat.unknown')));
        self::assertNull($provider->read(RuntimeSettingId::fromString('chat.null_value')));
    }

    #[DataProvider('databases')]
    public function testIntegerColumnsAndTheIdParameter(?string $url): void
    {
        $connection = $this->connect($url);
        $provider   = new DbalQueryValueProvider($connection, new ReadOnlyQuery('host', 'SELECT weight AS value FROM host_setting WHERE ns = :namespace AND name = :key AND weight >= :min', ['min' => 1]));

        self::assertSame('7', $provider->read(RuntimeSettingId::fromString('chat.retention_days'))?->current);
    }

    #[DataProvider('databases')]
    public function testTwoRowsAreAFailure(?string $url): void
    {
        $connection = $this->connect($url);
        $provider   = new DbalQueryValueProvider($connection, new ReadOnlyQuery('host', 'SELECT val AS value FROM host_setting WHERE name = :key'));

        $this->expectException(RuntimeSettingSourceFailure::class);
        $this->expectExceptionMessage('more than one row');

        $provider->read(RuntimeSettingId::fromString('API_URL'));
    }

    #[DataProvider('databases')]
    public function testADriverErrorIsSanitised(?string $url): void
    {
        $connection = $this->connect($url);
        $provider   = new DbalQueryValueProvider($connection, new ReadOnlyQuery('host', 'SELECT val AS value FROM host_setting_missing WHERE name = :key'));

        try {
            $provider->read(RuntimeSettingId::fromString('API_URL'));
            self::fail('Expected an exception.');
        } catch (RuntimeSettingSourceFailure $e) {
            self::assertStringNotContainsString('host_setting_missing', $e->getMessage());
            self::assertNull($e->getPrevious());
        }

        // PostgreSQL: the failed statement did not leave the connection unusable for the next read.
        self::assertSame('45', (new DbalQueryValueProvider($connection, new ReadOnlyQuery('host', 'SELECT val AS value FROM host_setting WHERE ns = :namespace AND name = :key')))->read(RuntimeSettingId::fromString('chat.retention_days'))?->current);
    }

    /**
     * ZDA-L3: a failing provider read inside the caller's transaction (shared connection) leaves it usable, also on
     * PostgreSQL, where a failed statement would otherwise abort the whole transaction (25P02).
     */
    #[DataProvider('databases')]
    public function testAFailureInsideTheCallersTransactionLeavesItUsable(?string $url): void
    {
        $connection = $this->connect($url);
        $connection->beginTransaction();
        $connection->executeStatement("UPDATE host_setting SET weight = 8 WHERE name = 'retention_days'");

        try {
            (new DbalQueryValueProvider($connection, new ReadOnlyQuery('host', 'SELECT val AS value FROM host_setting_missing WHERE name = :key')))->read(RuntimeSettingId::fromString('API_URL'));
            self::fail('Expected an exception.');
        } catch (RuntimeSettingSourceFailure) {
        }

        self::assertSame('45', (new DbalQueryValueProvider($connection, new ReadOnlyQuery('host', 'SELECT val AS value FROM host_setting WHERE ns = :namespace AND name = :key')))->read(RuntimeSettingId::fromString('chat.retention_days'))?->current);
        $connection->executeStatement("UPDATE host_setting SET weight = 9 WHERE name = 'retention_days'");
        $connection->commit();
        self::assertSame(9, (int) $connection->fetchOne("SELECT weight FROM host_setting WHERE name = 'retention_days'"));
    }

    private function connect(?string $url): Connection
    {
        if ($url === null) {
            self::markTestSkipped('Set RE_MYSQL_URL / RE_MARIADB_URL / RE_POSTGRES_URL to run against real servers.');
        }

        $connection       = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url));
        $this->connection = $connection;
        $connection->executeStatement('DROP TABLE IF EXISTS host_setting');
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('CREATE TABLE host_setting (tenant VARCHAR(16) NOT NULL, ns VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, val LONGTEXT NULL, prev LONGTEXT NULL, prev_until DATETIME NULL, weight INT NULL, active TINYINT(1) NOT NULL) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        } else {
            $connection->executeStatement('CREATE TABLE host_setting (tenant VARCHAR(16) NOT NULL, ns VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, val TEXT NULL, prev TEXT NULL, prev_until TIMESTAMP(0) WITHOUT TIME ZONE NULL, weight INT NULL, active BOOLEAN NOT NULL)');
        }
        $rows = [
            ['acme', 'chat', 'retention_days', '45', '30', '2026-11-27 13:00:00', 7, true],
            ['acme', 'chat', 'null_value', null, null, null, null, true],
            ['acme', 'env', 'API_URL', 'https://acme.example', null, null, null, true],
            ['beta', 'env', 'API_URL', 'https://beta.example', null, null, null, true],
        ];
        foreach ($rows as [$tenant, $ns, $name, $val, $prev, $until, $weight, $active]) {
            $connection->insert('host_setting', ['tenant' => $tenant, 'ns' => $ns, 'name' => $name, 'val' => $val, 'prev' => $prev, 'prev_until' => $until, 'weight' => $weight, 'active' => $active], ['active' => 'boolean']);
        }

        return $connection;
    }
}
