<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Service;

use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RuntimeEnvBagTest extends TestCase
{
    /** @var MockObject&RuntimeEnvVariableRepositoryInterface */
    private RuntimeEnvVariableRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(RuntimeEnvVariableRepositoryInterface::class);
    }

    public function testAllReturnsEmptyWhenDisabled(): void
    {
        $bag = new RuntimeEnvBag($this->repository, false);
        $this->repository->expects(self::never())->method('findAllEnabled');

        self::assertFalse($bag->isEnabled());
        self::assertSame([], $bag->all());
    }

    public function testGetAndHasMemoizeRepositoryResults(): void
    {
        $this->repository->expects(self::once())
            ->method('findAllEnabled')
            ->willReturn([
                new RuntimeEnvVariable('API_TOKEN', 'secret'),
                new RuntimeEnvVariable('FEATURE_X', '1'),
            ]);

        $bag = new RuntimeEnvBag($this->repository, true);
        self::assertTrue($bag->isEnabled());

        self::assertTrue($bag->has('API_TOKEN'));
        self::assertSame('secret', $bag->get('API_TOKEN'));
        self::assertSame('1', $bag->get('FEATURE_X'));
        self::assertNull($bag->get('MISSING'));
        self::assertSame('fallback', $bag->get('MISSING', 'fallback'));
    }

    public function testResetClearsMemoization(): void
    {
        $this->repository->expects(self::exactly(2))
            ->method('findAllEnabled')
            ->willReturnOnConsecutiveCalls(
                [new RuntimeEnvVariable('A', '1')],
                [new RuntimeEnvVariable('A', '2')],
            );

        $bag = new RuntimeEnvBag($this->repository, true);
        self::assertSame('1', $bag->get('A'));
        $bag->reset();
        self::assertSame('2', $bag->get('A'));
    }

    public function testClearRuntimeCacheAlsoClearsMemoization(): void
    {
        $this->repository->expects(self::exactly(2))
            ->method('findAllEnabled')
            ->willReturnOnConsecutiveCalls(
                [new RuntimeEnvVariable('A', '1')],
                [new RuntimeEnvVariable('A', '3')],
            );

        $bag = new RuntimeEnvBag($this->repository, true);
        self::assertSame('1', $bag->get('A'));
        $bag->clearRuntimeCache();
        self::assertSame('3', $bag->get('A'));
    }
}
