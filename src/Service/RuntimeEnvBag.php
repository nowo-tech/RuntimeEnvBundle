<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Service;

use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use WeakReference;

use function array_key_exists;

/**
 * Request-scoped (worker-safe) bag of enabled runtime environment variables.
 *
 * Values are decrypted by DoctrineEncryptBundle on entity load. The in-memory map is bound to the current
 * main request: a new main request always reloads it, even when {@see reset()} is not called between
 * FrankenPHP worker requests. It is also cleared on {@see reset()} and after writes.
 *
 * Does **not** mutate {@code $_ENV} / {@code putenv()} (REQ-CS-005).
 */
final class RuntimeEnvBag implements ResetInterface
{
    /** @var array<string, string>|null */
    private ?array $values = null;

    /** @var WeakReference<Request>|null main request the memoized map belongs to (null outside HTTP) */
    private ?WeakReference $valuesRequest = null;

    public function __construct(
        private readonly RuntimeEnvVariableRepositoryInterface $repository,
        private readonly bool $enabled,
        private readonly ?RequestStack $requestStack = null,
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

        $request = $this->requestStack?->getMainRequest();
        if ($this->values !== null && $this->isMemoizedFor($request)) {
            return $this->values;
        }

        $map = [];
        foreach ($this->repository->findAllEnabled() as $variable) {
            $map[$variable->getName()] = $variable->getValue();
        }

        $this->valuesRequest = $request instanceof Request ? WeakReference::create($request) : null;

        return $this->values = $map;
    }

    /**
     * Clears the in-memory map after admin writes or between worker requests.
     */
    public function clearRuntimeCache(): void
    {
        $this->values        = null;
        $this->valuesRequest = null;
    }

    public function reset(): void
    {
        $this->clearRuntimeCache();
    }

    private function isMemoizedFor(?Request $request): bool
    {
        if (!$request instanceof Request) {
            return !$this->valuesRequest instanceof WeakReference;
        }

        return $this->valuesRequest?->get() === $request;
    }
}
