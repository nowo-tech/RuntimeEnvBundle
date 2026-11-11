<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\DependencyInjection\Compiler;

use Nowo\RuntimeEnvBundle\Persistence\OwnEntityManagerSubscriber;
use Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function implode;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Makes `database.entity_manager` real (1.5, rule 6).
 *
 * - check-only instance (before the mapping passes): fails the build when the manager is not configured;
 * - main instance (after them and DoctrineBundle's `auto_mapping`): removes the bundle entity from the mapping driver
 *   chain of every other manager, fails when the configured one does not map it, and, when that manager is not the
 *   host's default one, marks it as owned by the bundle (reset when closed, cleared at every boundary).
 */
final class RuntimeEnvEntityManagerPass implements CompilerPassInterface
{
    public const ENTITY_NAMESPACE = 'Nowo\\RuntimeEnvBundle\\Entity';

    public const PARAMETER = 'nowo_runtime_env.database.entity_manager';

    /**
     * @param bool $checkOnly only validate the manager name (registered before the mapping passes)
     */
    public function __construct(
        private readonly bool $checkOnly = false,
    ) {
    }

    /**
     * Validates, scopes the mapping and wires the ownership of the manager.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER) || !$container->hasParameter('doctrine.entity_managers')) {
            return;
        }

        $name     = (string) $container->getParameter(self::PARAMETER); // @phpstan-ignore cast.string
        $managers = (array) $container->getParameter('doctrine.entity_managers');

        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_runtime_env.database.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or change the option.', $name, implode('", "', array_keys($managers))));
        }

        if ($this->checkOnly) {
            return;
        }

        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                $isBundleDriver = $call[0] === 'addDriver' && is_string($call[1][1] ?? null) && str_starts_with($call[1][1], self::ENTITY_NAMESPACE);
                if ($isBundleDriver && $manager !== $name) {
                    continue;
                }

                $maps    = $maps || $isBundleDriver;
                $calls[] = $call;
            }

            $chain->setMethodCalls($calls);

            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_runtime_env.database.entity_manager) does not map %s.', $name, self::ENTITY_NAMESPACE));
            }
        }

        $owned = $container->hasParameter('doctrine.default_entity_manager') && $container->getParameter('doctrine.default_entity_manager') !== $name;
        if ($container->hasDefinition(DoctrineOrmRuntimeEnvVariableRepository::class)) {
            $container->getDefinition(DoctrineOrmRuntimeEnvVariableRepository::class)->setArgument('$ownsManager', $owned);
        }

        if ($owned) {
            $container->setDefinition(OwnEntityManagerSubscriber::class, new Definition(OwnEntityManagerSubscriber::class, [new Reference('doctrine'), $name]))
                ->addTag('kernel.event_subscriber');
        }
    }
}
