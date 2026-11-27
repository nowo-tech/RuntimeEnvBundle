<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting\Provider;

use InvalidArgumentException;

/**
 * 1.10: a DBAL query provider's configuration is refused at container build (never at runtime). Messages name the
 * provider and the rule, never the SQL.
 */
final class InvalidReadOnlyQuery extends InvalidArgumentException
{
    public static function because(string $provider, string $reason): self
    {
        return new self('nowo_runtime_env.providers.' . $provider . ': ' . $reason);
    }
}
