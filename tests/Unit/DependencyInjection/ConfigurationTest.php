<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\DependencyInjection;

use Nowo\RuntimeEnvBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['enabled']);
        self::assertSame('runtime_env', $config['table_prefix']);
        self::assertSame('default', $config['database']['entity_manager']);
        self::assertTrue($config['panel']['enabled']);
        self::assertNull($config['panel']['path_prefix']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertNull($config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertSame([], $config['web_ui']['templates']);
        self::assertSame(50, $config['web_ui']['list_page_size']);
        self::assertNull($config['web_ui']['css_framework']);
        self::assertNull($config['web_ui']['icon_set']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertNull($config['templates']['index']);
        self::assertNull($config['templates']['form']);
        self::assertNull($config['templates']['layout']);
    }

    public function testCanonicalWebUiKeys(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => [
                'path_prefix'     => '/admin/env',
                'layout_template' => 'layouts/admin.html.twig',
                'templates'       => ['index' => 'admin/env/index.html.twig'],
                'list_page_size'  => 25,
            ],
        ]]);

        self::assertSame('/admin/env', $config['web_ui']['path_prefix']);
        self::assertSame('layouts/admin.html.twig', $config['web_ui']['layout_template']);
        self::assertSame(['index' => 'admin/env/index.html.twig'], $config['web_ui']['templates']);
        self::assertSame(25, $config['web_ui']['list_page_size']);
    }

    public function testRemovedBundleLayoutIsMappedToTheUiKitLayout(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['layout_template' => Configuration::LEGACY_DEFAULT_LAYOUT],
        ]]);

        self::assertNull($config['web_ui']['layout_template']);
    }

    public function testUnknownTemplatePageIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown nowo_runtime_env.web_ui.templates page(s) "show"; known: index, form.');

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['templates' => ['show' => 'x.html.twig']],
        ]]);
    }

    public function testEmptyPathPrefixIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('web_ui.path_prefix must be a non-empty path');

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['path_prefix' => ' '],
        ]]);
    }

    #[IgnoreDeprecations]
    public function testEmptyDeprecatedPanelPathPrefixIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('panel.path_prefix must be a non-empty path');

        (new Processor())->processConfiguration(new Configuration(), [[
            'panel' => ['path_prefix' => ''],
        ]]);
    }

    public function testListPageSizeIsBounded(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['list_page_size' => 0],
        ]]);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string}>
     */
    public static function deprecatedOptions(): iterable
    {
        yield 'panel.enabled' => [['panel', 'enabled'], 'web_ui.enabled'];
        yield 'panel.path_prefix' => [['panel', 'path_prefix'], 'web_ui.path_prefix'];
        yield 'templates.index' => [['templates', 'index'], 'web_ui.templates.index'];
        yield 'templates.form' => [['templates', 'form'], 'web_ui.templates.form'];
        yield 'web_ui.css_framework' => [['web_ui', 'css_framework'], 'nowo_ui_kit.css_framework'];
        yield 'web_ui.icon_set' => [['web_ui', 'icon_set'], 'nowo_ui_kit.icon_set'];
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('deprecatedOptions')]
    public function testLegacyOptionsAreDeprecatedInFavourOfTheCanonicalKeys(array $path, string $replacement): void
    {
        $node = (new Configuration())->getConfigTreeBuilder()->buildTree();
        foreach ($path as $name) {
            self::assertInstanceOf(ArrayNode::class, $node);
            $node = $node->getChildren()[$name];
        }
        self::assertInstanceOf(BaseNode::class, $node);

        self::assertTrue($node->isDeprecated());
        $deprecation = $node->getDeprecation($path[1], 'nowo_runtime_env.' . $path[0]);
        self::assertSame('nowo-tech/runtime-env-bundle', $deprecation['package']);
        self::assertSame('1.3', $deprecation['version']);
        self::assertStringContainsString($replacement, $deprecation['message']);
    }

    #[IgnoreDeprecations]
    public function testCustomConfigurationOverridesDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'enabled'      => false,
            'table_prefix' => 'tenant_runtime',
            'database'     => ['entity_manager' => 'reporting'],
            'panel'        => [
                'enabled'     => false,
                'path_prefix' => '/admin/runtime-env',
            ],
            'web_ui' => [
                'enabled'         => false,
                'layout_template' => '@App/layout.html.twig',
                'css_framework'   => 'bootstrap',
                'icon_set'        => 'tabler-icons',
            ],
            'security' => [
                'access_roles'          => ['ROLE_SUPER_ADMIN'],
                'access_checker'        => 'app.runtime_env_checker',
                'allow_unauthenticated' => true,
            ],
            'templates' => [
                'index' => '@App/runtime_env/index.html.twig',
                'form'  => '@App/runtime_env/form.html.twig',
            ],
        ]]);

        self::assertFalse($config['enabled']);
        self::assertSame('tenant_runtime', $config['table_prefix']);
        self::assertSame('reporting', $config['database']['entity_manager']);
        self::assertFalse($config['panel']['enabled']);
        self::assertSame('/admin/runtime-env', $config['panel']['path_prefix']);
        self::assertFalse($config['web_ui']['enabled']);
        self::assertSame('@App/layout.html.twig', $config['web_ui']['layout_template']);
        self::assertSame('bootstrap', $config['web_ui']['css_framework']);
        self::assertSame('tabler-icons', $config['web_ui']['icon_set']);
        self::assertSame(['ROLE_SUPER_ADMIN'], $config['security']['access_roles']);
        self::assertSame('app.runtime_env_checker', $config['security']['access_checker']);
        self::assertTrue($config['security']['allow_unauthenticated']);
        self::assertSame('@App/runtime_env/index.html.twig', $config['templates']['index']);
        self::assertSame('@App/runtime_env/form.html.twig', $config['templates']['form']);
        self::assertNull($config['templates']['layout']);
    }

    public function testTemplatesLayoutIsDeprecatedInFavourOfWebUiLayoutTemplate(): void
    {
        $root = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $root);
        $templates = $root->getChildren()['templates'];
        self::assertInstanceOf(ArrayNode::class, $templates);
        $layout = $templates->getChildren()['layout'];
        self::assertInstanceOf(BaseNode::class, $layout);

        self::assertTrue($layout->isDeprecated());
        $deprecation = $layout->getDeprecation('layout', 'nowo_runtime_env.templates');
        self::assertSame('nowo-tech/runtime-env-bundle', $deprecation['package']);
        self::assertSame('1.1', $deprecation['version']);
        self::assertStringContainsString('web_ui.layout_template', $deprecation['message']);
    }

    public function testEmptyAccessRolesRejectedWhenAuthenticatedGateIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('access_roles');

        (new Processor())->processConfiguration(new Configuration(), [[
            'security' => [
                'access_roles' => [],
            ],
        ]]);
    }
}
