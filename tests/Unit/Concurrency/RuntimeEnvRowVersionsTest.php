<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\RuntimeEnvBundle\Concurrency\RowVersionListener;
use Nowo\RuntimeEnvBundle\Concurrency\RuntimeEnvRowVersions;
use Nowo\RuntimeEnvBundle\Concurrency\StaleEditException;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Tests\Support\RuntimeSettingDatabaseTrait;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 1.9 (REQ-RUNTIME-003 rule 4): row versions of the manage page's edit form (SQLite; the real servers run
 * RowVersionDatabaseTest).
 */
final class RuntimeEnvRowVersionsTest extends TestCase
{
    use RuntimeSettingDatabaseTrait;

    private Connection $connection;

    private int $id;

    protected function setUp(): void
    {
        $this->connection = $this->database();
        $this->insertVariable($this->connection, 'API_TOKEN', 'v0');
        $this->id = (int) $this->connection->fetchOne("SELECT id FROM runtime_env_variables WHERE name = 'API_TOKEN'");
    }

    public function testCurrentReadsTheDatabase(): void
    {
        self::assertSame(1, $this->versions()->current($this->id));
        self::assertNull($this->versions()->current($this->id + 100));
    }

    public function testAdvanceChecksOnlyWithAnExpectedVersion(): void
    {
        RuntimeEnvRowVersions::advance($this->connection, 'runtime_env_variables', $this->id);
        self::assertSame(2, $this->versions()->current($this->id));
        RuntimeEnvRowVersions::advance($this->connection, 'runtime_env_variables', $this->id, 2);
        self::assertSame(3, $this->versions()->current($this->id));
        RuntimeEnvRowVersions::advance($this->connection, 'runtime_env_variables', $this->id + 100);

        try {
            RuntimeEnvRowVersions::advance($this->connection, 'runtime_env_variables', $this->id, 2);
            self::fail('A stale version must be refused.');
        } catch (StaleEditException $e) {
            self::assertSame($this->id, $e->id);
            self::assertSame(2, $e->expected);
            self::assertSame(StaleEditException::MESSAGE, $e->getMessage());
        }
        self::assertSame(3, $this->versions()->current($this->id));
    }

    public function testSaveEditRunsTheWriteAfterTheClaimInOneTransaction(): void
    {
        $level  = null;
        $result = $this->versions()->saveEdit($this->id, 1, function () use (&$level): string {
            $level = $this->connection->getTransactionNestingLevel();
            $this->connection->executeStatement("UPDATE runtime_env_variables SET value = 'v1' WHERE id = ?", [$this->id]);

            return 'written';
        });

        self::assertSame('written', $result);
        self::assertSame(1, $level);
        self::assertSame(2, $this->versions()->current($this->id));
        self::assertSame('v1', $this->connection->fetchOne('SELECT value FROM runtime_env_variables WHERE id = ?', [$this->id]));
    }

    public function testAStaleSaveNeverRunsTheWrite(): void
    {
        $ran = false;

        try {
            $this->versions()->saveEdit($this->id, 7, static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('A stale save must be refused.');
        } catch (StaleEditException) {
        }

        self::assertFalse($ran);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, $this->versions()->current($this->id));
    }

    public function testAFailedWriteRollsTheClaimBack(): void
    {
        try {
            $this->versions()->saveEdit($this->id, 1, static function (): never {
                throw new LogicException('write failed');
            });
            self::fail('The failure must surface.');
        } catch (LogicException $e) {
            self::assertSame('write failed', $e->getMessage());
        }

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, $this->versions()->current($this->id));
    }

    public function testClaimCommitsTheNextVersion(): void
    {
        self::assertSame(2, $this->versions()->claim($this->id, 1));
        self::assertSame(2, $this->versions()->current($this->id));

        $this->expectException(StaleEditException::class);
        $this->versions()->claim($this->id, 1);
    }

    public function testANonOrmManagerIsRefused(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        (new RuntimeEnvRowVersions($registry, 'runtime_env', 'runtime_env_variables'))->current(1);
    }

    public function testTheListenerAdvancesTheVersionOfAnUpdatedVariableOnly(): void
    {
        $metadata = new ClassMetadata(RuntimeEnvVariable::class);
        $metadata->setPrimaryTable(['name' => 'runtime_env_variables']);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($this->connection);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $listener = new RowVersionListener();

        $listener->postUpdate(new PostUpdateEventArgs((new RuntimeEnvVariable('API_TOKEN'))->setId($this->id), $manager));
        self::assertSame(2, $this->versions()->current($this->id));

        $listener->postUpdate(new PostUpdateEventArgs(new RuntimeEnvVariable('NEW_ONE'), $manager));
        $listener->postUpdate(new PostUpdateEventArgs(new stdClass(), $manager));
        self::assertSame(2, $this->versions()->current($this->id));
    }

    private function versions(): RuntimeEnvRowVersions
    {
        return new RuntimeEnvRowVersions($this->registryFor($this->connection), 'default', 'runtime_env_variables');
    }
}
