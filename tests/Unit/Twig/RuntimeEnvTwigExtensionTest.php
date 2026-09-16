<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Twig;

use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Twig\RuntimeEnvTwigExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

final class RuntimeEnvTwigExtensionTest extends TestCase
{
    public function testGetFunctionsRegistersExpectedTwigFunctions(): void
    {
        $functions = (new RuntimeEnvTwigExtension(new RuntimeEnvBag(
            $this->createMock(RuntimeEnvVariableRepositoryInterface::class),
            true,
        )))->getFunctions();

        self::assertCount(2, $functions);
        self::assertContainsOnlyInstancesOf(TwigFunction::class, $functions);
        self::assertSame('runtime_env', $functions[0]->getName());
        self::assertSame('runtime_env_has', $functions[1]->getName());
    }

    public function testGetDelegatesToBag(): void
    {
        $repository = $this->createMock(RuntimeEnvVariableRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findAllEnabled')
            ->willReturn([new RuntimeEnvVariable('APP_KEY', 'secret')]);

        $extension = new RuntimeEnvTwigExtension(new RuntimeEnvBag($repository, true));
        self::assertSame('secret', $extension->get('APP_KEY', 'fallback'));
    }

    public function testHasDelegatesToBag(): void
    {
        $repository = $this->createMock(RuntimeEnvVariableRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findAllEnabled')
            ->willReturn([new RuntimeEnvVariable('FEATURE_FLAG', '1')]);

        $extension = new RuntimeEnvTwigExtension(new RuntimeEnvBag($repository, true));
        self::assertTrue($extension->has('FEATURE_FLAG'));
    }
}
