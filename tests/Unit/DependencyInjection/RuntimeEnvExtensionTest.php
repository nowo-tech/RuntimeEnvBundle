<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\DependencyInjection\Configuration;
use Nowo\RuntimeEnvBundle\DependencyInjection\RuntimeEnvExtension;
use Nowo\RuntimeEnvBundle\Doctrine\RuntimeEnvMetadataListener;
use Nowo\RuntimeEnvBundle\EventSubscriber\RuntimeEnvAccessSubscriber;
use Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository;
use Nowo\RuntimeEnvBundle\Repository\PaginatedRuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Routing\RuntimeEnvRouteLoader;
use Nowo\RuntimeEnvBundle\Security\AllowAllRuntimeEnvAccessChecker;
use Nowo\RuntimeEnvBundle\Security\ConfigurableRuntimeEnvAccessChecker;
use Nowo\RuntimeEnvBundle\Security\RuntimeEnvAccessCheckerInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Nowo\RuntimeEnvBundle\Twig\RuntimeEnvTwigExtension;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPageFactory;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPages;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class RuntimeEnvExtensionTest extends TestCase
{
    private RuntimeEnvExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new RuntimeEnvExtension();
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_runtime_env', $this->extension->getAlias());
    }

    public function testLoadThrowsWhenSecurityBundleIsMissingAndUnauthenticatedAccessIsDisabled(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('NowoRuntimeEnvBundle panel requires symfony/security-bundle when security.allow_unauthenticated is false.');

        $this->extension->load([], new ContainerBuilder());
    }

    public function testLoadRegistersParametersDefinitionsAndAliases(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->dummyExtension('security'));

        $this->extension->load([], $container);

        self::assertTrue($container->hasDefinition(DoctrineOrmRuntimeEnvVariableRepository::class));
        self::assertTrue($container->hasAlias(RuntimeEnvVariableRepositoryInterface::class));
        self::assertSame(DoctrineOrmRuntimeEnvVariableRepository::class, (string) $container->getAlias(RuntimeEnvVariableRepositoryInterface::class));
        self::assertSame('runtime_env_variables', $container->getParameter('nowo_runtime_env.variables_table'));
        self::assertSame('runtime_env', $container->getParameter('nowo_runtime_env.table_prefix'));
        self::assertSame('default', $container->getParameter('nowo_runtime_env.database.entity_manager'));
        self::assertTrue($container->getParameter('nowo_runtime_env.panel.enabled'));
        self::assertSame('/_runtime_env', $container->getParameter('nowo_runtime_env.panel.path_prefix'));
        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_runtime_env.security')['access_roles']);

        $repoDefinition = $container->getDefinition(DoctrineOrmRuntimeEnvVariableRepository::class);
        self::assertNull($repoDefinition->getArgument('$entityManager'));
        self::assertSame('doctrine', (string) $repoDefinition->getArgument('$registry'));
        self::assertSame('default', $repoDefinition->getArgument('$entityManagerName'));

        $metadataDefinition = $container->getDefinition(RuntimeEnvMetadataListener::class);
        self::assertSame('runtime_env_variables', $metadataDefinition->getArgument('$variablesTableName'));
        self::assertSame([['event' => 'loadClassMetadata']], $metadataDefinition->getTag('doctrine.event_listener'));

        $bagDefinition = $container->getDefinition(RuntimeEnvBag::class);
        self::assertSame(RuntimeEnvVariableRepositoryInterface::class, (string) $bagDefinition->getArgument('$repository'));
        self::assertTrue($bagDefinition->getArgument('$enabled'));
        self::assertSame('request_stack', (string) $bagDefinition->getArgument('$requestStack'));
        self::assertSame([['method' => 'reset']], $bagDefinition->getTag('kernel.reset'));

        $writerDefinition = $container->getDefinition(RuntimeEnvWriter::class);
        self::assertSame(RuntimeEnvVariableRepositoryInterface::class, (string) $writerDefinition->getArgument('$repository'));
        self::assertSame(RuntimeEnvBag::class, (string) $writerDefinition->getArgument('$bag'));

        $checkerDefinition = $container->getDefinition(ConfigurableRuntimeEnvAccessChecker::class);
        self::assertSame('security.authorization_checker', (string) $checkerDefinition->getArgument('$authorizationChecker'));
        self::assertSame(['ROLE_ADMIN'], $checkerDefinition->getArgument('$accessRoles'));
        self::assertSame(ConfigurableRuntimeEnvAccessChecker::class, (string) $container->getAlias(RuntimeEnvAccessCheckerInterface::class));

        $controllerDefinition = $container->getDefinition(RuntimeEnvManageController::class);
        self::assertSame(RuntimeEnvVariableRepositoryInterface::class, (string) $controllerDefinition->getArgument('$repository'));
        self::assertSame(RuntimeEnvWriter::class, (string) $controllerDefinition->getArgument('$writer'));
        self::assertSame('/_runtime_env', $controllerDefinition->getArgument('$pathPrefix'));

        $subscriberDefinition = $container->getDefinition(RuntimeEnvAccessSubscriber::class);
        self::assertSame(RuntimeEnvAccessCheckerInterface::class, (string) $subscriberDefinition->getArgument('$accessChecker'));
        self::assertSame('/_runtime_env', $subscriberDefinition->getArgument('$pathPrefix'));
        self::assertFalse($subscriberDefinition->getArgument('$allowUnauthenticated'));

        $twigDefinition = $container->getDefinition(RuntimeEnvTwigExtension::class);
        self::assertSame(RuntimeEnvBag::class, (string) $twigDefinition->getArgument('$bag'));
        self::assertArrayHasKey('twig.extension', $twigDefinition->getTags());

        $routeLoaderDefinition = $container->getDefinition(RuntimeEnvRouteLoader::class);
        self::assertTrue($routeLoaderDefinition->getArgument('$panelEnabled'));
        self::assertSame('/_runtime_env', $routeLoaderDefinition->getArgument('$pathPrefix'));
        self::assertArrayHasKey('routing.loader', $routeLoaderDefinition->getTags());
    }

    public function testLoadSupportsCustomCheckerServiceId(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->dummyExtension('security'));

        $this->extension->load([
            ['security' => ['access_checker' => 'app.runtime_env_checker']],
        ], $container);

        self::assertSame('app.runtime_env_checker', (string) $container->getAlias(RuntimeEnvAccessCheckerInterface::class));
        self::assertFalse($container->hasDefinition(ConfigurableRuntimeEnvAccessChecker::class));
    }

    public function testLoadRegistersAllowAllCheckerWhenUnauthenticatedAccessIsAllowed(): void
    {
        $container = new ContainerBuilder();

        $this->extension->load([
            ['security' => ['allow_unauthenticated' => true]],
        ], $container);

        self::assertTrue($container->hasDefinition(AllowAllRuntimeEnvAccessChecker::class));
        self::assertSame(AllowAllRuntimeEnvAccessChecker::class, (string) $container->getAlias(RuntimeEnvAccessCheckerInterface::class));
        self::assertTrue($container->getParameter('nowo_runtime_env.security.allow_unauthenticated'));
    }

    #[IgnoreDeprecations]
    public function testLoadWithPanelDisabledRemovesUiServicesAndNormalizesConfig(): void
    {
        $container = new ContainerBuilder();

        $this->extension->load([
            [
                'table_prefix' => 'tenant_runtime_',
                'database'     => ['entity_manager' => 'reporting'],
                'panel'        => [
                    'enabled'     => false,
                    'path_prefix' => '/admin/runtime-env',
                ],
                'security' => [
                    'access_roles' => ['ROLE_ADMIN', '', 'ROLE_SUPER_ADMIN'],
                ],
            ],
        ], $container);

        self::assertSame('tenant_runtime', $container->getParameter('nowo_runtime_env.table_prefix'));
        self::assertSame('tenant_runtime_variables', $container->getParameter('nowo_runtime_env.variables_table'));
        self::assertSame('reporting', $container->getParameter('nowo_runtime_env.database.entity_manager'));
        self::assertSame('reporting', $container->getDefinition(DoctrineOrmRuntimeEnvVariableRepository::class)->getArgument('$entityManagerName'));
        self::assertFalse($container->hasDefinition(RuntimeEnvManageController::class));
        self::assertFalse($container->hasDefinition(RuntimeEnvAccessSubscriber::class));
        self::assertFalse($container->getParameter('nowo_runtime_env.panel.enabled'));

        $routeLoaderDefinition = $container->getDefinition(RuntimeEnvRouteLoader::class);
        self::assertFalse($routeLoaderDefinition->getArgument('$panelEnabled'));
        self::assertSame('/admin/runtime-env', $routeLoaderDefinition->getArgument('$pathPrefix'));
    }

    public function testWebUiDisabledRemovesTheManageUiAndItsRoutes(): void
    {
        $container = new ContainerBuilder();

        // No security bundle needed: there is no panel to protect.
        $this->extension->load([['web_ui' => ['enabled' => false]]], $container);

        self::assertFalse($container->hasDefinition(RuntimeEnvManageController::class));
        self::assertFalse($container->hasDefinition(RuntimeEnvAccessSubscriber::class));
        self::assertFalse($container->getParameter('nowo_runtime_env.panel.enabled'));
        self::assertFalse($container->getDefinition(RuntimeEnvRouteLoader::class)->getArgument('$panelEnabled'));
        self::assertTrue($container->hasDefinition(RuntimeEnvBag::class));
    }

    public function testManageUiServicesAreWiredWithTheCanonicalKeys(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->dummyExtension('security'));

        $this->extension->load([['web_ui' => [
            'path_prefix'     => '/admin/env',
            'layout_template' => '@App/admin/base.html.twig',
            'templates'       => ['form' => 'admin/env/form.html.twig'],
            'list_page_size'  => 20,
        ]]], $container);

        $pages = $container->getDefinition(RuntimeEnvPages::class);
        self::assertSame('@App/admin/base.html.twig', $pages->getArgument('$layoutTemplate'));
        self::assertSame(['form' => 'admin/env/form.html.twig'], $pages->getArgument('$templates'));
        self::assertSame('twig', (string) $pages->getArgument('$twig'));

        $factory = $container->getDefinition(RuntimeEnvPageFactory::class);
        self::assertSame('router', (string) $factory->getArgument('$urlGenerator'));
        self::assertSame(RuntimeEnvPages::class, (string) $factory->getArgument('$pages'));

        $controller = $container->getDefinition(RuntimeEnvManageController::class);
        self::assertFalse($controller->isAutoconfigured(), 'No routing.controller tag: routes only come from the prefixed loader.');
        self::assertTrue($controller->hasTag('container.service_subscriber'));
        self::assertTrue($controller->hasTag('controller.service_arguments'));
        self::assertSame('/admin/env', $controller->getArgument('$pathPrefix'));
        self::assertSame(20, $controller->getArgument('$listPageSize'));
        self::assertSame(RuntimeEnvPageFactory::class, (string) $controller->getArgument('$pageFactory'));
        self::assertSame('translator', (string) $controller->getArgument('$translator'));

        self::assertSame('/admin/env', $container->getDefinition(RuntimeEnvAccessSubscriber::class)->getArgument('$pathPrefix'));
        self::assertSame('/admin/env', $container->getDefinition(RuntimeEnvRouteLoader::class)->getArgument('$pathPrefix'));
        self::assertSame('/admin/env', $container->getParameter('nowo_runtime_env.web_ui.path_prefix'));
        self::assertSame('/admin/env', $container->getParameter('nowo_runtime_env.panel.path_prefix'));
        self::assertSame(20, $container->getParameter('nowo_runtime_env.web_ui.list_page_size'));
        self::assertSame([
            'index'  => '@NowoRuntimeEnvBundle/manage/index.html.twig',
            'form'   => 'admin/env/form.html.twig',
            'layout' => '@App/admin/base.html.twig',
        ], $container->getParameter('nowo_runtime_env.templates'));
        self::assertSame(DoctrineOrmRuntimeEnvVariableRepository::class, (string) $container->getAlias(PaginatedRuntimeEnvVariableRepositoryInterface::class));
    }

    public function testUiKitLayoutIsUsedWhenNothingIsConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->dummyExtension('security'));

        $this->extension->load([['web_ui' => ['layout_template' => Configuration::LEGACY_DEFAULT_LAYOUT]]], $container);

        self::assertNull($container->getDefinition(RuntimeEnvPages::class)->getArgument('$layoutTemplate'));
        self::assertSame([], $container->getDefinition(RuntimeEnvPages::class)->getArgument('$templates'));
        self::assertNull($container->getParameter('nowo_runtime_env.web_ui.layout_template'));
    }

    #[IgnoreDeprecations]
    public function testDeprecatedKeysAreTheFallbacksOfTheCanonicalOnes(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->dummyExtension('security'));

        $this->extension->load([[
            'panel'     => ['path_prefix' => '/legacy/env'],
            'templates' => [
                'layout' => '@App/legacy/layout.html.twig',
                'index'  => '@App/legacy/index.html.twig',
                'form'   => '@NowoRuntimeEnvBundle/manage/form.html.twig',
            ],
        ]], $container);

        $pages = $container->getDefinition(RuntimeEnvPages::class);
        self::assertSame('@App/legacy/layout.html.twig', $pages->getArgument('$layoutTemplate'));
        self::assertSame(['index' => '@App/legacy/index.html.twig'], $pages->getArgument('$templates'));
        self::assertSame('/legacy/env', $container->getDefinition(RuntimeEnvManageController::class)->getArgument('$pathPrefix'));
    }

    #[IgnoreDeprecations]
    public function testCanonicalKeysWinOverTheDeprecatedOnes(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->dummyExtension('security'));

        $this->extension->load([[
            'panel'     => ['path_prefix' => '/legacy/env'],
            'templates' => ['layout' => '@App/legacy/layout.html.twig', 'index' => '@App/legacy/index.html.twig'],
            'web_ui'    => [
                'path_prefix'     => '/admin/env',
                'layout_template' => '@App/admin/base.html.twig',
                'templates'       => ['index' => '@App/admin/index.html.twig'],
            ],
        ]], $container);

        $pages = $container->getDefinition(RuntimeEnvPages::class);
        self::assertSame('@App/admin/base.html.twig', $pages->getArgument('$layoutTemplate'));
        self::assertSame(['index' => '@App/admin/index.html.twig'], $pages->getArgument('$templates'));
        self::assertSame('/admin/env', $container->getDefinition(RuntimeEnvManageController::class)->getArgument('$pathPrefix'));
    }

    #[IgnoreDeprecations]
    public function testPrependAddsDoctrineMappingAndUiKitDefaults(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension);
        $container->registerExtension($this->dummyExtension('doctrine'));
        $container->registerExtension($this->dummyExtension('nowo_ui_kit'));
        $container->loadFromExtension('nowo_runtime_env', [
            'web_ui' => [
                'css_framework' => 'bootstrap',
                'icon_set'      => 'tabler-icons',
            ],
        ]);

        $this->extension->prepend($container);

        $doctrineConfig = $container->getExtensionConfig('doctrine');
        self::assertSame('attribute', $doctrineConfig[0]['orm']['mappings']['RuntimeEnvBundle']['type']);
        self::assertStringContainsString('/src/Entity', $doctrineConfig[0]['orm']['mappings']['RuntimeEnvBundle']['dir']);
        self::assertSame('Nowo\RuntimeEnvBundle\Entity', $doctrineConfig[0]['orm']['mappings']['RuntimeEnvBundle']['prefix']);

        $uiKitConfig = $container->getExtensionConfig('nowo_ui_kit');
        self::assertSame('bootstrap5', $uiKitConfig[0]['css_framework']);
        self::assertSame('tabler-icons', $uiKitConfig[0]['icon_set']);
    }

    public function testPrependIsNoOpWhenUiKitExtensionIsMissing(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension);

        $this->extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'));
        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    #[IgnoreDeprecations]
    public function testPrependDoesNotOverrideHostUiKitChoices(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension);
        $container->registerExtension($this->dummyExtension('nowo_ui_kit'));
        $container->loadFromExtension('nowo_runtime_env', [
            'web_ui' => [
                'css_framework' => 'tailwind',
                'icon_set'      => 'ux_icon',
            ],
        ]);
        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework' => 'foundation',
            'icon_set'      => 'svg_inline',
        ]);

        $this->extension->prepend($container);

        $uiKitConfig = $container->getExtensionConfig('nowo_ui_kit');
        self::assertSame('foundation', $uiKitConfig[0]['css_framework']);
        self::assertSame('svg_inline', $uiKitConfig[0]['icon_set']);
        self::assertCount(1, $uiKitConfig);
    }

    #[IgnoreDeprecations]
    public function testPrependSkipsNonArrayUiKitConfigEntries(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension);
        $container->registerExtension($this->dummyExtension('nowo_ui_kit'));
        $container->loadFromExtension('nowo_runtime_env', ['web_ui' => ['css_framework' => 'custom', 'icon_set' => 'none']]);

        $ref                      = new ReflectionProperty(ContainerBuilder::class, 'extensionConfigs');
        $configs                  = $ref->getValue($container);
        $configs['nowo_ui_kit'][] = 'invalid';
        $ref->setValue($container, $configs);

        $this->extension->prepend($container);

        $uiKitConfig = $container->getExtensionConfig('nowo_ui_kit');
        self::assertContains('invalid', $uiKitConfig);
        self::assertSame('custom', $uiKitConfig[0]['css_framework']);
        self::assertSame('none', $uiKitConfig[0]['icon_set']);
    }

    public function testPrependLeavesUiKitAloneWhenTheDeprecatedKeysAreNotSet(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension);
        $container->registerExtension($this->dummyExtension('nowo_ui_kit'));
        $container->loadFromExtension('nowo_runtime_env', []);

        $this->extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'));
    }

    private function dummyExtension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(
                private readonly string $alias,
            ) {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
