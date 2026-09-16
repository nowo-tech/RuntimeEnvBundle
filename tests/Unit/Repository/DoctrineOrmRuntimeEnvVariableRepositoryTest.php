<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DoctrineOrmRuntimeEnvVariableRepositoryTest extends TestCase
{
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    /** @var EntityRepository<RuntimeEnvVariable>&MockObject */
    private EntityRepository $doctrineRepository;

    private DoctrineOrmRuntimeEnvVariableRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager      = $this->createMock(EntityManagerInterface::class);
        $this->doctrineRepository = $this->createMock(EntityRepository::class);
        $this->repository         = new DoctrineOrmRuntimeEnvVariableRepository($this->entityManager);
    }

    public function testFindDelegatesToEntityManager(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');

        $this->entityManager->expects(self::once())
            ->method('find')
            ->with(RuntimeEnvVariable::class, 7)
            ->willReturn($variable);

        self::assertSame($variable, $this->repository->find(7));
    }

    public function testFindOneByNameDelegatesToDoctrineRepository(): void
    {
        $variable = new RuntimeEnvVariable('APP_KEY', 'secret');

        $this->entityManager->expects(self::once())
            ->method('getRepository')
            ->with(RuntimeEnvVariable::class)
            ->willReturn($this->doctrineRepository);
        $this->doctrineRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['name' => 'APP_KEY'])
            ->willReturn($variable);

        self::assertSame($variable, $this->repository->findOneByName('APP_KEY'));
    }

    public function testFindAllOrderedByNameDelegatesToDoctrineRepository(): void
    {
        $variables = [
            new RuntimeEnvVariable('A', '1'),
            new RuntimeEnvVariable('B', '2'),
        ];

        $this->entityManager->expects(self::once())
            ->method('getRepository')
            ->with(RuntimeEnvVariable::class)
            ->willReturn($this->doctrineRepository);
        $this->doctrineRepository->expects(self::once())
            ->method('findBy')
            ->with([], ['name' => 'ASC'])
            ->willReturn($variables);

        self::assertSame($variables, $this->repository->findAllOrderedByName());
    }

    public function testFindAllEnabledDelegatesToDoctrineRepository(): void
    {
        $variables = [new RuntimeEnvVariable('FEATURE_FLAG', '1')];

        $this->entityManager->expects(self::once())
            ->method('getRepository')
            ->with(RuntimeEnvVariable::class)
            ->willReturn($this->doctrineRepository);
        $this->doctrineRepository->expects(self::once())
            ->method('findBy')
            ->with(['enabled' => true], ['name' => 'ASC'])
            ->willReturn($variables);

        self::assertSame($variables, $this->repository->findAllEnabled());
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
}
