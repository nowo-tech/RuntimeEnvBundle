<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Repository;

use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;

/**
 * Paginated reads for the manage list (REQ-PERF-001): one COUNT query plus one bounded SELECT per page.
 *
 * Separate from {@see RuntimeEnvVariableRepositoryInterface} so custom repositories written for 1.2 keep working: the
 * manage controller falls back to slicing {@see RuntimeEnvVariableRepositoryInterface::findAllOrderedByName()} when the
 * repository does not implement it.
 */
interface PaginatedRuntimeEnvVariableRepositoryInterface extends RuntimeEnvVariableRepositoryInterface
{
    /**
     * Number of stored variables (enabled or not).
     */
    public function countAll(): int;

    /**
     * One page of variables ordered by name.
     *
     * @return list<RuntimeEnvVariable>
     */
    public function findPageOrderedByName(int $limit, int $offset): array;
}
