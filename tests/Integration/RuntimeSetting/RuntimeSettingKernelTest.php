<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\RuntimeSetting;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriterInterface;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

use function bin2hex;
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
        $worker = $this->kernel(true);
        $admin  = $this->kernel(false);
        $id     = spl_object_id($worker->getContainer());
        /** @var MockClock $clock */
        $clock = $worker->getContainer()->get('nowo_runtime_setting.clock');
        self::assertFalse($worker->getContainer()->has('services_resetter'));
        self::assertInstanceOf(RuntimeEnvSettingStore::class, $this->service($admin, 'test.source'));

        $updater = $this->service($admin, 'test.updater');
        self::assertInstanceOf(RuntimeSettingUpdater::class, $updater);
        $updater->set('DEMO_TOKEN', 'first', 'cli:test');
        self::assertSame('first', $this->request($worker));

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

    public function testTheBundleValidatorRefusesTheValueOnEveryWritePath(): void
    {
        $admin   = $this->kernel(true);
        $updater = $this->service($admin, 'test.updater');
        self::assertInstanceOf(RuntimeSettingUpdater::class, $updater);

        $this->expectException(InvalidRuntimeSettingValue::class);
        $updater->set('DEMO_TOKEN', "two\nlines", 'cli:test');
    }

    private function kernel(bool $createSchema): RuntimeSettingTestKernel
    {
        $kernel = new RuntimeSettingTestKernel($this->directory . '/db.sqlite', $this->directory . '/pool');
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

    private function request(RuntimeSettingTestKernel $kernel): string
    {
        $request  = Request::create('/t/token');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return (string) $response->getContent();
    }
}
