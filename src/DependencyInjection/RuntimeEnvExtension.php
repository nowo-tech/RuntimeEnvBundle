<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\DependencyInjection;

use LogicException;
use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\Doctrine\RuntimeEnvMetadataListener;
use Nowo\RuntimeEnvBundle\EventSubscriber\RuntimeEnvAccessSubscriber;
use Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Routing\RuntimeEnvRouteLoader;
use Nowo\RuntimeEnvBundle\Security\AllowAllRuntimeEnvAccessChecker;
use Nowo\RuntimeEnvBundle\Security\ConfigurableRuntimeEnvAccessChecker;
use Nowo\RuntimeEnvBundle\Security\RuntimeEnvAccessCheckerInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Nowo\RuntimeEnvBundle\Twig\RuntimeEnvTwigExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function array_values;
use function dirname;
use function is_array;
use function is_string;
use function rtrim;

final class RuntimeEnvExtension extends Extension implements PrependExtensionInterface
{
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMappings($container);
        $this->prependUiKitDefaults($container);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        if (
            (bool) $config['panel']['enabled']
            && !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('NowoRuntimeEnvBundle panel requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $prefix         = rtrim((string) $config['table_prefix'], '_');
        $variablesTable = $prefix . '_variables';
        $emName         = (string) $config['database']['entity_manager'];

        $container->setParameter('nowo_runtime_env.enabled', (bool) $config['enabled']);
        $container->setParameter('nowo_runtime_env.table_prefix', $prefix);
        $container->setParameter('nowo_runtime_env.variables_table', $variablesTable);
        $container->setParameter('nowo_runtime_env.database.entity_manager', $emName);
        $container->setParameter('nowo_runtime_env.panel.enabled', (bool) $config['panel']['enabled']);
        $container->setParameter('nowo_runtime_env.panel.path_prefix', $config['panel']['path_prefix']);
        $container->setParameter('nowo_runtime_env.security', $config['security']);
        $container->setParameter('nowo_runtime_env.security.allow_unauthenticated', (bool) $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_runtime_env.web_ui', $config['web_ui']);
        $container->setParameter('nowo_runtime_env.templates', $config['templates']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setDefinition(DoctrineOrmRuntimeEnvVariableRepository::class, (new Definition(DoctrineOrmRuntimeEnvVariableRepository::class))
            ->setAutowired(false)
            ->setArgument('$entityManager', null)
            ->setArgument('$registry', new Reference('doctrine'))
            ->setArgument('$entityManagerName', $emName));
        $container->setAlias(RuntimeEnvVariableRepositoryInterface::class, DoctrineOrmRuntimeEnvVariableRepository::class);

        $container->setDefinition(RuntimeEnvMetadataListener::class, (new Definition(RuntimeEnvMetadataListener::class))
            ->setAutowired(false)
            ->setArgument('$variablesTableName', $variablesTable)
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        $container->setDefinition(RuntimeEnvBag::class, (new Definition(RuntimeEnvBag::class))
            ->setAutowired(false)
            ->setArgument('$repository', new Reference(RuntimeEnvVariableRepositoryInterface::class))
            ->setArgument('$enabled', (bool) $config['enabled'])
            ->setArgument('$requestStack', new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.reset', ['method' => 'reset']));

        $container->setDefinition(RuntimeEnvWriter::class, (new Definition(RuntimeEnvWriter::class))
            ->setAutowired(false)
            ->setArgument('$repository', new Reference(RuntimeEnvVariableRepositoryInterface::class))
            ->setArgument('$bag', new Reference(RuntimeEnvBag::class)));

        $this->registerAccessChecker($container, $config['security']);
        $this->configurePanel($container, $config);
        $this->configureTwig($container);
        $this->configureRouteLoader($container, $config);
    }

    /**
     * @param array<string, mixed> $security
     */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        $customId = $security['access_checker'] ?? null;
        if (is_string($customId) && $customId !== '') {
            $container->setAlias(RuntimeEnvAccessCheckerInterface::class, $customId);

            return;
        }

        if (!empty($security['allow_unauthenticated'])) {
            $container->setDefinition(AllowAllRuntimeEnvAccessChecker::class, new Definition(AllowAllRuntimeEnvAccessChecker::class));
            $container->setAlias(RuntimeEnvAccessCheckerInterface::class, AllowAllRuntimeEnvAccessChecker::class);

            return;
        }

        /** @var list<string> $roles */
        $roles = array_values(array_filter(
            is_array($security['access_roles'] ?? null) ? $security['access_roles'] : [],
            static fn (mixed $r): bool => is_string($r) && $r !== '',
        ));

        $container->setDefinition(ConfigurableRuntimeEnvAccessChecker::class, (new Definition(ConfigurableRuntimeEnvAccessChecker::class))
            ->setAutowired(false)
            ->setArgument('$authorizationChecker', new Reference('security.authorization_checker'))
            ->setArgument('$accessRoles', $roles));
        $container->setAlias(RuntimeEnvAccessCheckerInterface::class, ConfigurableRuntimeEnvAccessChecker::class);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configurePanel(ContainerBuilder $container, array $config): void
    {
        if (!(bool) $config['panel']['enabled']) {
            $container->removeDefinition(RuntimeEnvManageController::class);
            $container->removeDefinition(RuntimeEnvAccessSubscriber::class);

            return;
        }

        $container->getDefinition(RuntimeEnvManageController::class)
            ->setArgument('$repository', new Reference(RuntimeEnvVariableRepositoryInterface::class))
            ->setArgument('$writer', new Reference(RuntimeEnvWriter::class))
            ->setArgument('$templates', $config['templates'])
            ->setArgument('$pathPrefix', $config['panel']['path_prefix']);

        $container->getDefinition(RuntimeEnvAccessSubscriber::class)
            ->setArgument('$accessChecker', new Reference(RuntimeEnvAccessCheckerInterface::class))
            ->setArgument('$pathPrefix', $config['panel']['path_prefix'])
            ->setArgument('$allowUnauthenticated', (bool) $config['security']['allow_unauthenticated']);
    }

    private function configureTwig(ContainerBuilder $container): void
    {
        $container->setDefinition(RuntimeEnvTwigExtension::class, (new Definition(RuntimeEnvTwigExtension::class))
            ->setAutowired(false)
            ->setArgument('$bag', new Reference(RuntimeEnvBag::class))
            ->addTag('twig.extension'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureRouteLoader(ContainerBuilder $container, array $config): void
    {
        $container->setDefinition(RuntimeEnvRouteLoader::class, (new Definition(RuntimeEnvRouteLoader::class))
            ->setAutowired(false)
            ->setArgument('$panelEnabled', (bool) $config['panel']['enabled'])
            ->setArgument('$pathPrefix', (string) $config['panel']['path_prefix'])
            ->addTag('routing.loader'));
    }

    private function prependDoctrineMappings(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'RuntimeEnvBundle' => [
                        'type'      => 'attribute',
                        'dir'       => dirname(__DIR__) . '/Entity',
                        'prefix'    => 'Nowo\RuntimeEnvBundle\Entity',
                        'alias'     => 'RuntimeEnvBundle',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);
    }

    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        $config   = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $webUi    = is_array($config['web_ui'] ?? null) ? $config['web_ui'] : [];
        $defaults = [];

        if (!$hostHasCssFramework) {
            $fw                        = (string) ($webUi['css_framework'] ?? 'custom');
            $defaults['css_framework'] = $fw === 'bootstrap' ? 'bootstrap5' : $fw;
        }
        if (!$hostHasIconSet) {
            $defaults['icon_set'] = (string) ($webUi['icon_set'] ?? 'none');
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }

    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        return $container->hasExtension('security');
    }
}
