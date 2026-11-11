<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\RuntimeEnvEntityManagerPass;
use Nowo\RuntimeEnvBundle\Persistence\OwnEntityManagerSubscriber;
use Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(RuntimeEnvEntityManagerPass::class)]
final class RuntimeEnvEntityManagerPassTest extends TestCase
{
    #[Test]
    public function doesNothingWithoutDoctrine(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(RuntimeEnvEntityManagerPass::PARAMETER, 'default');
        (new RuntimeEnvEntityManagerPass())->process($container);
        self::assertFalse($container->hasDefinition(OwnEntityManagerSubscriber::class));
    }

    #[Test]
    public function aDedicatedManagerIsOwnedAndTheOnlyOneMappingTheEntity(): void
    {
        $container = $this->container('runtime_env', ['default' => 'x', 'runtime_env' => 'y']);
        $container->setDefinition('doctrine.orm.default_metadata_driver', $this->chain(true, true));
        $container->setDefinition('doctrine.orm.runtime_env_metadata_driver', $this->chain(true, false));

        (new RuntimeEnvEntityManagerPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls(), 'host mapping kept');
        self::assertTrue($container->getDefinition(DoctrineOrmRuntimeEnvVariableRepository::class)->getArgument('$ownsManager'));
        self::assertTrue($container->hasDefinition(OwnEntityManagerSubscriber::class));
    }

    #[Test]
    public function theHostDefaultManagerIsNeverOwned(): void
    {
        $container = $this->container('default', ['default' => 'x']);
        $container->setDefinition('doctrine.orm.default_metadata_driver', $this->chain(true, true));

        (new RuntimeEnvEntityManagerPass())->process($container);

        self::assertFalse($container->getDefinition(DoctrineOrmRuntimeEnvVariableRepository::class)->getArgument('$ownsManager'));
        self::assertFalse($container->hasDefinition(OwnEntityManagerSubscriber::class));
    }

    #[Test]
    public function unknownManagerFailsEvenInCheckOnlyMode(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('known: "default"');
        (new RuntimeEnvEntityManagerPass(checkOnly: true))->process($this->container('missing', ['default' => 'x']));
    }

    #[Test]
    public function checkOnlyModeLeavesTheMappingAlone(): void
    {
        $container = $this->container('runtime_env', ['default' => 'x', 'runtime_env' => 'y']);
        $container->setDefinition('doctrine.orm.default_metadata_driver', $this->chain(true, true));
        (new RuntimeEnvEntityManagerPass(checkOnly: true))->process($container);
        self::assertCount(2, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    #[Test]
    public function managerWithoutTheBundleMappingFails(): void
    {
        $container = $this->container('runtime_env', ['default' => 'x', 'runtime_env' => 'y']);
        $container->setDefinition('doctrine.orm.runtime_env_metadata_driver', $this->chain(false, true));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not map');
        (new RuntimeEnvEntityManagerPass())->process($container);
    }

    /**
     * @param array<string, string> $managers
     */
    private function container(string $name, array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(RuntimeEnvEntityManagerPass::PARAMETER, $name);
        $container->setParameter('doctrine.entity_managers', $managers);
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->setDefinition(DoctrineOrmRuntimeEnvVariableRepository::class, new Definition(DoctrineOrmRuntimeEnvVariableRepository::class));

        return $container;
    }

    private function chain(bool $bundle, bool $host): Definition
    {
        $chain = new Definition();
        if ($bundle) {
            $chain->addMethodCall('addDriver', [new Definition(), RuntimeEnvEntityManagerPass::ENTITY_NAMESPACE]);
        }
        if ($host) {
            $chain->addMethodCall('addDriver', [new Definition(), 'App\\Entity']);
        }

        return $chain;
    }
}
