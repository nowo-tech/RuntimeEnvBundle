<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Service;

use InvalidArgumentException;

/**
 * Name-based write port of the runtime env variables (1.5, rules 1/3): no entity in the signatures.
 *
 * Implemented by {@see RuntimeEnvWriter} (autowire this interface). Each write dispatches a
 * `RuntimeEnvVariableChangedEvent` after the commit; with `database.transaction: host` the bundle does not flush and
 * your use case commits.
 */
interface RuntimeEnvWriterInterface
{
    /**
     * @throws InvalidArgumentException invalid or already used name
     */
    public function createVariable(string $name, string $value, ?string $description = null, bool $enabled = true): void;

    /**
     * @throws InvalidArgumentException unknown variable
     */
    public function updateByName(string $name, string $value, ?string $description, bool $enabled): void;

    /**
     * @throws InvalidArgumentException unknown variable, invalid or already used new name
     */
    public function renameByName(string $name, string $newName): void;

    /**
     * @throws InvalidArgumentException unknown variable
     */
    public function deleteByName(string $name): void;
}
