<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

use function sprintf;

/**
 * 1.6 (audit QT-L-3): makes sure the version row of a namespace exists with a statement that cannot conflict, so the
 * caller can always `UPDATE` it. Two first writes ever then serialise on that row instead of racing an `INSERT`
 * (deadlock on MySQL / MariaDB, unique violation on PostgreSQL).
 *
 * MySQL / MariaDB: `INSERT … ON DUPLICATE KEY UPDATE namespace = namespace`; PostgreSQL and SQLite (3.24+):
 * `INSERT … ON CONFLICT (namespace) DO NOTHING`. The seeded token equals the token of a namespace never written, so the
 * statement changes nothing a reader can observe.
 */
final class RuntimeEnvVersionRow
{
    private const MYSQL = 'INSERT INTO %s (namespace, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE namespace = namespace';

    private const STANDARD = 'INSERT INTO %s (namespace, token) VALUES (?, ?) ON CONFLICT (namespace) DO NOTHING';

    public static function ensure(Connection $connection, string $table, string $namespace): void
    {
        $sql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? self::MYSQL : self::STANDARD;

        $connection->executeStatement(sprintf($sql, $table), [$namespace, RuntimeEnvSettingStore::INITIAL_VERSION]);
    }
}
