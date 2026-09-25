<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use InvalidArgumentException;
use LogicException;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DoctrineOrmRuntimeEnvVariableRepositoryTest extends TestCase
{
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    private DoctrineOrmRuntimeEnvVariableRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->repository    = new DoctrineOrmRuntimeEnvVariableRepository($this->entityManager);
    }

    public function testConstructorRequiresEntityManagerOrRegistry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DoctrineOrmRuntimeEnvVariableRepository();
    }

    public function testSavePersistsAndOptionallyFlushes(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');

        $this->entityManager->expects(self::exactly(2))
            ->method('persist')
            ->with($variable);
        $this->entityManager->expects(self::once())
            ->method('flush');

        $this->repository->save($variable, false);
        $this->repository->save($variable, true);
    }

    public function testRemoveDeletesAndOptionallyFlushes(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');

        $this->entityManager->expects(self::exactly(2))
            ->method('remove')
            ->with($variable);
        $this->entityManager->expects(self::once())
            ->method('flush');

        $this->repository->remove($variable, false);
        $this->repository->remove($variable, true);
    }

    public function testLegacyEntityManagerFlushFailureIsRethrown(): void
    {
        $this->entityManager->method('flush')->willThrowException(new RuntimeException('duplicate'));
        $this->entityManager->expects(self::never())->method('isOpen');

        $this->expectException(RuntimeException::class);

        $this->repository->save(new RuntimeEnvVariable('APP_KEY', 'secret'));
    }

    public function testRegistryManagerIsResolvedOnEveryCall(): void
    {
        $first  = $this->createOpenEntityManager();
        $second = $this->createOpenEntityManager();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::exactly(2))
            ->method('getManager')
            ->with('reporting')
            ->willReturnOnConsecutiveCalls($first, $second);
        $registry->expects(self::never())->method('resetManager');

        $first->expects(self::once())->method('persist');
        $second->expects(self::once())->method('remove');

        $repository = new DoctrineOrmRuntimeEnvVariableRepository(null, $registry, 'reporting');
        $repository->save(new RuntimeEnvVariable('A', '1'), false);
        $repository->remove(new RuntimeEnvVariable('A', '1'), false);
    }

    public function testClosedManagerLeftByAPreviousRequestIsReplaced(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects(self::never())->method('persist');

        $fresh = $this->createOpenEntityManager();
        $fresh->expects(self::once())->method('persist');
        $fresh->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('default')->willReturn($closed);
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturn($fresh);

        $repository = new DoctrineOrmRuntimeEnvVariableRepository(null, $registry, 'default');
        $repository->save(new RuntimeEnvVariable('A', '1'));
    }

    public function testFailedFlushResetsTheClosedManagerSoTheNextRequestWorks(): void
    {
        $open = true;
        $em1  = $this->createMock(EntityManagerInterface::class);
        $em1->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $em1->method('flush')->willReturnCallback(static function () use (&$open): void {
            $open = false;

            throw new RuntimeException('Unique constraint violation');
        });

        $em2 = $this->createOpenEntityManager();
        $em2->expects(self::once())->method('flush');

        $managers = ['current' => $em1];
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(static function () use (&$managers): ObjectManager {
            return $managers['current'];
        });
        $registry->expects(self::once())
            ->method('resetManager')
            ->with('default')
            ->willReturnCallback(static function () use (&$managers, $em2): ObjectManager {
                return $managers['current'] = $em2;
            });

        $repository = new DoctrineOrmRuntimeEnvVariableRepository(null, $registry, 'default');

        try {
            $repository->save(new RuntimeEnvVariable('DUP', '1'));
            self::fail('Flush exception must be rethrown.');
        } catch (RuntimeException $e) {
            self::assertSame('Unique constraint violation', $e->getMessage());
        }

        $repository->save(new RuntimeEnvVariable('NEXT', '2'));
    }

    public function testFailedFlushOnStillOpenManagerDoesNotReset(): void
    {
        $em = $this->createOpenEntityManager();
        $em->method('flush')->willThrowException(new RuntimeException('transient'));

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $registry->expects(self::never())->method('resetManager');

        $repository = new DoctrineOrmRuntimeEnvVariableRepository(null, $registry);

        $this->expectException(RuntimeException::class);
        $repository->remove(new RuntimeEnvVariable('A', '1'));
    }

    public function testNonOrmManagerIsRejected(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));

        $repository = new DoctrineOrmRuntimeEnvVariableRepository(null, $registry, 'odm');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Entity manager "odm" is not a Doctrine ORM entity manager.');

        $repository->save(new RuntimeEnvVariable('A', '1'));
    }

    /**
     * @return EntityManagerInterface&MockObject
     */
    private function createOpenEntityManager(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        return $em;
    }
}
