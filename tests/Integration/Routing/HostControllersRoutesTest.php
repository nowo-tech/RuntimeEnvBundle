<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Routing;

use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\Tests\Kernel\HostControllersRoutesKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function str_starts_with;
use function sys_get_temp_dir;

/**
 * REQ-SF-004 rule 7: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default
 * `config/routes.yaml`) must not get the bundle routes without importing the `nowo_runtime_env` loader.
 */
final class HostControllersRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }

        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-runtime-env-host-controllers');
    }

    public function testRoutingControllersImportDoesNotRegisterBundleRoutes(): void
    {
        $kernel = new HostControllersRoutesKernel('test', true);
        $kernel->boot();

        try {
            $container     = $kernel->getContainer();
            $testContainer = $container->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $testContainer);
            self::assertTrue($testContainer->has(RuntimeEnvManageController::class), 'The manage controller service must be registered.');

            $router = $container->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);

            foreach ($router->getRouteCollection()->all() as $name => $route) {
                self::assertFalse(str_starts_with($name, 'nowo_runtime_env'), 'Bundle route "' . $name . '" must stay opt-in.');
                $controller = $route->getDefault('_controller');
                self::assertFalse(
                    is_string($controller) && str_starts_with($controller, RuntimeEnvManageController::class),
                    'Route "' . $name . '" points to the bundle controller.',
                );
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
