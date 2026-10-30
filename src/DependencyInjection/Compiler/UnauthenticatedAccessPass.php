<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\DependencyInjection\Compiler;

use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `security.allow_unauthenticated: true` is a dev / demo switch: with the manage UI enabled the container refuses to
 * compile unless `kernel.debug` is true, so a demo configuration copied to production cannot open the secrets UI.
 */
final class UnauthenticatedAccessPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // The extension removes the controller when the manage UI is disabled: nothing to open then.
        if (!$container->hasDefinition(RuntimeEnvManageController::class)) {
            return;
        }

        if (
            !$container->hasParameter('nowo_runtime_env.security.allow_unauthenticated')
            || $container->getParameter('nowo_runtime_env.security.allow_unauthenticated') !== true
        ) {
            return;
        }

        if ($container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug') === true) {
            return;
        }

        throw new InvalidConfigurationException('nowo_runtime_env.security.allow_unauthenticated: true is only allowed when kernel.debug is true (dev / demo); the manage UI would expose every runtime env variable without login. Set it to false and protect the UI with security.access_roles.');
    }
}
