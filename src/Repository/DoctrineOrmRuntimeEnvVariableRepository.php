<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Throwable;

use function sprintf;

/**
 * Doctrine ORM storage for runtime env variables.
 *
 * Worker-safe without {@code kernel.reset}: when a {@see ManagerRegistry} is given the manager is resolved per call
 * and a closed manager is replaced; reads use {@see Query::HINT_REFRESH} so managed entities are re-hydrated
 * (and decrypted again) from the database instead of being served stale from the identity map.
 */
final class DoctrineOrmRuntimeEnvVariableRepository implements RuntimeEnvVariableRepositoryInterface
{
    /**
     * @param EntityManagerInterface|null $entityManager legacy fixed manager, used only when no registry is given
     */
    public function __construct(
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?ManagerRegistry $registry = null,
        private readonly ?string $entityManagerName = null,
    ) {
        if ($entityManager === null && $registry === null) {
            throw new InvalidArgumentException('Either an entity manager or a manager registry must be provided.');
        }
    }

    public function find(int $id): ?RuntimeEnvVariable
    {
        /** @var RuntimeEnvVariable|null $row */
        $row = $this->createRefreshingQuery('SELECT v FROM %s v WHERE v.id = :id')
            ->setParameter('id', $id)
            ->getOneOrNullResult();

        return $row;
    }

    public function findOneByName(string $name): ?RuntimeEnvVariable
    {
        /** @var RuntimeEnvVariable|null $row */
        $row = $this->createRefreshingQuery('SELECT v FROM %s v WHERE v.name = :name')
            ->setParameter('name', $name)
            ->getOneOrNullResult();

        return $row;
    }

    public function findAllOrderedByName(): array
    {
        /** @var list<RuntimeEnvVariable> $rows */
        $rows = $this->createRefreshingQuery('SELECT v FROM %s v ORDER BY v.name ASC')->getResult();

        return $rows;
    }

    public function findAllEnabled(): array
    {
        /** @var list<RuntimeEnvVariable> $rows */
        $rows = $this->createRefreshingQuery('SELECT v FROM %s v WHERE v.enabled = :enabled ORDER BY v.name ASC')
            ->setParameter('enabled', true)
            ->getResult();

        return $rows;
    }

    public function save(RuntimeEnvVariable $variable, bool $flush = true): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($variable);
        if ($flush) {
            $this->flush($entityManager);
        }
    }

    public function remove(RuntimeEnvVariable $variable, bool $flush = true): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->remove($variable);
        if ($flush) {
            $this->flush($entityManager);
        }
    }

    private function createRefreshingQuery(string $dql): Query
    {
        return $this->getEntityManager()
            ->createQuery(sprintf($dql, RuntimeEnvVariable::class))
            ->setHint(Query::HINT_REFRESH, true);
    }

    private function flush(EntityManagerInterface $entityManager): void
    {
        try {
            $entityManager->flush();
        } catch (Throwable $e) {
            if ($this->registry !== null && !$entityManager->isOpen()) {
                $this->registry->resetManager($this->entityManagerName);
            }

            throw $e;
        }
    }

    private function getEntityManager(): EntityManagerInterface
    {
        if ($this->registry === null) {
            /** @var EntityManagerInterface $entityManager guaranteed by the constructor */
            $entityManager = $this->entityManager;

            return $entityManager;
        }

        $entityManager = $this->registry->getManager($this->entityManagerName);
        if ($entityManager instanceof EntityManagerInterface && !$entityManager->isOpen()) {
            $entityManager = $this->registry->resetManager($this->entityManagerName);
        }

        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('Entity manager "%s" is not a Doctrine ORM entity manager.', $this->entityManagerName ?? 'default'));
        }

        return $entityManager;
    }
}
