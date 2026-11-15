<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvValueCipherInterface;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvVersionRow;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function getenv;
use function is_string;
use function str_repeat;
use function str_starts_with;
use function strrev;
use function substr;

use const DATE_ATOM;

/**
 * 1.6, audit QT: the store's platform SQL on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (CI job "Real databases";
 * locally set RE_MYSQL_URL / RE_MARIADB_URL / RE_POSTGRES_URL).
 */
#[Group('database')]
final class RuntimeEnvSettingStoreDatabaseTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
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

    /**
     * QT-L-2: a write of B while A holds its own row (A paused inside its transaction) never waits for A, for existing
     * and for new variables, with an empty versions table too.
     */
    #[DataProvider('databases')]
    public function testWritesOfDifferentVariablesNeverWaitForEachOther(?string $url): void
    {
        foreach ([true, false] as $existingRows) {
            [$a, $b] = $this->twoConnections($url);
            if ($existingRows) {
                $setup = $this->store($a);
                $setup->set($this->definition('VAR_A'), 'a0', $this->context());
                $setup->set($this->definition('VAR_B'), 'b0', $this->context());
            }
            $storeB = $this->store($b);
            $done   = false;
            $storeA = $this->store($a, function () use ($storeB, &$done): void {
                if (!$done) {
                    $done = true;
                    // A holds VAR_A inside its transaction; B must commit without waiting (lock timeout 3 s on B).
                    $storeB->set($this->definition('VAR_B'), 'b1', $this->context());
                }
            });

            $storeA->set($this->definition('VAR_A'), 'a1', $this->context());

            self::assertTrue($done);
            self::assertSame('a1', $storeA->read(RuntimeSettingId::fromString('VAR_A'))?->current);
            self::assertSame('b1', $storeA->read(RuntimeSettingId::fromString('VAR_B'))?->current);
        }
    }

    /**
     * QT-L-3: ensuring the version row never conflicts, also inside an open transaction (PostgreSQL would abort it on
     * a failed INSERT).
     */
    #[DataProvider('databases')]
    public function testEnsuringTheVersionRowNeverConflicts(?string $url): void
    {
        [$connection] = $this->twoConnections($url);
        $connection->beginTransaction();
        RuntimeEnvVersionRow::ensure($connection, 'runtime_env_versions', 'env');
        RuntimeEnvVersionRow::ensure($connection, 'runtime_env_versions', 'env');
        $connection->update('runtime_env_versions', ['token' => 't1'], ['namespace' => 'env']);
        RuntimeEnvVersionRow::ensure($connection, 'runtime_env_versions', 'env');
        $connection->commit();

        self::assertSame('t1', $connection->fetchOne("SELECT token FROM runtime_env_versions WHERE namespace = 'env'"));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM runtime_env_versions'));
    }

    /**
     * QT-L-1 on a real DATETIME / TIMESTAMP column: the expiry is UTC whatever the PHP timezone of writer and reader.
     */
    #[DataProvider('databases')]
    public function testRotationExpiryIsUtcOnTheServer(?string $url): void
    {
        $default      = date_default_timezone_get();
        [$connection] = $this->twoConnections($url);
        $definition   = $this->definition('API_TOKEN');

        try {
            date_default_timezone_set('UTC');
            $store = $this->store($connection, null, new MockClock('2026-11-15 10:00:00', 'UTC'));
            $store->set($definition, 'old', $this->context());
            $store->rotate($definition, 'new', new DateTimeImmutable('2026-11-15 11:00:00', new DateTimeZone('UTC')), $this->context());

            date_default_timezone_set('America/New_York');
            $stored = $this->store($connection)->read($definition->id);
            self::assertSame('2026-11-15T11:00:00+00:00', $stored?->previousExpiresAt?->format(DATE_ATOM));
            self::assertSame(0, $this->store($connection)->purgeExpired(new DateTimeImmutable('2026-11-15 05:59:59')));
            self::assertSame(1, $this->store($connection)->purgeExpired(new DateTimeImmutable('2026-11-15 06:00:00')));
        } finally {
            date_default_timezone_set($default);
        }
    }

    /**
     * @return array{0: Connection, 1: Connection}
     */
    private function twoConnections(?string $url): array
    {
        if ($url === null) {
            self::markTestSkipped('Set RE_MYSQL_URL / RE_MARIADB_URL / RE_POSTGRES_URL to run against real servers.');
        }

        $params              = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $a                   = DriverManager::getConnection($params);
        $b                   = DriverManager::getConnection($params);
        $this->connections[] = $a;
        $this->connections[] = $b;

        // Without the previous_expires_at index on purpose: the writes must not depend on it (QT-L-2).
        $mysql = $a->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $a->executeStatement('DROP TABLE IF EXISTS runtime_env_variables');
        $a->executeStatement('DROP TABLE IF EXISTS runtime_env_versions');
        if ($mysql) {
            $a->executeStatement('CREATE TABLE runtime_env_variables (id INT AUTO_INCREMENT NOT NULL PRIMARY KEY, name VARCHAR(128) NOT NULL, value LONGTEXT NOT NULL, description VARCHAR(255) DEFAULT NULL, enabled TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL, previous_value LONGTEXT DEFAULT NULL, previous_expires_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_runtime_env_name (name)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
            $a->executeStatement('CREATE TABLE runtime_env_versions (namespace VARCHAR(64) NOT NULL PRIMARY KEY, token VARCHAR(128) NOT NULL) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
            $b->executeStatement('SET SESSION innodb_lock_wait_timeout = 3');
        } else {
            $a->executeStatement('CREATE TABLE runtime_env_variables (id SERIAL PRIMARY KEY, name VARCHAR(128) NOT NULL UNIQUE, value TEXT NOT NULL, description VARCHAR(255) DEFAULT NULL, enabled BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, previous_value TEXT DEFAULT NULL, previous_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
            $a->executeStatement('CREATE TABLE runtime_env_versions (namespace VARCHAR(64) NOT NULL PRIMARY KEY, token VARCHAR(128) NOT NULL)');
            $b->executeStatement("SET lock_timeout = '3s'");
        }

        return [$a, $b];
    }

    /**
     * @param (callable(): void)|null $duringEncrypt runs inside the writer's transaction (after its row is locked)
     */
    private function store(Connection $connection, ?callable $duringEncrypt = null, ?MockClock $clock = null): RuntimeEnvSettingStore
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $cipher = new class($duringEncrypt) implements RuntimeEnvValueCipherInterface {
            /** @param (callable(): void)|null $duringEncrypt */
            public function __construct(private $duringEncrypt)
            {
            }

            public function encrypt(string $plain): string
            {
                if ($this->duringEncrypt !== null) {
                    ($this->duringEncrypt)();
                }

                return 'enc:' . strrev($plain);
            }

            public function decrypt(string $stored): string
            {
                return str_starts_with($stored, 'enc:') ? strrev(substr($stored, 4)) : $stored;
            }
        };

        return new RuntimeEnvSettingStore($registry, 'default', 'runtime_env_variables', 'runtime_env_versions', $cipher, clock: $clock);
    }

    private function definition(string $name): RuntimeSettingDefinition
    {
        return new RuntimeSettingDefinition($name, RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded());
    }

    private function context(): RuntimeSettingWriteContext
    {
        return new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter(str_repeat('k', 32)));
    }
}
