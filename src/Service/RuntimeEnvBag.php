<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Service;

use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;

/**
 * Request-scoped (worker-safe) bag of enabled runtime environment variables.
 *
 * Values are decrypted by DoctrineEncryptBundle on entity load. In-memory cache
 * is cleared on {@see reset()} between FrankenPHP worker requests and after writes.
 *
 * Does **not** mutate {@code $_ENV} / {@code putenv()} (REQ-CS-005).
 */
final class RuntimeEnvBag implements ResetInterface
{
    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(
        private readonly RuntimeEnvVariableRepositoryInterface $repository,
        private readonly bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->all());
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $all = $this->all();

        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        if (!$this->enabled) {
            return [];
        }

        if ($this->values !== null) {
            return $this->values;
        }

        $map = [];
        foreach ($this->repository->findAllEnabled() as $variable) {
            $map[$variable->getName()] = $variable->getValue();
        }

        return $this->values = $map;
    }

    /**
     * Clears the in-memory map after admin writes or between worker requests.
     */
    public function clearRuntimeCache(): void
    {
        $this->values = null;
    }

    public function reset(): void
    {
        $this->clearRuntimeCache();
    }
}
