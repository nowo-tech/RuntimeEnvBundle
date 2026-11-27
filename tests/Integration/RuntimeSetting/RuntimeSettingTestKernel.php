<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\RuntimeSetting;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvValueCipherInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriterInterface;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Bridge\Symfony\NowoRuntimeSettingBundle;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\Validator\SingleLineString;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function implode;
use function md5;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

/**
 * 1.6 host: RuntimeEnvBundle with `runtime_setting.enabled`, NowoRuntimeSettingBundle with a shared (filesystem) cache
 * pool, one registered setting (`DEMO_TOKEN`, source db) and, 1.10, one read from the DBAL query provider `tenant_db`
 * (`TENANT_URL`). Kept alive like a FrankenPHP worker with kernel reset false:
 * no services resetter.
 */
final class RuntimeSettingTestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    public function __construct(
        private readonly string $databaseUrl,
        private readonly string $poolDirectory,
        private readonly int $versionTtl = 1,
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
        yield new NowoRuntimeSettingBundle();
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir() . '/nowo-runtime-env-runtime-setting';
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/' . md5($this->databaseUrl . $this->poolDirectory . $this->versionTtl) . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/log';
    }

    public function tokenAction(): Response
    {
        /** @var RuntimeSettingResolver $resolver */
        $resolver = $this->getContainer()->get('test.resolver');
        /** @var RuntimeSettingDefinition $definition */
        $definition = $this->getContainer()->get('test.definition');

        return new Response((string) $resolver->get($definition));
    }

    public function tenantAction(): Response
    {
        /** @var RuntimeSettingResolver $resolver */
        $resolver = $this->getContainer()->get('test.resolver');
        /** @var RuntimeSettingDefinition $definition */
        $definition = $this->getContainer()->get('test.tenant_definition');

        return new Response(implode('|', $resolver->candidates($definition)));
    }

    /**
     * Worker mode with kernel reset false: nothing but the bundles' own boundary listeners clears state.
     */
    public function process(ContainerBuilder $container): void
    {
        $container->removeDefinition('messenger.listener.reset_services');
        $container->removeDefinition('services_resetter');
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'runtime-setting-test-secret-0123456789',
            'test'                 => true,
            'http_method_override' => false,
            'router'               => ['utf8' => true],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => $this->databaseUrl],
            'orm'  => [],
        ]);
        $container->extension('nowo_runtime_env', [
            'web_ui'          => ['enabled' => false],
            'security'        => ['allow_unauthenticated' => true],
            'runtime_setting' => ['enabled' => true],
            // 1.10: a DBAL query provider over a host table, shared through the pool for 60 seconds.
            'providers' => [
                'tenant_db' => [
                    'connection' => 'default',
                    'sql'        => 'SELECT url AS value, old_url, old_until FROM host_tenant_setting WHERE tenant = :tenant AND name = :key',
                    // An env constant: resolved at runtime, also into the provider's cache scope (audit ZDA-L2).
                    'parameters'              => ['tenant' => '%env(RS_TEST_TENANT)%'],
                    'previous_column'         => 'old_url',
                    'previous_expires_column' => 'old_until',
                    'cache_ttl'               => 60,
                ],
            ],
        ]);
        $container->extension('nowo_runtime_setting', [
            'cache_pool'        => 'test.shared_pool',
            'version_ttl'       => $this->versionTtl,
            'command'           => ['enabled' => true],
            'setting_providers' => ['TENANT_URL' => 'tenant_db'],
        ]);

        $container->parameters()->set('env(RS_TEST_TENANT)', 'acme');
        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set('test.shared_pool', FilesystemAdapter::class)->args(['runtime_setting', 0, $this->poolDirectory]);
        $services->set('nowo_runtime_setting.clock', MockClock::class)->args(['2026-11-16 12:00:00'])->public();
        $services->set(TestCipher::class);
        $services->alias(RuntimeEnvValueCipherInterface::class, TestCipher::class);
        $services->set('test.validator', SingleLineString::class)->args([64]);
        $services->set('test.definition', RuntimeSettingDefinition::class)
            ->args(['DEMO_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, RuntimeSettingErrorPolicy::FailClosed, false, service('test.validator')])
            ->tag(RuntimeSettingsConfiguration::DEFINITION_TAG)
            ->public();
        $services->set('test.tenant_definition', RuntimeSettingDefinition::class)
            ->args(['TENANT_URL', RuntimeSettingType::String, RuntimeSettingSource::Db, RuntimeSettingErrorPolicy::FailClosed, false, service('test.validator')])
            ->tag(RuntimeSettingsConfiguration::DEFINITION_TAG)
            ->public();
        $services->alias('test.resolver', RuntimeSettingResolver::class)->public();
        $services->alias('test.writer', RuntimeEnvWriterInterface::class)->public();
        $services->alias('test.updater', RuntimeSettingUpdater::class)->public();
        $services->alias('test.source', RuntimeValueSourceInterface::class)->public();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('t_token', '/t/token')->controller('kernel::tokenAction');
        $routes->add('t_tenant', '/t/tenant')->controller('kernel::tenantAction');
    }
}
