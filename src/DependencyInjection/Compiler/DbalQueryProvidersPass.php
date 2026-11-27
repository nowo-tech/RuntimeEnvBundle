<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
use function is_string;
use function sprintf;

/**
 * 1.10: every `nowo_runtime_env.providers.<name>.connection` must be a declared DBAL connection; the build fails
 * otherwise (never a "connection not found" at the first read).
 */
final class DbalQueryProvidersPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $connections = $container->hasParameter('nowo_runtime_env.provider_connections') ? $container->getParameter('nowo_runtime_env.provider_connections') : [];
        foreach (is_array($connections) ? $connections : [] as $provider => $connection) {
            if (!is_string($connection) || !$container->has(sprintf('doctrine.dbal.%s_connection', $connection))) {
                throw new InvalidConfigurationException(sprintf('nowo_runtime_env.providers.%s.connection: DBAL connection "%s" is not declared (doctrine.dbal.connections).', (string) $provider, is_string($connection) ? $connection : '?'));
            }
        }
    }
}
