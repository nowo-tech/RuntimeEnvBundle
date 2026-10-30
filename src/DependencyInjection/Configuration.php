<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\DependencyInjection;

use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPages;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_keys;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function trim;

/**
 * Bundle configuration under alias {@see self::ALIAS}.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_runtime_env';

    /** Pre-1.3 default layout (removed in 1.3; mapped to null = nowo_ui_kit.layouts.admin). */
    public const LEGACY_DEFAULT_LAYOUT = '@NowoRuntimeEnvBundle/manage/layout.html.twig';

    /** @deprecated since 1.3, the bundle layout was removed (pages extend the UiKit scaffolds); kept for BC */
    public const DEFAULT_LAYOUT = self::LEGACY_DEFAULT_LAYOUT;

    /** Default URL prefix of the manage routes. */
    public const DEFAULT_PATH_PREFIX = '/_runtime_env';

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
                    ->info('Deprecated since 1.3: use web_ui.enabled and web_ui.path_prefix (REQ-UI-003).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Deprecated since 1.3: use web_ui.enabled. false still removes the manage UI.')
                            ->defaultTrue()
                            ->setDeprecated('nowo-tech/runtime-env-bundle', '1.3', 'The "%path%" option is deprecated, use "nowo_runtime_env.web_ui.enabled" instead (REQ-UI-003).')
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('Deprecated since 1.3: use web_ui.path_prefix (used when web_ui.path_prefix is not set).')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/runtime-env-bundle', '1.3', 'The "%path%" option is deprecated, use "nowo_runtime_env.web_ui.path_prefix" instead (REQ-UI-003).')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || trim($v) === ''))
                                ->thenInvalid('nowo_runtime_env.panel.path_prefix must be a non-empty path.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->info('Manage UI (REQ-UI-001 / REQ-UI-003): pages extend the UiKit list / form scaffolds.')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static function (array $v): array {
                            // The pre-1.3 default layout was removed (REQ-UI-003): null = nowo_ui_kit.layouts.admin.
                            if (($v['layout_template'] ?? null) === self::LEGACY_DEFAULT_LAYOUT) {
                                $v['layout_template'] = null;
                            }

                            return $v;
                        })
                    ->end()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('false = no HTML manage UI (no routes, controller removed).')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('URL prefix of the manage routes. Null (default) = panel.path_prefix when set (deprecated), else ' . self::DEFAULT_PATH_PREFIX . '.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || trim($v) === ''))
                                ->thenInvalid('nowo_runtime_env.web_ui.path_prefix must be a non-empty path.')
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Layout the manage pages extend (REQ-UI-003). Null (default) = nowo_ui_kit.layouts.admin. A pre-1.3 layout without the UiKit canonical blocks is wrapped in a deprecated bridge.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('templates')
                            ->info('Per-page template overrides (page id => template): index, form. Extend @!NowoRuntimeEnvBundle/manage/... and override a data block.')
                            ->useAttributeAsKey('page')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), array_keys(RuntimeEnvPages::TEMPLATES)) !== [])
                                ->then(static function (array $v): never {
                                    throw new InvalidConfigurationException(sprintf('Unknown nowo_runtime_env.web_ui.templates page(s) "%s"; known: %s.', implode('", "', array_diff(array_keys($v), array_keys(RuntimeEnvPages::TEMPLATES))), implode(', ', array_keys(RuntimeEnvPages::TEMPLATES))));
                                })
                            ->end()
                        ->end()
                        ->integerNode('list_page_size')
                            ->info('Rows per page of the manage list (REQ-PERF-001).')
                            ->defaultValue(50)
                            ->min(1)
                            ->max(500)
                        ->end()
                        ->enumNode('css_framework')
                            ->values(['bootstrap', 'bootstrap4', 'bootstrap5', 'tailwind', 'foundation', 'custom', 'tabler', 'none'])
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/runtime-env-bundle', '1.3', 'The "%path%" option is deprecated, set "nowo_ui_kit.css_framework" instead (REQ-UI-003).')
                            ->info('Deprecated since 1.3: the CSS stack is inherited from nowo_ui_kit.css_framework (copied there when the host did not set it).')
                        ->end()
                        ->enumNode('icon_set')
                            ->values(['bootstrap-icons', 'tabler-icons', 'ux_icon', 'svg_inline', 'none'])
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/runtime-env-bundle', '1.3', 'The "%path%" option is deprecated, set "nowo_ui_kit.icon_set" instead (REQ-UI-003).')
                            ->info('Deprecated since 1.3: the icon set is inherited from nowo_ui_kit.icon_set (copied there when the host did not set it).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->info('REQ-UI-002 roles + optional access checker.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->info('Symfony roles granted access. At least one non-empty role is required unless allow_unauthenticated or access_checker is set.')
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
                    ->validate()
                        ->ifTrue(static function (array $v): bool {
                            if (!empty($v['allow_unauthenticated'])) {
                                return false;
                            }
                            $checker = $v['access_checker'] ?? null;
                            if (is_string($checker) && $checker !== '') {
                                return false;
                            }
                            $roles = array_values(array_filter(
                                is_array($v['access_roles'] ?? null) ? $v['access_roles'] : [],
                                static fn (mixed $r): bool => is_string($r) && $r !== '',
                            ));

                            return $roles === [];
                        })
                        ->thenInvalid('nowo_runtime_env.security.access_roles must contain at least one non-empty role when allow_unauthenticated is false and no access_checker is set.')
                    ->end()
                ->end()
                ->arrayNode('templates')
                    ->info('Deprecated since 1.3: use web_ui.templates.<page> and web_ui.layout_template.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('index')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/runtime-env-bundle', '1.3', 'The "%path%" option is deprecated, use "nowo_runtime_env.web_ui.templates.index" instead (REQ-UI-003).')
                        ->end()
                        ->scalarNode('form')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/runtime-env-bundle', '1.3', 'The "%path%" option is deprecated, use "nowo_runtime_env.web_ui.templates.form" instead (REQ-UI-003).')
                        ->end()
                        ->scalarNode('layout')
                            ->info('Deprecated: use web_ui.layout_template (used when web_ui.layout_template is not set).')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/runtime-env-bundle', '1.1', 'The "%node%" option at path "%path%" is deprecated, use "nowo_runtime_env.web_ui.layout_template" instead.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
