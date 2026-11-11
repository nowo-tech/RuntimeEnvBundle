<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Persistence;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function md5;
use function serialize;
use function sys_get_temp_dir;

/**
 * Host kernel of the rule 6 tests (1.5): real DoctrineBundle on SQLite files, configurable managers and
 * `nowo_runtime_env.database`, two host routes (`/t/set/{name}`, `/t/fail`).
 */
final class PersistenceTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $doctrine host `doctrine` config
     * @param array<string, mixed> $database `nowo_runtime_env.database`
     */
    public function __construct(
        private readonly array $doctrine,
        private readonly array $database = [],
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new NowoFormKitBundle();
        yield new NowoRuntimeEnvBundle();
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir() . '/nowo-runtime-env-persistence';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/' . md5(serialize([$this->doctrine, $this->database])) . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'persistence-test-secret',
            'test'                 => true,
            'http_method_override' => false,
            'router'               => ['utf8' => true],
            'csrf_protection'      => true,
            'form'                 => ['csrf_protection' => true],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);
        $container->extension('doctrine', $this->doctrine);
        $container->services()->set('logger', NullLogger::class);
        $container->services()->set(PersistenceTestController::class)
            ->autowire()
            ->public()
            ->tag('controller.service_arguments');
        $container->extension('nowo_runtime_env', [
            'web_ui'   => ['enabled' => false],
            'security' => ['allow_unauthenticated' => true],
            'database' => $this->database,
        ]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('t_set', '/t/set/{name}')->controller([PersistenceTestController::class, 'set']);
        $routes->add('t_fail', '/t/fail')->controller([PersistenceTestController::class, 'fail']);
    }
}
