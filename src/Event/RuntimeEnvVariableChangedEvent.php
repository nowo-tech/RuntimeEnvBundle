<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Event;

use DateTimeImmutable;

/**
 * A runtime env variable was created, updated, renamed or deleted (1.5, rules 4/7).
 *
 * Carries the variable NAME and scalars only, never the (secret) value nor the entity. Dispatched after the write is
 * committed; with `database.transaction: host` after the host's own commit.
 */
final readonly class RuntimeEnvVariableChangedEvent
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const RENAMED = 'renamed';

    public const DELETED = 'deleted';

    /**
     * @param string $name variable name (after a rename: the new name)
     * @param string $action one of the class constants
     * @param DateTimeImmutable $at when the write happened
     * @param string|null $previousName the old name of a renamed variable
     */
    public function __construct(
        public string $name,
        public string $action,
        public DateTimeImmutable $at,
        public ?string $previousName = null,
    ) {
    }
}
