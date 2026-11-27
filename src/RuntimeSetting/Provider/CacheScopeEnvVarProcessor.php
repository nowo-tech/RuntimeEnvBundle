<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\RuntimeSetting\Provider;

use Closure;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

use function hash;
use function is_bool;
use function is_scalar;
use function substr;

/**
 * 1.10 (audit ZDA-v): `%env(nowo_runtime_env_scope:NAME)%` is the first 16 hex characters of the SHA-256 of the
 * environment value. A DBAL query provider's cache scope uses it for `%env()%` constants, so the scope separates
 * deployments with different values while keeping a fixed length, whatever the size of the value. Stateless.
 */
final readonly class CacheScopeEnvVarProcessor implements EnvVarProcessorInterface
{
    public const PREFIX = 'nowo_runtime_env_scope';

    /** Length of each hashed part of a cache scope. */
    public const HASH_LENGTH = 16;

    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        return self::hash($getEnv($name));
    }

    public static function hash(mixed $value): string
    {
        $text = match (true) {
            is_bool($value)   => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default           => '',
        };

        return substr(hash('sha256', $text), 0, self::HASH_LENGTH);
    }

    public static function getProvidedTypes(): array
    {
        return [self::PREFIX => 'string'];
    }
}
