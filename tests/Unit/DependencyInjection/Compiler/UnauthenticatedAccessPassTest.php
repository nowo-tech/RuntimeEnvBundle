<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class UnauthenticatedAccessPassTest extends TestCase
{
    /**
     * @return iterable<string, array{0: bool|null}>
     */
    public static function nonDebugKernels(): iterable
    {
        yield 'kernel.debug false' => [false];
        yield 'kernel.debug unknown' => [null];
    }

    #[DataProvider('nonDebugKernels')]
    public function testUnauthenticatedManageUiIsRefusedOutsideDebug(?bool $debug): void
    {
        $container = $this->container(true, true, $debug);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('allow_unauthenticated: true is only allowed when kernel.debug is true');

        (new UnauthenticatedAccessPass())->process($container);
    }

    public function testUnauthenticatedManageUiIsAllowedInDebug(): void
    {
        (new UnauthenticatedAccessPass())->process($this->container(true, true, true));

        $this->addToAssertionCount(1);
    }

    public function testAuthenticatedOrDisabledUiPasses(): void
    {
        (new UnauthenticatedAccessPass())->process($this->container(false, true, false));
        (new UnauthenticatedAccessPass())->process($this->container(true, false, false));
        (new UnauthenticatedAccessPass())->process(new ContainerBuilder());

        $this->addToAssertionCount(1);
    }

    private function container(bool $allowUnauthenticated, bool $ui, ?bool $debug): ContainerBuilder
    {
        $container = new ContainerBuilder();
        if ($ui) {
            $container->setDefinition(RuntimeEnvManageController::class, new Definition(RuntimeEnvManageController::class));
        }
        $container->setParameter('nowo_runtime_env.security.allow_unauthenticated', $allowUnauthenticated);
        if ($debug !== null) {
            $container->setParameter('kernel.debug', $debug);
        }

        return $container;
    }
}
