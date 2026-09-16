<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Twig;

use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers: {@code runtime_env('KEY')} and {@code runtime_env_has('KEY')}.
 */
final class RuntimeEnvTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RuntimeEnvBag $bag,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('runtime_env', $this->get(...)),
            new TwigFunction('runtime_env_has', $this->has(...)),
        ];
    }

    public function get(string $name, ?string $default = null): ?string
    {
        return $this->bag->get($name, $default);
    }

    public function has(string $name): bool
    {
        return $this->bag->has($name);
    }
}
