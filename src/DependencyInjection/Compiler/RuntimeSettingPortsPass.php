<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\DependencyInjection\Compiler;

use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvAdminSettings;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.6 (`runtime_setting.enabled`): binds the runtime-setting-contract ports to {@see RuntimeEnvSettingStore} only when
 * nothing else did (the host, NowoRuntimeSettingBundle's `source:` / `writer:`, another adapter), and gives the admin
 * controller the updater path when NowoRuntimeSettingBundle is registered. Runs before NowoRuntimeSettingBundle's
 * default-ports pass (priority 10), never in load().
 */
final class RuntimeSettingPortsPass implements CompilerPassInterface
{
    public const PRIORITY = 10;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_runtime_env.runtime_setting.enabled') || $container->getParameter('nowo_runtime_env.runtime_setting.enabled') !== true) {
            return;
        }

        foreach ([RuntimeValueSourceInterface::class, RuntimeSettingWriterInterface::class] as $port) {
            if (!$container->has($port)) {
                $container->setAlias($port, RuntimeEnvSettingStore::class);
            }
        }

        if (!$container->has(RuntimeSettingUpdater::class) || !$container->has(RuntimeSettingRegistry::class)) {
            return;
        }

        $container->setDefinition(RuntimeEnvAdminSettings::class, new Definition(RuntimeEnvAdminSettings::class, [
            new Reference(RuntimeSettingRegistry::class),
            new Reference(RuntimeSettingUpdater::class),
            new Reference(RuntimeEnvSettingStore::class),
        ]));
        if ($container->hasDefinition(RuntimeEnvManageController::class)) {
            $container->getDefinition(RuntimeEnvManageController::class)->setArgument('$settings', new Reference(RuntimeEnvAdminSettings::class));
        }
    }
}
