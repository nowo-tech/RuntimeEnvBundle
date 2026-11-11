<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Service;

/**
 * Read port of the runtime env variables (1.5, rule 1): names and string values only.
 *
 * Implemented by {@see RuntimeEnvBag} (autowire this interface).
 */
interface RuntimeEnvReaderInterface
{
    /**
     * Whether an enabled variable with that name exists.
     */
    public function has(string $name): bool;

    /**
     * Value of an enabled variable, or the default.
     */
    public function get(string $name, ?string $default = null): ?string;

    /**
     * Every enabled variable, name => value.
     *
     * @return array<string, string>
     */
    public function all(): array;
}
