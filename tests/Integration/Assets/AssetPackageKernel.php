<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Assets;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function md5;
use function serialize;
use function sys_get_temp_dir;

/**
 * Host with `framework.assets` configured as given and the bundle's web UI off: only the asset packages matter.
 */
final class AssetPackageKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $assets `framework.assets` configuration of the host
     */
    public function __construct(private readonly array $assets)
    {
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
        return sys_get_temp_dir() . '/nowo-runtime-env-assets';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/' . md5(serialize($this->assets)) . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'asset-package-test-secret-0123456789',
            'test'                 => true,
            'http_method_override' => false,
            'assets'               => $this->assets,
        ]);
        $container->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => []]);
        $container->extension('nowo_runtime_env', [
            'web_ui'   => ['enabled' => false],
            'security' => ['allow_unauthenticated' => true],
        ]);
        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->alias('test.assets', 'assets.packages')->public();
    }
}
