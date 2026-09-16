<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit;

use Nowo\RuntimeEnvBundle\DependencyInjection\RuntimeEnvExtension;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoRuntimeEnvBundleTest extends TestCase
{
    public function testTranslationDomainConstant(): void
    {
        self::assertSame('NowoRuntimeEnvBundle', NowoRuntimeEnvBundle::TRANSLATION_DOMAIN);
    }

    public function testGetContainerExtensionReturnsRuntimeEnvExtension(): void
    {
        $bundle = new NowoRuntimeEnvBundle();

        self::assertInstanceOf(RuntimeEnvExtension::class, $bundle->getContainerExtension());
        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());
    }

    public function testBuildRegistersTwigPathsCompilerPass(): void
    {
        $container = new ContainerBuilder();

        (new NowoRuntimeEnvBundle())->build($container);

        self::assertNotEmpty($container->getCompilerPassConfig()->getPasses());
    }
}
