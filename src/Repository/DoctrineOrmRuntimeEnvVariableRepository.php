<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;

final class DoctrineOrmRuntimeEnvVariableRepository implements RuntimeEnvVariableRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function find(int $id): ?RuntimeEnvVariable
    {
        return $this->entityManager->find(RuntimeEnvVariable::class, $id);
    }

    public function findOneByName(string $name): ?RuntimeEnvVariable
    {
        return $this->entityManager
            ->getRepository(RuntimeEnvVariable::class)
            ->findOneBy(['name' => $name]);
    }

    public function findAllOrderedByName(): array
    {
        /** @var list<RuntimeEnvVariable> $rows */
        $rows = $this->entityManager
            ->getRepository(RuntimeEnvVariable::class)
            ->findBy([], ['name' => 'ASC']);

        return $rows;
    }

    public function findAllEnabled(): array
    {
        /** @var list<RuntimeEnvVariable> $rows */
        $rows = $this->entityManager
            ->getRepository(RuntimeEnvVariable::class)
            ->findBy(['enabled' => true], ['name' => 'ASC']);

        return $rows;
    }

    public function save(RuntimeEnvVariable $variable, bool $flush = true): void
    {
        $this->entityManager->persist($variable);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function remove(RuntimeEnvVariable $variable, bool $flush = true): void
    {
        $this->entityManager->remove($variable);
        if ($flush) {
            $this->entityManager->flush();
        }
    }
}
