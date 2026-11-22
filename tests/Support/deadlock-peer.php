<?php

declare(strict_types=1);

/*
 * Second database session of RowVersionDatabaseTest::testADeadlockInTheEditFlushAnswers409AndRepairsTheConnection
 * (MySQL / MariaDB). It runs in its own process so that its lock wait and the test's can overlap:
 *
 * 1. BEGIN, change the filler rows (`$argv[4]`, ids) and the second row (`$argv[2]`), then print "ready";
 * 2. on "go" (stdin), change the edited row (`$argv[3]`): this waits for the test's save, which holds that row and then
 *    asks for the second one, so InnoDB finds a deadlock and rolls back the save (the lighter transaction);
 * 3. ROLLBACK and print "ok" (or "error <code>" when this session was chosen instead).
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $url, $second, $edited, $fillers] = $argv;
$connection                          = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse($url));
$connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 10');
$connection->beginTransaction();
// By primary key only: a range scan would take next-key locks on rows of the test's save under REPEATABLE READ.
foreach (explode(',', $fillers) as $filler) {
    $connection->executeStatement("UPDATE runtime_env_variables SET description = 'peer' WHERE id = ?", [(int) $filler]);
}
$connection->executeStatement("UPDATE runtime_env_variables SET description = 'peer' WHERE id = ?", [(int) $second]);
fwrite(\STDOUT, "ready\n");

if (trim((string) fgets(\STDIN)) !== 'go') {
    exit(1);
}

try {
    $connection->executeStatement("UPDATE runtime_env_variables SET description = 'peer' WHERE id = ?", [(int) $edited]);
    $connection->rollBack();
    fwrite(\STDOUT, "ok\n");
} catch (Throwable $e) {
    fwrite(\STDOUT, 'error ' . $e->getCode() . "\n");
}
