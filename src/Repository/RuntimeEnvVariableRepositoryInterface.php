<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Repository;

use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;

interface RuntimeEnvVariableRepositoryInterface
{
    public function find(int $id): ?RuntimeEnvVariable;

    public function findOneByName(string $name): ?RuntimeEnvVariable;

    /**
     * @return list<RuntimeEnvVariable>
     */
    public function findAllOrderedByName(): array;

    /**
     * @return list<RuntimeEnvVariable>
     */
    public function findAllEnabled(): array;

    public function save(RuntimeEnvVariable $variable, bool $flush = true): void;

    public function remove(RuntimeEnvVariable $variable, bool $flush = true): void;
}
