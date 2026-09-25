<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Repository;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function dirname;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_VERSION_ID;

/**
 * Two FrankenPHP worker threads (one EntityManager each) sharing one database, no kernel reset between requests.
 */
final class DoctrineOrmRuntimeEnvVariableRepositoryWorkerTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = (string) tempnam(sys_get_temp_dir(), 'runtime_env_');
        $schemaManager      = $this->createEntityManager();
        (new SchemaTool($schemaManager))->createSchema([$schemaManager->getClassMetadata(RuntimeEnvVariable::class)]);
        $schemaManager->getConnection()->close();
    }

    protected function tearDown(): void
    {
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testWriteInOneWorkerIsVisibleInAnotherOnItsNextRequest(): void
    {
        $emWorker1     = $this->createEntityManager();
        $emWorker2     = $this->createEntityManager();
        $repoWorker1   = new DoctrineOrmRuntimeEnvVariableRepository($emWorker1);
        $repoWorker2   = new DoctrineOrmRuntimeEnvVariableRepository($emWorker2);
        $stackWorker2  = new RequestStack();
        $bagWorker2    = new RuntimeEnvBag($repoWorker2, true, $stackWorker2);
        $writerWorker1 = new RuntimeEnvWriter($repoWorker1, new RuntimeEnvBag($repoWorker1, true));

        $variable = $writerWorker1->create('API_TOKEN', 'old-secret');
        $writerWorker1->create('LEGACY', 'to-be-deleted');

        // Worker 2, request 1: loads and keeps the entities in its identity map.
        $stackWorker2->push(Request::create('/r1'));
        self::assertSame('old-secret', $bagWorker2->get('API_TOKEN'));
        self::assertSame('to-be-deleted', $bagWorker2->get('LEGACY'));
        $stackWorker2->pop();

        // Worker 1 (admin): update, disable and delete.
        $writerWorker1->update($variable, 'new-secret', 'rotated', true);
        $legacy = $repoWorker1->findOneByName('LEGACY');
        self::assertInstanceOf(RuntimeEnvVariable::class, $legacy);
        $writerWorker1->delete($legacy);

        // Worker 2, request 2: no reset, no clear; must see fresh values.
        $stackWorker2->push(Request::create('/r2'));
        self::assertSame('new-secret', $bagWorker2->get('API_TOKEN'));
        self::assertFalse($bagWorker2->has('LEGACY'));
        $stackWorker2->pop();

        $reloaded = $repoWorker2->find((int) $variable->getId());
        self::assertInstanceOf(RuntimeEnvVariable::class, $reloaded);
        self::assertSame('rotated', $reloaded->getDescription());
        self::assertSame('new-secret', $repoWorker2->findOneByName('API_TOKEN')?->getValue());
        self::assertNull($repoWorker2->find((int) $legacy->getId() ?: 999));
        self::assertSame(['API_TOKEN'], array_map(
            static fn (RuntimeEnvVariable $v): string => $v->getName(),
            $repoWorker2->findAllOrderedByName(),
        ));
    }

    public function testDisabledVariableDisappearsFromOtherWorkerWithoutReset(): void
    {
        $emWorker1   = $this->createEntityManager();
        $repoWorker1 = new DoctrineOrmRuntimeEnvVariableRepository($emWorker1);
        $repoWorker2 = new DoctrineOrmRuntimeEnvVariableRepository($this->createEntityManager());

        $variable = new RuntimeEnvVariable('FEATURE_X', '1');
        $repoWorker1->save($variable);

        self::assertCount(1, $repoWorker2->findAllEnabled());

        $variable->setEnabled(false);
        $repoWorker1->save($variable);

        self::assertSame([], $repoWorker2->findAllEnabled());
    }

    public function testDuplicateInsertClosesManagerAndNextRequestRecovers(): void
    {
        $managers = ['default' => $this->createEntityManager()];
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(static function () use (&$managers): EntityManagerInterface {
            return $managers['default'];
        });
        $registry->expects(self::once())->method('resetManager')->willReturnCallback(function () use (&$managers): EntityManagerInterface {
            return $managers['default'] = $this->createEntityManager();
        });

        $repoWorker1 = new DoctrineOrmRuntimeEnvVariableRepository(null, $registry, 'default');
        $repoWorker2 = new DoctrineOrmRuntimeEnvVariableRepository($this->createEntityManager());

        $repoWorker2->save(new RuntimeEnvVariable('RACE', 'from-worker-2'));

        // Request 1 in worker 1 loses the race on the unique name.
        try {
            $repoWorker1->save(new RuntimeEnvVariable('RACE', 'from-worker-1'));
            self::fail('Unique constraint violation expected.');
        } catch (UniqueConstraintViolationException) {
        }

        // Request 2 in worker 1, no reset: the manager must be usable again.
        self::assertSame('from-worker-2', $repoWorker1->findOneByName('RACE')?->getValue());
        $repoWorker1->save(new RuntimeEnvVariable('OTHER', 'ok'));
        self::assertSame('ok', $repoWorker2->findOneByName('OTHER')?->getValue());
    }

    private function createEntityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (method_exists($config, 'enableNativeLazyObjects') && PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->databasePath], $config),
            $config,
        );
    }
}
