<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function dirname;

final class TwigPathsPassTest extends TestCase
{
    public function testProcessWithoutTwigLoaderIsNoOp(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native'));
    }

    public function testProcessAddsBundleViewsPathToNativeLoader(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        $definition = new Definition('Twig\\Loader\\FilesystemLoader');
        $container->setDefinition('twig.loader.native', $definition);

        (new TwigPathsPass())->process($container);

        $calls = $definition->getMethodCalls();
        self::assertNotEmpty($calls);

        $addPathCall = null;
        foreach ($calls as $call) {
            if ($call[0] === 'addPath') {
                $addPathCall = $call;
                break;
            }
        }

        self::assertNotNull($addPathCall);
        self::assertStringContainsString('Resources/views', $addPathCall[1][0]);
        self::assertSame('NowoRuntimeEnvBundle', $addPathCall[1][1]);
    }

    public function testProcessUsesNativeFilesystemLoaderDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        $definition = new Definition('Twig\\Loader\\FilesystemLoader');
        $container->setDefinition('twig.loader.native_filesystem', $definition);

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($definition->getMethodCalls());
    }

    public function testProcessResolvesChainedAliasToDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        $definition = new Definition('Twig\\Loader\\FilesystemLoader');
        $container->setDefinition('twig.loader.real', $definition);
        $container->setAlias('twig.loader.chain', 'twig.loader.real');
        $container->setAlias('twig.loader.native', 'twig.loader.chain');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($definition->getMethodCalls());
    }

    public function testProcessIgnoresAliasWithoutDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setAlias('twig.loader.native', 'twig.loader.missing');

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.missing'));
    }

    public function testProcessPrependsOverridePathWhenDirectoryExists(): void
    {
        $projectDir  = sys_get_temp_dir() . '/runtime_env_project_' . uniqid('', true);
        $overrideDir = $projectDir . '/templates/bundles/NowoRuntimeEnvBundle';
        mkdir($overrideDir, 0777, true);

        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $projectDir);

            $definition = new Definition('Twig\\Loader\\FilesystemLoader');
            $container->setDefinition('twig.loader.native', $definition);

            (new TwigPathsPass())->process($container);

            $prependCall = null;
            foreach ($definition->getMethodCalls() as $call) {
                if ($call[0] === 'prependPath') {
                    $prependCall = $call;
                    break;
                }
            }

            self::assertNotNull($prependCall);
            self::assertSame($overrideDir, $prependCall[1][0]);
            self::assertSame('NowoRuntimeEnvBundle', $prependCall[1][1]);
        } finally {
            rmdir($overrideDir);
            rmdir(dirname($overrideDir));
            rmdir(dirname($overrideDir, 2));
            rmdir($projectDir);
        }
    }
}
