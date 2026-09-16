<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Bundle configuration under alias {@see self::ALIAS}.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_runtime_env';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->info('Nowo Runtime Env Bundle — database-backed encrypted env variables with admin CRUD.')
            ->children()
                ->booleanNode('enabled')
                    ->info('Master switch for the runtime env bag and manage UI.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('table_prefix')
                    ->info('Doctrine table prefix (REQ-ORM-002). Final table: {prefix}_variables.')
                    ->defaultValue('runtime_env')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('database')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('entity_manager')
                            ->info('Doctrine ORM entity manager name.')
                            ->defaultValue('default')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('panel')
                    ->info('Admin CRUD panel.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('URL prefix for manage routes.')
                            ->defaultValue('/_runtime_env')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->info('REQ-UI-001 look-and-feel.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('layout_template')
                            ->defaultValue('@NowoRuntimeEnvBundle/manage/layout.html.twig')
                            ->cannotBeEmpty()
                        ->end()
                        ->enumNode('css_framework')
                            ->values(['bootstrap', 'bootstrap4', 'bootstrap5', 'tailwind', 'foundation', 'custom', 'tabler', 'none'])
                            ->defaultValue('custom')
                        ->end()
                        ->enumNode('icon_set')
                            ->values(['bootstrap-icons', 'tabler-icons', 'ux_icon', 'svg_inline', 'none'])
                            ->defaultValue('none')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->info('REQ-UI-002 roles + optional access checker.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->info('Symfony roles granted access (at least one). Empty = no bundle-level role check.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')
                            ->info('Optional service id implementing RuntimeEnvAccessCheckerInterface. null = role-based default.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->info('DEV/DEMO only. Never true in production.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('templates')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('index')
                            ->defaultValue('@NowoRuntimeEnvBundle/manage/index.html.twig')
                        ->end()
                        ->scalarNode('form')
                            ->defaultValue('@NowoRuntimeEnvBundle/manage/form.html.twig')
                        ->end()
                        ->scalarNode('layout')
                            ->defaultValue('@NowoRuntimeEnvBundle/manage/layout.html.twig')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
