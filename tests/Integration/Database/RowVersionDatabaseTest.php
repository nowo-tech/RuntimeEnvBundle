<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Database;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RuntimeEnvBundle\Concurrency\RowVersionListener;
use Nowo\RuntimeEnvBundle\Concurrency\RuntimeEnvRowVersions;
use Nowo\RuntimeEnvBundle\Concurrency\StaleEditException;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvValueCipherInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Nowo\RuntimeEnvBundle\Transaction\StorageFailureListener;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function array_filter;
use function array_values;
use function dirname;
use function fclose;
use function fgets;
use function fwrite;
use function getenv;
use function implode;
use function is_resource;
use function is_string;
use function method_exists;
use function proc_close;
use function proc_open;
use function sprintf;
use function str_contains;
use function trim;
use function usleep;

use const PHP_BINARY;
use const PHP_VERSION_ID;

/**
 * 1.9 (REQ-RUNTIME-003 rule 4): the row version of the manage page's edit form on MySQL 8.4, MariaDB 11.4 and
 * PostgreSQL 16, with two connections (two admins) on the same database (CI job "Real databases"; locally set
 * RE_MYSQL_URL / RE_MARIADB_URL / RE_POSTGRES_URL, or `make test-with-db`).
 */
#[Group('database')]
final class RowVersionDatabaseTest extends TestCase
{
    private const TABLE = 'runtime_env_variables';

    /** @var list<Connection> */
    private array $connections = [];

    /** @var array<string, mixed> */
    private array $params = [];

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
     * The migration only adds `row_version INT DEFAULT 1 NOT NULL`; existing rows get version 1.
     */
    #[DataProvider('databases')]
    public function testTheMigrationIsAdditive(?string $url): void
    {
        [$a]     = $this->twoConnections($url);
        $manager = $this->manager($a);
        $this->seed($a, 'OLD_ROW', 'x');
        $a->executeStatement(sprintf('ALTER TABLE %s DROP COLUMN row_version', self::TABLE));

        $sql     = (new SchemaTool($manager))->getUpdateSchemaSql([$manager->getClassMetadata(RuntimeEnvVariable::class)]);
        $version = array_values(array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'row_version')));

        self::assertSame([sprintf('ALTER TABLE %s ADD row_version INT DEFAULT 1 NOT NULL', self::TABLE)], $version);
        foreach ($sql as $statement) {
            $a->executeStatement($statement);
        }
        self::assertSame(1, (int) $a->fetchOne(sprintf("SELECT row_version FROM %s WHERE name = 'OLD_ROW'", self::TABLE)));
    }

    /**
     * Two admins open the same page (same version). The first save wins; the second is refused as stale, writes
     * nothing, and leaves its connection usable at nesting level 0.
     */
    #[DataProvider('databases')]
    public function testTwoAdminsOnTheSamePageOnlyTheFirstSaveWins(?string $url): void
    {
        [$a, $b] = $this->twoConnections($url);
        $id      = $this->seed($a, 'API_TOKEN', 'v0');
        $version = $this->rowVersions($a)->current($id);
        self::assertSame(1, $version);

        $this->save($a, $id, $version, 'from-a');

        try {
            $this->save($b, $id, $version, 'from-b');
            self::fail('The second save of the same page must be stale.');
        } catch (StaleEditException $e) {
            self::assertSame($id, $e->id);
            self::assertSame($version, $e->expected);
        }

        self::assertSame('from-a', $this->value($b, $id));
        self::assertSame(0, $b->getTransactionNestingLevel());
        self::assertGreaterThan($version, $this->rowVersions($b)->current($id));
        $this->save($b, $id, (int) $this->rowVersions($b)->current($id), 'from-b-reloaded');
        self::assertSame('from-b-reloaded', $this->value($a, $id), 'a reloaded page saves');
    }

    /**
     * The second save meets the row lock of the first one, still open: its lock wait times out (3 s) and the answer is
     * 409 `CONFLICT_001` with Retry-After. Its retry, after the first save committed, is stale.
     */
    #[DataProvider('databases')]
    public function testASaveWaitingOnAnOpenSaveAnswersConflict001AndItsRetryIsStale(?string $url): void
    {
        [$a, $b] = $this->twoConnections($url);
        $id      = $this->seed($a, 'API_TOKEN', 'v0');
        $version = (int) $this->rowVersions($a)->current($id);
        $failure = null;

        $this->save($a, $id, $version, 'from-a', function () use ($b, $id, $version, &$failure): void {
            try {
                $this->save($b, $id, $version, 'from-b');
            } catch (TransientStorageFailure $e) {
                $failure = $e;
            }
        });

        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        $answer = $this->answer($failure);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame('1', $answer->getHeaders()['Retry-After']);
        self::assertSame(ConflictCode::RETRY_SAFE, $answer->getHeaders()[StorageFailureListener::CODE_HEADER]);
        self::assertSame(0, $b->getTransactionNestingLevel());

        $this->expectException(StaleEditException::class);
        try {
            $this->save($b, $id, $version, 'from-b');
        } finally {
            self::assertSame('from-a', $this->value($b, $id));
        }
    }

    /**
     * Writes without a page behind them advance the version too, so a page opened before them is stale: the writer
     * (ORM flush, postUpdate) and the store's description edit. The claim of the runtime-setting path checks the same
     * version.
     */
    #[DataProvider('databases')]
    public function testOtherWritesMakeAnOpenPageStale(?string $url): void
    {
        [$a, $b] = $this->twoConnections($url);
        $id      = $this->seed($a, 'API_TOKEN', 'v0');
        $page    = (int) $this->rowVersions($b)->current($id);

        $manager  = $this->manager($a);
        $variable = $manager->find(RuntimeEnvVariable::class, $id);
        self::assertInstanceOf(RuntimeEnvVariable::class, $variable);
        $this->writer($manager)->update($variable, 'host-write', null, true);
        self::assertSame($page + 1, $this->rowVersions($b)->current($id));

        $this->store($a)->updateDescription('API_TOKEN', 'described');
        self::assertSame($page + 2, $this->rowVersions($b)->current($id));

        try {
            $this->rowVersions($b)->claim($id, $page);
            self::fail('A page opened before the writes must be stale.');
        } catch (StaleEditException) {
        }
        self::assertSame($page + 3, $this->rowVersions($b)->claim($id, $page + 2));
        self::assertSame($page + 3, $this->rowVersions($a)->current($id));
        self::assertSame('host-write', $this->value($b, $id));
    }

    /**
     * Audit ZH-M1 (MySQL / MariaDB): a deadlock inside the edit's flush (a savepoint one level below the save's
     * transaction) destroys the savepoint, so rolling it back fails with 1305. The runner repairs the connection: the
     * answer is 409 `CONFLICT_001` (not a 500), the nesting level is back to 0 and the next save works.
     */
    #[DataProvider('databases')]
    public function testADeadlockInTheEditFlushAnswers409AndRepairsTheConnection(?string $url): void
    {
        [$a] = $this->twoConnections($url);
        if (!$a->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // PostgreSQL rolls back to a savepoint inside an aborted transaction: the case does not exist there.
            self::assertNotNull($url);

            return;
        }

        $id      = $this->seed($a, 'API_TOKEN', 'v0');
        $second  = $this->seed($a, 'OTHER', 'o0');
        $fillers = [];
        for ($i = 0; $i < 10; ++$i) {
            $fillers[] = $this->seed($a, 'FILLER_' . $i, 'f');
        }
        $version = (int) $this->rowVersions($a)->current($id);

        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/deadlock-peer.php', (string) $url, (string) $second, (string) $id, implode(',', $fillers)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertTrue(is_resource($process));
        self::assertSame('ready', trim((string) fgets($pipes[1])));

        $manager  = $this->manager($a);
        $variable = $manager->find(RuntimeEnvVariable::class, $id);
        $other    = $manager->find(RuntimeEnvVariable::class, $second);
        self::assertInstanceOf(RuntimeEnvVariable::class, $variable);
        self::assertInstanceOf(RuntimeEnvVariable::class, $other);
        $failure = null;

        try {
            $this->rowVersions($a)->saveEdit($id, $version, static function () use ($manager, $variable, $other, $pipes): void {
                // The save holds the edited row (claim); the peer now waits for it ...
                fwrite($pipes[0], "go\n");
                usleep(500_000);
                // ... and the flush asks for the row the peer holds: a deadlock, the save is the lighter transaction.
                $variable->setValue('from-a');
                $other->setDescription('from-a');
                $manager->flush();
            });
        } catch (Throwable $e) {
            $failure = $e;
        }

        // Closing its input ends a peer that never got "go" (the save failed before its flush).
        fclose($pipes[0]);
        $peer = trim((string) fgets($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('ok', $peer, 'the peer won the deadlock');
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        // Audit ZJ-I1: a real deadlock (1213), never a lock wait timeout (1205) that would skip the ZH-M1 path.
        $deadlock = false;
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            self::assertNotInstanceOf(LockWaitTimeoutException::class, $cause);
            $deadlock = $deadlock || $cause instanceof DeadlockException;
        }
        self::assertTrue($deadlock, 'the failure chain holds the deadlock (1213)');
        $answer = $this->answer($failure);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(ConflictCode::RETRY_SAFE, $answer->getHeaders()[StorageFailureListener::CODE_HEADER]);
        self::assertSame(0, $a->getTransactionNestingLevel());
        self::assertSame('v0', $this->value($a, $id), 'nothing of the save is stored');
        self::assertSame($version, $this->rowVersions($a)->current($id));

        // The next save on the same connection works (a fresh manager: the failed flush closed the old one).
        $this->save($a, $id, $version, 'after-deadlock');
        self::assertSame('after-deadlock', $this->value($a, $id));
        self::assertSame(0, $a->getTransactionNestingLevel());
    }

    /**
     * The manage page's plain save: the compare-and-set, then the writer's flush, in one transaction.
     *
     * @param (callable(): void)|null $insideTransaction runs after the claim, before the flush
     */
    private function save(Connection $connection, int $id, int $version, string $value, ?callable $insideTransaction = null): void
    {
        $manager  = $this->manager($connection);
        $variable = $manager->find(RuntimeEnvVariable::class, $id);
        self::assertInstanceOf(RuntimeEnvVariable::class, $variable);
        $writer = $this->writer($manager);

        $this->rowVersions($connection)->saveEdit($id, $version, static function () use ($writer, $variable, $value, $insideTransaction): void {
            if ($insideTransaction !== null) {
                $insideTransaction();
            }
            $writer->update($variable, $value, $variable->getDescription(), true);
        });
    }

    private function seed(Connection $connection, string $name, string $value): int
    {
        $variable = $this->writer($this->manager($connection))->create($name, $value);

        return (int) $variable->getId();
    }

    private function value(Connection $connection, int $id): string
    {
        return (string) $connection->fetchOne(sprintf('SELECT value FROM %s WHERE id = ?', self::TABLE), [$id]);
    }

    private function answer(Throwable $failure): HttpException
    {
        $request = Request::create('/admin/runtime-env/1/edit', 'POST');
        $request->attributes->set('_controller', 'Nowo\\RuntimeEnvBundle\\Controller\\RuntimeEnvManageController::edit');
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        (new StorageFailureListener())->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);

        return $answer;
    }

    private function writer(EntityManagerInterface $manager): RuntimeEnvWriter
    {
        $repository = new DoctrineOrmRuntimeEnvVariableRepository($manager);

        return new RuntimeEnvWriter($repository, new RuntimeEnvBag($repository, true));
    }

    private function rowVersions(Connection $connection): RuntimeEnvRowVersions
    {
        return new RuntimeEnvRowVersions($this->registry($connection), 'default', self::TABLE);
    }

    private function store(Connection $connection): RuntimeEnvSettingStore
    {
        return new RuntimeEnvSettingStore($this->registry($connection), 'default', self::TABLE, 'runtime_env_versions', $this->createStub(RuntimeEnvValueCipherInterface::class));
    }

    private function registry(Connection $connection): ManagerRegistry
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return $registry;
    }

    /**
     * An entity manager of the bundle on `$connection`, with the row version listener.
     */
    private function manager(Connection $connection): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (method_exists($config, 'enableNativeLazyObjects') && PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        // A fresh event manager each time: on DBAL 3 the connection's own one is shared by every manager built on it.
        $manager = new EntityManager($connection, $config, new EventManager());
        $manager->getEventManager()->addEventListener(Events::postUpdate, new RowVersionListener());

        return $manager;
    }

    /**
     * Two sessions on a fresh schema generated from the mapping (lock wait timeout 3 s).
     *
     * @return array{0: Connection, 1: Connection}
     */
    private function twoConnections(?string $url): array
    {
        if ($url === null) {
            self::markTestSkipped('Set RE_MYSQL_URL / RE_MARIADB_URL / RE_POSTGRES_URL to run against real servers.');
        }

        $this->params        = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $a                   = DriverManager::getConnection($this->params);
        $b                   = DriverManager::getConnection($this->params);
        $this->connections[] = $a;
        $this->connections[] = $b;

        $mysql = $a->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $a->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        if (!$mysql) {
            // DBAL 3 maps the identity column of PostgreSQL to a sequence of its own.
            $a->executeStatement('DROP SEQUENCE IF EXISTS ' . self::TABLE . '_id_seq');
        }
        $manager = $this->manager($a);
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(RuntimeEnvVariable::class)]);
        foreach ([$a, $b] as $connection) {
            $connection->executeStatement($mysql ? 'SET SESSION innodb_lock_wait_timeout = 3' : "SET lock_timeout = '3s'");
        }

        return [$a, $b];
    }
}
