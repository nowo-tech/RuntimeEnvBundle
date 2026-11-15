<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvValueCipherInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

use function str_starts_with;
use function strrev;
use function substr;

/**
 * 1.6 tests: an SQLite connection with the variables and versions tables (as the schema tool creates them with
 * runtime_setting.enabled), a registry whose manager returns it, and a reversible fake cipher.
 */
trait RuntimeSettingDatabaseTrait
{
    private function database(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE runtime_env_variables (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(128) NOT NULL, value CLOB NOT NULL, description VARCHAR(255) DEFAULT NULL, enabled BOOLEAN NOT NULL, updated_at DATETIME NOT NULL, previous_value CLOB DEFAULT NULL, previous_expires_at DATETIME DEFAULT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX uniq_runtime_env_name ON runtime_env_variables (name)');
        $connection->executeStatement('CREATE TABLE runtime_env_versions (namespace VARCHAR(64) NOT NULL PRIMARY KEY, token VARCHAR(128) NOT NULL)');

        return $connection;
    }

    private function registryFor(Connection $connection): ManagerRegistry
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return $registry;
    }

    private function cipher(): RuntimeEnvValueCipherInterface
    {
        return new class implements RuntimeEnvValueCipherInterface {
            public function encrypt(string $plain): string
            {
                return 'enc:' . strrev($plain);
            }

            public function decrypt(string $stored): string
            {
                return str_starts_with($stored, 'enc:') ? strrev(substr($stored, 4)) : $stored;
            }
        };
    }

    private function store(Connection $connection, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null, ?ClockInterface $clock = null): RuntimeEnvSettingStore
    {
        return new RuntimeEnvSettingStore($this->registryFor($connection), 'default', 'runtime_env_variables', 'runtime_env_versions', $this->cipher(), $dispatcher, $logger, $clock);
    }

    private function insertVariable(Connection $connection, string $name, string $stored, bool $enabled = true, ?string $previous = null, ?DateTimeImmutable $expires = null): void
    {
        $connection->insert('runtime_env_variables', [
            'name'                => $name,
            'value'               => $stored,
            'description'         => 'seeded',
            'enabled'             => $enabled,
            'updated_at'          => new DateTimeImmutable('2026-01-01'),
            'previous_value'      => $previous,
            'previous_expires_at' => $expires,
        ], ['enabled' => 'boolean', 'updated_at' => 'datetime_immutable', 'previous_expires_at' => 'datetime_immutable']);
    }
}
