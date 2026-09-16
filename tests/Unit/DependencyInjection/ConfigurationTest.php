<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\DependencyInjection;

use Nowo\RuntimeEnvBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
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
        self::assertSame('/_runtime_env', $config['panel']['path_prefix']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('@NowoRuntimeEnvBundle/manage/layout.html.twig', $config['web_ui']['layout_template']);
        self::assertSame('custom', $config['web_ui']['css_framework']);
        self::assertSame('none', $config['web_ui']['icon_set']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertSame('@NowoRuntimeEnvBundle/manage/index.html.twig', $config['templates']['index']);
        self::assertSame('@NowoRuntimeEnvBundle/manage/form.html.twig', $config['templates']['form']);
        self::assertSame('@NowoRuntimeEnvBundle/manage/layout.html.twig', $config['templates']['layout']);
    }

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
                'index'  => '@App/runtime_env/index.html.twig',
                'form'   => '@App/runtime_env/form.html.twig',
                'layout' => '@App/runtime_env/layout.html.twig',
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
        self::assertSame('@App/runtime_env/layout.html.twig', $config['templates']['layout']);
    }
}
