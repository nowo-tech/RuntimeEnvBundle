<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Event\RuntimeEnvVariableChangedEvent;
use Nowo\RuntimeEnvBundle\Persistence\OwnEntityManagerSubscriber;
use Nowo\RuntimeEnvBundle\Persistence\RuntimeEnvPendingEventsInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvReaderInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriterInterface;
use Nowo\RuntimeEnvBundle\Tests\Fixtures\Host\HostNote;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;

use function dirname;
use function memory_get_usage;

/**
 * Rule 6/7 through a real kernel (1.5): `database.entity_manager` is real, the host's managers are never flushed or
 * reset by the bundle when it owns another one, `database.transaction: host`, ID-only events after commit and a
 * kept-alive kernel driven through `http_kernel` (no services resetter).
 */
final class RuntimeEnvPersistenceKernelTest extends TestCase
{
    private ?PersistenceTestKernel $kernel = null;

    /** @var list<RuntimeEnvVariableChangedEvent> */
    private array $events = [];

    /** @var list<bool> */
    private array $inTransaction = [];

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testStandardHostWritesOnTheDefaultManagerAndDispatchesAfterCommit(): void
    {
        $container = $this->boot($this->singleManager(), []);
        self::assertFalse($container->has(OwnEntityManagerSubscriber::class), 'the host default manager is not the bundle\'s');

        $writer = $this->writer($container);
        $writer->createVariable('API_TOKEN', 'REDACTED-TEST');
        $writer->renameByName('API_TOKEN', 'SERVICE_TOKEN');
        $writer->updateByName('SERVICE_TOKEN', 'REDACTED-TEST-2', 'desc', true);

        $reader = $container->get(RuntimeEnvReaderInterface::class);
        self::assertInstanceOf(RuntimeEnvReaderInterface::class, $reader);
        self::assertSame('REDACTED-TEST-2', $reader->get('SERVICE_TOKEN'));

        $writer->deleteByName('SERVICE_TOKEN');
        self::assertSame(
            [['API_TOKEN', 'created', null], ['SERVICE_TOKEN', 'renamed', 'API_TOKEN'], ['SERVICE_TOKEN', 'updated', null], ['SERVICE_TOKEN', 'deleted', null]],
            array_map(static fn (RuntimeEnvVariableChangedEvent $e): array => [$e->name, $e->action, $e->previousName], $this->events),
        );
        self::assertNotContains(true, $this->inTransaction, 'dispatched after the commit');
    }

    public function testDedicatedManagerWithAutoMappingOnDefaultKeepsTheHostUnitOfWorkUntouched(): void
    {
        $container = $this->boot($this->twoManagers(autoMapping: true), ['entity_manager' => 'runtime_env']);
        $registry  = $this->registry($container);
        $default   = $this->manager($container, 'default');
        $bundle    = $this->manager($container, 'runtime_env');

        self::assertTrue($default->getMetadataFactory()->isTransient(RuntimeEnvVariable::class), 'not mapped on the auto-mapped default manager');
        self::assertSame($bundle, $registry->getManagerForClass(RuntimeEnvVariable::class));
        self::assertSame('default', $registry->getDefaultManagerName());

        $note = new HostNote('pending host change');
        $default->persist($note);
        $this->writer($container)->createVariable('FEATURE_FLAG', '1');

        self::assertSame(1, (int) $bundle->getConnection()->fetchOne('SELECT COUNT(*) FROM runtime_env_variables'));
        self::assertSame(0, (int) $default->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'), 'the host change is not flushed');
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note));
    }

    public function testManagersWithoutDefaultEntityManagerKeepTheHostDefault(): void
    {
        $container = $this->boot($this->twoManagers(autoMapping: false, defaultName: false), ['entity_manager' => 'runtime_env']);
        $registry  = $this->registry($container);

        self::assertSame('default', $registry->getDefaultManagerName());
        self::assertSame($this->manager($container, 'runtime_env'), $registry->getManagerForClass(RuntimeEnvVariable::class));
    }

    public function testUnknownEntityManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_runtime_env.database.entity_manager is "missing"');

        $this->boot($this->singleManager(), ['entity_manager' => 'missing']);
    }

    public function testHostTransactionModeNeverFlushesAndDispatchesAfterTheHostCommit(): void
    {
        $container = $this->boot($this->singleManager(), ['transaction' => 'host']);
        $default   = $this->manager($container, 'default');
        $pending   = $container->get(RuntimeEnvPendingEventsInterface::class);
        self::assertInstanceOf(RuntimeEnvPendingEventsInterface::class, $pending);

        $this->writer($container)->createVariable('DRAFT', 'x');
        self::assertSame(0, (int) $default->getConnection()->fetchOne('SELECT COUNT(*) FROM runtime_env_variables'), 'the bundle does not flush');
        self::assertSame(1, $pending->countPending());

        $default->wrapInTransaction(static function (EntityManagerInterface $em): void {
            $em->flush();
        });
        self::assertSame([], $this->events, 'not inside the host transaction');
        self::assertSame(1, $pending->dispatchPending());
        self::assertSame('DRAFT', $this->events[0]->name);
        self::assertSame(1, (int) $default->getConnection()->fetchOne('SELECT COUNT(*) FROM runtime_env_variables'));
    }

    public function testKeptAliveKernelRecoversTheOwnManagerAndKeepsMemoryFlat(): void
    {
        $container = $this->boot($this->twoManagers(autoMapping: true), ['entity_manager' => 'runtime_env']);
        self::assertTrue($container->has(OwnEntityManagerSubscriber::class));
        $kernel  = $this->httpKernel($container);
        $default = $this->manager($container, 'default');
        $bundle  = $this->manager($container, 'runtime_env');
        $note    = new HostNote('host change survives');
        $default->persist($note);

        $request  = Request::create('/t/fail');
        $response = $kernel->handle($request);
        self::assertSame(500, $response->getStatusCode());
        $kernel->terminate($request, $response);
        self::assertFalse($bundle->isOpen(), 'the failed write closed the bundle manager');

        $baseline = 0;
        for ($i = 0; $i < 300; ++$i) {
            $request  = Request::create('/t/set/VAR_' . $i);
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $kernel->terminate($request, $response);
            self::assertSame(0, $bundle->getUnitOfWork()->size(), 'no managed variable survives the request');
            if ($i === 50) {
                $baseline = memory_get_usage();
            }
        }

        self::assertSame(300, (int) $bundle->getConnection()->fetchOne('SELECT COUNT(*) FROM runtime_env_variables'));
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note), 'the host manager is never reset nor cleared');
        self::assertCount(300, $this->events);
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat');
    }

    public function testDefaultManagerIsNeverResetByTheBundle(): void
    {
        $container = $this->boot($this->singleManager(), []);
        $kernel    = $this->httpKernel($container);
        $default   = $this->manager($container, 'default');

        $request  = Request::create('/t/fail');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertFalse($default->isOpen());

        $response = $kernel->handle(Request::create('/t/set/NEXT'));
        self::assertSame(500, $response->getStatusCode(), 'the host owns its manager: the bundle does not reset it');
        self::assertFalse($default->isOpen());
    }

    /**
     * @param array<string, mixed> $doctrine
     * @param array<string, mixed> $database
     */
    private function boot(array $doctrine, array $database): ContainerInterface
    {
        $this->kernel = new PersistenceTestKernel($doctrine, $database);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        foreach ($this->registry($container)->getManagers() as $manager) {
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $tool = new SchemaTool($manager);
            $tool->dropDatabase();
            $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }

        $bundle = $this->registry($container)->getManagerForClass(RuntimeEnvVariable::class);
        self::assertInstanceOf(EntityManagerInterface::class, $bundle);
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(RuntimeEnvVariableChangedEvent::class, function (RuntimeEnvVariableChangedEvent $event) use ($bundle): void {
            $this->events[]        = $event;
            $this->inTransaction[] = $bundle->getConnection()->isTransactionActive();
        });

        return $container;
    }

    private function writer(ContainerInterface $container): RuntimeEnvWriterInterface
    {
        $writer = $container->get(RuntimeEnvWriterInterface::class);
        self::assertInstanceOf(RuntimeEnvWriterInterface::class, $writer);

        return $writer;
    }

    /**
     * The `http_kernel` service itself: FrankenPHP worker mode without kernel reset.
     */
    private function httpKernel(ContainerInterface $container): HttpKernel
    {
        $kernel = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $kernel);

        return $kernel;
    }

    private function registry(ContainerInterface $container): ManagerRegistry
    {
        $registry = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function manager(ContainerInterface $container, string $name): EntityManagerInterface
    {
        $manager = $this->registry($container)->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function hostMapping(): array
    {
        return ['Host' => [
            'type'      => 'attribute',
            'dir'       => dirname(__DIR__, 2) . '/Fixtures/Host',
            'prefix'    => 'Nowo\\RuntimeEnvBundle\\Tests\\Fixtures\\Host',
            'is_bundle' => false,
        ]];
    }

    /**
     * Flex shorthand: one (default) entity manager.
     *
     * @return array<string, mixed>
     */
    private function singleManager(): array
    {
        return [
            'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/default.sqlite'],
            'orm'  => ['mappings' => $this->hostMapping()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function twoManagers(bool $autoMapping, bool $defaultName = true): array
    {
        $orm = ['entity_managers' => [
            'default'     => ['connection' => 'default', 'auto_mapping' => $autoMapping, 'mappings' => $this->hostMapping()],
            'runtime_env' => ['connection' => 'runtime_env'],
        ]];
        if ($defaultName) {
            $orm = ['default_entity_manager' => 'default'] + $orm;
        }

        return [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default'     => ['url' => 'sqlite:///%kernel.cache_dir%/default.sqlite'],
                    'runtime_env' => ['url' => 'sqlite:///%kernel.cache_dir%/runtime_env.sqlite'],
                ],
            ],
            'orm' => $orm,
        ];
    }
}
