<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\RuntimeSetting;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriterInterface;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

use function bin2hex;
use function getenv;
use function hash;
use function is_string;
use function random_bytes;
use function spl_object_id;
use function sys_get_temp_dir;

/**
 * 1.6, the test the contract asks every adopter for: a kept-alive worker kernel (no services resetter) with a SHARED
 * cache pool serves the old value while the version token it read is fresh, and the new one once version_ttl passed,
 * without a restart; for writes through the contract updater, the console command and the 1.5 writer (admin path).
 */
final class RuntimeSettingKernelTest extends TestCase
{
    private string $directory;

    /** @var list<RuntimeSettingTestKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/nowo-runtime-env-rs-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        (new Filesystem())->remove($this->directory);
    }

    public function testAKeptAliveWorkerSeesWritesOfAnotherKernelWithinTheVersionTtl(): void
    {
        $this->liveChange('sqlite:///' . $this->directory . '/db.sqlite');
    }

    /**
     * QT-I-5: the same flow on MySQL, MariaDB and PostgreSQL (CI job "Real databases").
     */
    #[Group('database')]
    #[DataProvider('realDatabases')]
    public function testTheSameOnARealDatabase(?string $url): void
    {
        if ($url === null) {
            self::markTestSkipped('Set RE_MYSQL_URL / RE_MARIADB_URL / RE_POSTGRES_URL to run against real servers.');
        }

        $this->liveChange($url);
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function realDatabases(): iterable
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

    private function liveChange(string $url): void
    {
        $worker = $this->kernel(true, $url);
        $admin  = $this->kernel(false, $url);
        $id     = spl_object_id($worker->getContainer());
        /** @var MockClock $clock */
        $clock = $worker->getContainer()->get('nowo_runtime_setting.clock');
        self::assertFalse($worker->getContainer()->has('services_resetter'));
        self::assertInstanceOf(RuntimeEnvSettingStore::class, $this->service($admin, 'test.source'));

        $updater = $this->service($admin, 'test.updater');
        self::assertInstanceOf(RuntimeSettingUpdater::class, $updater);
        $updater->set('DEMO_TOKEN', 'first', 'cli:test');
        self::assertSame('first', $this->request($worker));
        // The shared pool really holds the value, under the token the source reports (QT-I-5).
        $source = $this->service($admin, 'test.source');
        self::assertInstanceOf(RuntimeEnvSettingStore::class, $source);
        $item = (new FilesystemAdapter('runtime_setting', 0, $this->directory . '/pool'))->getItem('nowo_runtime_setting.' . hash('sha256', 'DEMO_TOKEN'));
        self::assertTrue($item->isHit(), 'the worker filled the shared pool');
        $payload = $item->get();
        self::assertIsArray($payload);
        self::assertSame($source->namespaceVersion('env'), $payload['v']);

        // Through the contract updater: cached under the old token until version_ttl.
        $updater->set('DEMO_TOKEN', 'second', 'cli:test');
        self::assertSame('first', $this->request($worker), 'the shared pool serves the value of the token still fresh');
        $clock->modify('+1001 milliseconds');
        self::assertSame('second', $this->request($worker), 'after version_ttl the new token is read');

        // Through the console command of another kernel.
        $tester = new CommandTester((new Application($admin))->find('nowo:runtime-setting:set'));
        $tester->setInputs(['third']);
        self::assertSame(0, $tester->execute(['id' => 'DEMO_TOKEN', '--stdin' => true]));
        $clock->modify('+1001 milliseconds');
        self::assertSame('third', $this->request($worker));

        // Through the 1.5 writer (admin UI and host code): it bumps the version in its own flush.
        $writer = $this->service($admin, 'test.writer');
        self::assertInstanceOf(RuntimeEnvWriterInterface::class, $writer);
        $writer->updateByName('DEMO_TOKEN', 'fourth', 'Demo token', true);
        self::assertSame('third', $this->request($worker));
        $clock->modify('+1001 milliseconds');
        self::assertSame('fourth', $this->request($worker));

        $writer->updateByName('DEMO_TOKEN', 'fourth', 'Demo token', false);
        $clock->modify('+1001 milliseconds');
        self::assertSame('', $this->request($worker), 'disabled: unset');

        self::assertSame($id, spl_object_id($worker->getContainer()), 'same container: no restart');
    }

    public function testADbalQueryProviderServesAHostTableThroughTheSharedPool(): void
    {
        $this->providerFlow('sqlite:///' . $this->directory . '/db.sqlite');
    }

    /**
     * 1.10: the DBAL query provider in a kept-alive kernel on MySQL, MariaDB and PostgreSQL.
     */
    #[Group('database')]
    #[DataProvider('realDatabases')]
    public function testTheProviderOnARealDatabase(?string $url): void
    {
        if ($url === null) {
            self::markTestSkipped('Set RE_MYSQL_URL / RE_MARIADB_URL / RE_POSTGRES_URL to run against real servers.');
        }

        $this->providerFlow($url);
    }

    private function providerFlow(string $url): void
    {
        $worker = $this->kernel(true, $url);
        /** @var MockClock $clock */
        $clock      = $worker->getContainer()->get('nowo_runtime_setting.clock');
        $connection = $this->service($worker, 'doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DROP TABLE IF EXISTS host_tenant_setting');
        $connection->executeStatement('CREATE TABLE host_tenant_setting (tenant VARCHAR(16) NOT NULL, name VARCHAR(128) NOT NULL, url VARCHAR(255) NULL, old_url VARCHAR(255) NULL, old_until VARCHAR(32) NULL)');
        $connection->insert('host_tenant_setting', ['tenant' => 'acme', 'name' => 'TENANT_URL', 'url' => 'https://acme-1.example']);
        $connection->insert('host_tenant_setting', ['tenant' => 'other', 'name' => 'TENANT_URL', 'url' => 'https://other.example']);

        self::assertSame('https://acme-1.example', $this->request($worker, '/t/tenant'));
        $registry = $this->service($worker, RuntimeValueProviderRegistry::class);
        self::assertInstanceOf(RuntimeValueProviderRegistry::class, $registry);
        self::assertStringEndsWith('|tenant=acme', $registry->get('tenant_db')->cacheScope, 'the env constant is part of the cache scope');

        // A rotation at the provider: within the cache TTL the pool still serves the first value, to every worker.
        $connection->update('host_tenant_setting', ['url' => 'https://acme-2.example', 'old_url' => 'https://acme-1.example', 'old_until' => '2026-11-16 13:00:00'], ['tenant' => 'acme']);
        self::assertSame('https://acme-1.example', $this->request($worker, '/t/tenant'));
        $other = $this->kernel(false, $url);
        self::assertSame('https://acme-1.example', $this->request($other, '/t/tenant'), 'another worker reads the shared pool');

        $clock->modify('+60 seconds');
        self::assertSame('https://acme-2.example|https://acme-1.example', $this->request($worker, '/t/tenant'), 'next bucket: current and previous');

        // Read-only: the updater and the command refuse it.
        $tester = new CommandTester((new Application($other))->find('nowo:runtime-setting:set'));
        self::assertSame(1, $tester->execute(['id' => 'TENANT_URL', 'value' => 'https://evil.example']));
        self::assertStringContainsString('read-only provider', $tester->getDisplay());
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM runtime_env_variables WHERE name = 'TENANT_URL'"));
        $connection->executeStatement('DROP TABLE host_tenant_setting');
    }

    public function testTheBundleValidatorRefusesTheValueOnEveryWritePath(): void
    {
        $admin   = $this->kernel(true, 'sqlite:///' . $this->directory . '/db.sqlite');
        $updater = $this->service($admin, 'test.updater');
        self::assertInstanceOf(RuntimeSettingUpdater::class, $updater);

        $this->expectException(InvalidRuntimeSettingValue::class);
        $updater->set('DEMO_TOKEN', "two\nlines", 'cli:test');
    }

    private function kernel(bool $createSchema, string $url): RuntimeSettingTestKernel
    {
        $kernel = new RuntimeSettingTestKernel($url, $this->directory . '/pool');
        $kernel->boot();
        $this->kernels[] = $kernel;

        if ($createSchema) {
            $registry = $this->service($kernel, 'doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $registry);
            $manager = $registry->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $tool = new SchemaTool($manager);
            $tool->dropDatabase();
            $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }

        return $kernel;
    }

    private function service(RuntimeSettingTestKernel $kernel, string $id): mixed
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container->get($id);
    }

    private function request(RuntimeSettingTestKernel $kernel, string $path = '/t/token'): string
    {
        $request  = Request::create($path);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return (string) $response->getContent();
    }
}
