<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\DependencyInjection;

use Nowo\FormKitBundle\DependencyInjection\FormKitExtension;
use Nowo\RuntimeEnvBundle\DependencyInjection\RuntimeEnvExtension;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle prepends its FormKit profile (REQ-TWIG-005 rule 11 / REQ-UI-003 "Form theme").
 */
final class RuntimeEnvExtensionFormKitTest extends TestCase
{
    public function testPrependsTheFormKitProfile(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());

        (new RuntimeEnvExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertCount(1, $configs);
        $profile = $configs[0]['profiles'][NowoRuntimeEnvBundle::FORM_KIT_PROFILE];
        self::assertSame(RuntimeEnvExtension::formKitProfile(), $profile);
        self::assertSame('runtime_env', $profile['alias']);
        self::assertSame('NowoRuntimeEnvBundle', $profile['translation_domain']);
        self::assertFalse($profile['auto_help']);
        self::assertFalse($profile['auto_placeholder']);
        self::assertSame([], $profile['field_types']);
    }

    public function testHostProfileWins(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        $container->prependExtensionConfig('nowo_form_kit', ['profiles' => ['runtime_env' => ['alias' => 'runtime_env', 'translation_domain' => 'app']]]);

        (new RuntimeEnvExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertCount(1, $configs);
        self::assertSame('app', $configs[0]['profiles']['runtime_env']['translation_domain']);
    }

    public function testOtherHostProfilesDoNotPreventThePrepend(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        $container->prependExtensionConfig('nowo_form_kit', ['translation_domain' => 'messages']);
        $container->prependExtensionConfig('nowo_form_kit', ['profiles' => ['default' => ['alias' => 'default']]]);

        (new RuntimeEnvExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertCount(3, $configs);
        self::assertArrayHasKey('runtime_env', $configs[0]['profiles']);
    }

    public function testNoFormKitExtensionPrependsNothing(): void
    {
        $container = new ContainerBuilder();

        (new RuntimeEnvExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_form_kit'));
    }
}
