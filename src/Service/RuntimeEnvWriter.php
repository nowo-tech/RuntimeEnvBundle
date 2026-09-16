<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Service;

use InvalidArgumentException;
use Nowo\RuntimeEnvBundle\Entity\RuntimeEnvVariable;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;

use function preg_match;
use function sprintf;

/**
 * Persists runtime env variables and invalidates {@see RuntimeEnvBag} cache.
 */
final class RuntimeEnvWriter
{
    public const NAME_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    public function __construct(
        private readonly RuntimeEnvVariableRepositoryInterface $repository,
        private readonly RuntimeEnvBag $bag,
    ) {
    }

    public function create(string $name, string $value, ?string $description = null, bool $enabled = true): RuntimeEnvVariable
    {
        $this->assertValidName($name);
        if ($this->repository->findOneByName($name) instanceof RuntimeEnvVariable) {
            throw new InvalidArgumentException(sprintf('Runtime env variable "%s" already exists.', $name));
        }

        $variable = new RuntimeEnvVariable($name, $value, $description, $enabled);
        $this->repository->save($variable);
        $this->bag->clearRuntimeCache();

        return $variable;
    }

    public function update(RuntimeEnvVariable $variable, string $value, ?string $description, bool $enabled): RuntimeEnvVariable
    {
        $variable
            ->setValue($value)
            ->setDescription($description)
            ->setEnabled($enabled);
        $this->repository->save($variable);
        $this->bag->clearRuntimeCache();

        return $variable;
    }

    public function rename(RuntimeEnvVariable $variable, string $newName): RuntimeEnvVariable
    {
        $this->assertValidName($newName);
        if ($newName !== $variable->getName()) {
            $existing = $this->repository->findOneByName($newName);
            if ($existing instanceof RuntimeEnvVariable) {
                throw new InvalidArgumentException(sprintf('Runtime env variable "%s" already exists.', $newName));
            }
            $variable->setName($newName);
            $this->repository->save($variable);
            $this->bag->clearRuntimeCache();
        }

        return $variable;
    }

    public function delete(RuntimeEnvVariable $variable): void
    {
        $this->repository->remove($variable);
        $this->bag->clearRuntimeCache();
    }

    private function assertValidName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Variable name must match ^[A-Z][A-Z0-9_]*$ (e.g. MY_API_TOKEN).');
        }
    }
}
