<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\DependencyInjection;

use DateTimeZone;
use Exception;
use LogicException;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\RuntimeEnvBundle\Concurrency\RowVersionListener;
use Nowo\RuntimeEnvBundle\Concurrency\RuntimeEnvRowVersions;
use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use Nowo\RuntimeEnvBundle\Doctrine\KeyCollationListener;
use Nowo\RuntimeEnvBundle\Doctrine\RuntimeEnvMetadataListener;
use Nowo\RuntimeEnvBundle\EventSubscriber\RuntimeEnvAccessSubscriber;
use Nowo\RuntimeEnvBundle\NowoRuntimeEnvBundle;
use Nowo\RuntimeEnvBundle\Persistence\AfterCommitMiddleware;
use Nowo\RuntimeEnvBundle\Persistence\RuntimeEnvOutbox;
use Nowo\RuntimeEnvBundle\Persistence\RuntimeEnvPendingEventsInterface;
use Nowo\RuntimeEnvBundle\Persistence\RuntimeEnvTransaction;
use Nowo\RuntimeEnvBundle\Repository\DoctrineOrmRuntimeEnvVariableRepository;
use Nowo\RuntimeEnvBundle\Repository\PaginatedRuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Repository\RuntimeEnvVariableRepositoryInterface;
use Nowo\RuntimeEnvBundle\Routing\RuntimeEnvRouteLoader;
use Nowo\RuntimeEnvBundle\RuntimeSetting\DoctrineEncryptValueCipher;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\CacheScopeEnvVarProcessor;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\DbalQueryValueProvider;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\InvalidReadOnlyQuery;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\ReadOnlyQuery;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvSettingStore;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvValueCipherInterface;
use Nowo\RuntimeEnvBundle\RuntimeSetting\RuntimeEnvVersionBumper;
use Nowo\RuntimeEnvBundle\Security\AllowAllRuntimeEnvAccessChecker;
use Nowo\RuntimeEnvBundle\Security\ConfigurableRuntimeEnvAccessChecker;
use Nowo\RuntimeEnvBundle\Security\RuntimeEnvAccessCheckerInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvBag;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvReaderInterface;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriter;
use Nowo\RuntimeEnvBundle\Service\RuntimeEnvWriterInterface;
use Nowo\RuntimeEnvBundle\Transaction\StorageFailureListener;
use Nowo\RuntimeEnvBundle\Twig\RuntimeEnvTwigExtension;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPageFactory;
use Nowo\RuntimeEnvBundle\Ui\RuntimeEnvPages;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderBinding;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\Asset\Package;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_key_exists;
use function array_key_first;
use function array_values;
use function class_exists;
use function dirname;
use function hash;
use function is_array;
use function is_bool;
use function is_string;
use function ksort;
use function preg_match;
use function preg_match_all;
use function rtrim;
use function sprintf;
use function strlen;

use const SORT_STRING;

final class RuntimeEnvExtension extends Extension implements PrependExtensionInterface
{
    /** The commit ledger (1.8): real commits of the bundle's connection, counted by the outbox, read by the conflict answer. */
    public const COMMIT_LEDGER_ID = 'nowo_runtime_env.commit_ledger';

    /** 1.10: service id prefix of the DBAL query providers (`nowo_runtime_env.provider.<name>`). */
    public const PROVIDER_PREFIX = 'nowo_runtime_env.provider.';

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMappings($container);
        $this->prependUiKitDefaults($container);
        $this->prependFormKitProfile($container);
        $this->prependAssetPackage($container);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);
        $this->assertLiteralBooleans($config);

        // web_ui.enabled: false removes the HTML manage UI; the deprecated panel.enabled: false still does too.
        $config['panel']['enabled']  = (bool) $config['panel']['enabled'] && (bool) $config['web_ui']['enabled'];
        $config['web_ui']['enabled'] = $config['panel']['enabled'];
        // Canonical web_ui keys win; the deprecated panel.path_prefix / templates.* are the fallbacks (REQ-UI-003).
        $pathPrefix                          = $this->resolvePathPrefix($config);
        $config['panel']['path_prefix']      = $pathPrefix;
        $config['web_ui']['path_prefix']     = $pathPrefix;
        $config['web_ui']['layout_template'] = $this->resolveLayout($config);
        $config['web_ui']['templates']       = $this->resolveTemplates($config);
        $config['templates']                 = [
            'index'  => $config['web_ui']['templates']['index'] ?? RuntimeEnvPages::TEMPLATES['index'],
            'form'   => $config['web_ui']['templates']['form'] ?? RuntimeEnvPages::TEMPLATES['form'],
            'layout' => $config['web_ui']['layout_template'],
        ];

        if (
            (bool) $config['panel']['enabled']
            && !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('NowoRuntimeEnvBundle panel requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $prefix         = rtrim((string) $config['table_prefix'], '_');
        $variablesTable = $prefix . '_variables';
        $emName         = (string) $config['database']['entity_manager'];

        $container->setParameter('nowo_runtime_env.enabled', (bool) $config['enabled']);
        $container->setParameter('nowo_runtime_env.table_prefix', $prefix);
        $container->setParameter('nowo_runtime_env.variables_table', $variablesTable);
        $container->setParameter('nowo_runtime_env.database.entity_manager', $emName);
        $container->setParameter('nowo_runtime_env.panel.enabled', (bool) $config['panel']['enabled']);
        $container->setParameter('nowo_runtime_env.panel.path_prefix', $pathPrefix);
        $container->setParameter('nowo_runtime_env.web_ui.path_prefix', $pathPrefix);
        $container->setParameter('nowo_runtime_env.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_runtime_env.web_ui.templates', $config['web_ui']['templates']);
        $container->setParameter('nowo_runtime_env.web_ui.list_page_size', $config['web_ui']['list_page_size']);
        $container->setParameter('nowo_runtime_env.security', $config['security']);
        $container->setParameter('nowo_runtime_env.security.allow_unauthenticated', (bool) $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_runtime_env.web_ui', $config['web_ui']);
        $container->setParameter('nowo_runtime_env.templates', $config['templates']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setDefinition(DoctrineOrmRuntimeEnvVariableRepository::class, (new Definition(DoctrineOrmRuntimeEnvVariableRepository::class))
            ->setAutowired(false)
            ->setArgument('$entityManager', null)
            ->setArgument('$registry', new Reference('doctrine'))
            ->setArgument('$entityManagerName', $emName));
        $container->setAlias(RuntimeEnvVariableRepositoryInterface::class, DoctrineOrmRuntimeEnvVariableRepository::class);
        $container->setAlias(PaginatedRuntimeEnvVariableRepositoryInterface::class, DoctrineOrmRuntimeEnvVariableRepository::class);

        $runtimeSettings = (bool) $config['runtime_setting']['enabled'];
        $versionsTable   = $prefix . '_versions';
        $container->setParameter('nowo_runtime_env.runtime_setting.enabled', $runtimeSettings);
        $container->setParameter('nowo_runtime_env.versions_table', $versionsTable);

        $container->setDefinition(RuntimeEnvMetadataListener::class, (new Definition(RuntimeEnvMetadataListener::class))
            ->setAutowired(false)
            ->setArgument('$variablesTableName', $variablesTable)
            ->setArgument('$runtimeSettings', $runtimeSettings)
            ->setArgument('$versionsTableName', $versionsTable)
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));
        // 1.6 / 1.8: 409 + CONFLICT_001 / CONFLICT_002 (or 503) instead of 500 for storage failures on the bundle's routes.
        $container->setDefinition(self::COMMIT_LEDGER_ID, new Definition(CommitLedger::class));
        $policy = $config['conflict_response']['status'] === null && $config['conflict_response']['retry_after'] === null
            ? new Reference(ConflictResponsePolicy::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)
            : new Definition(ConflictResponsePolicy::class, [
                $config['conflict_response']['status'] ?? ConflictResponsePolicy::STATUS_CONFLICT,
                $config['conflict_response']['retry_after'] ?? ConflictResponsePolicy::DEFAULT_RETRY_AFTER,
            ]);
        $container->setDefinition(StorageFailureListener::class, (new Definition(StorageFailureListener::class, [$policy, new Reference(self::COMMIT_LEDGER_ID)]))
            ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => StorageFailureListener::REQUEST_PRIORITY])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'method' => 'onException', 'priority' => StorageFailureListener::PRIORITY]));

        // 1.9 (REQ-RUNTIME-003 rule 4): row versions of the manage page's edit form. Every ORM update of a variable
        // advances the version (postUpdate, inside the flush's transaction, on any manager that maps the entity).
        $container->setDefinition(RuntimeEnvRowVersions::class, new Definition(RuntimeEnvRowVersions::class, [
            '$registry'          => new Reference('doctrine'),
            '$entityManagerName' => $emName,
            '$variablesTable'    => $variablesTable,
        ]));
        $container->setDefinition(RowVersionListener::class, (new Definition(RowVersionListener::class))
            ->addTag('doctrine.event_listener', ['event' => 'postUpdate']));

        $container->setDefinition(RuntimeEnvBag::class, (new Definition(RuntimeEnvBag::class))
            ->setAutowired(false)
            ->setArgument('$repository', new Reference(RuntimeEnvVariableRepositoryInterface::class))
            ->setArgument('$enabled', (bool) $config['enabled'])
            ->setArgument('$requestStack', new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.reset', ['method' => 'reset']));

        // 1.5: unit of work boundary + write events (rules 6/7), on database.entity_manager.
        $container->setDefinition(RuntimeEnvTransaction::class, (new Definition(RuntimeEnvTransaction::class))
            ->setAutowired(false)
            ->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName)))
            ->setArgument('$eventDispatcher', new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$hostControlsTransaction', $config['database']['transaction'] === 'host')
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$requestStack', new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$outbox', new Reference(RuntimeEnvOutbox::class))
            ->addTag('kernel.event_subscriber')
            ->addTag('kernel.reset', ['method' => 'reset'])
            ->addTag('doctrine.event_listener', ['event' => 'postFlush']));
        $container->setAlias(RuntimeEnvPendingEventsInterface::class, RuntimeEnvTransaction::class);
        // LH-RE-M1: the real transactions of the bundle's connection (AfterCommitMiddlewarePass sets the connection).
        $container->setDefinition(RuntimeEnvOutbox::class, (new Definition(RuntimeEnvOutbox::class))
            ->setAutowired(false)
            ->setArgument('$ledger', new Reference(self::COMMIT_LEDGER_ID))
            ->addTag('kernel.reset', ['method' => 'reset']));
        $container->setDefinition(AfterCommitMiddleware::class, (new Definition(AfterCommitMiddleware::class))
            ->setAutowired(false)
            ->setArguments([new Reference(RuntimeEnvOutbox::class)])
            ->addTag('doctrine.middleware'));

        $container->setDefinition(RuntimeEnvWriter::class, (new Definition(RuntimeEnvWriter::class))
            ->setAutowired(false)
            ->setArgument('$repository', new Reference(RuntimeEnvVariableRepositoryInterface::class))
            ->setArgument('$bag', new Reference(RuntimeEnvBag::class))
            ->setArgument('$transaction', new Reference(RuntimeEnvTransaction::class))
            ->setArgument('$clock', new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE)));
        $container->setAlias(RuntimeEnvWriterInterface::class, RuntimeEnvWriter::class);

        if ($runtimeSettings) {
            $this->configureRuntimeSettings($container, $emName, $variablesTable, $versionsTable);
        }
        $container->setAlias(RuntimeEnvReaderInterface::class, RuntimeEnvBag::class);
        $this->configureProviders($container, $config['providers']);

        $this->registerAccessChecker($container, $config['security']);
        $this->configurePanel($container, $config);
        $this->configureTwig($container);
        $this->configureRouteLoader($container, $config);
    }

    /**
     * 1.6: nowo-tech/runtime-setting-contract source and writer on the variables table, the version bump of the 1.5
     * writer, and the cipher. The contract ports are bound by RuntimeSettingPortsPass only when nothing else is.
     */
    private function configureRuntimeSettings(ContainerBuilder $container, string $emName, string $variablesTable, string $versionsTable): void
    {
        // Exact (binary NO PAD) collation of the key columns on MySQL / MariaDB, in the generated schema. Only with the
        // feature on (audit QT-L-4, owner decision): with it off, migrations:diff stays empty.
        $container->setDefinition(KeyCollationListener::class, (new Definition(KeyCollationListener::class, [
            new Reference('doctrine'),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema']));

        $container->setDefinition(DoctrineEncryptValueCipher::class, new Definition(DoctrineEncryptValueCipher::class, [
            new Reference(EncryptUtil::UTIL_NAME),
        ]));
        $container->setAlias(RuntimeEnvValueCipherInterface::class, DoctrineEncryptValueCipher::class);

        $container->setDefinition(RuntimeEnvSettingStore::class, new Definition(RuntimeEnvSettingStore::class, [
            '$registry'          => new Reference('doctrine'),
            '$entityManagerName' => $emName,
            '$variablesTable'    => $variablesTable,
            '$versionsTable'     => $versionsTable,
            '$cipher'            => new Reference(RuntimeEnvValueCipherInterface::class),
            '$eventDispatcher'   => new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            '$logger'            => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            '$clock'             => new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            '$bag'               => new Reference(RuntimeEnvBag::class),
        ]));

        $container->setDefinition(RuntimeEnvVersionBumper::class, new Definition(RuntimeEnvVersionBumper::class, [
            '$registry'          => new Reference('doctrine'),
            '$entityManagerName' => $emName,
            '$versionsTable'     => $versionsTable,
        ]));
        $container->getDefinition(RuntimeEnvWriter::class)->setArgument('$versions', new Reference(RuntimeEnvVersionBumper::class));
    }

    /**
     * 1.10: one {@see DbalQueryValueProvider} per `providers.<name>`, tagged for nowo-tech/runtime-setting-contract's
     * provider registry. Everything is checked here, at container build: name, literal SQL (no %env()%), the read-only
     * query rules, columns, time zone. DbalQueryProvidersPass checks the connection exists.
     *
     * @param array<string, array{connection: string, sql: string, parameters: array<string, bool|float|int|string|null>, value_column: string, previous_column: ?string, previous_expires_column: ?string, expires_timezone: string, max_length: int, cache_ttl: int}> $providers
     */
    private function configureProviders(ContainerBuilder $container, array $providers): void
    {
        $connections = [];
        foreach ($providers as $name => $provider) {
            $name = (string) $name;
            if (!RuntimeValueProviderBinding::isValidName($name)) {
                throw new InvalidConfigurationException('nowo_runtime_env.providers: invalid provider name; use lowercase letters, digits and underscores, starting with a letter (at most 64 bytes).');
            }
            foreach (['connection', 'sql', 'value_column', 'expires_timezone'] as $option) {
                $value = $provider[$option];
                if (!is_string($value) || $container->resolveEnvPlaceholders($value) !== $value) {
                    throw new InvalidConfigurationException(sprintf('nowo_runtime_env.providers.%s.%s must be a literal string: the query is fixed when the container is built, never read from the environment.', $name, $option));
                }
            }
            foreach (['previous_column', 'previous_expires_column'] as $option) {
                $value = $provider[$option];
                if ($value !== null && (!is_string($value) || $container->resolveEnvPlaceholders($value) !== $value)) {
                    throw new InvalidConfigurationException(sprintf('nowo_runtime_env.providers.%s.%s must be a literal string or null.', $name, $option));
                }
            }
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $provider['connection']) !== 1) {
                throw new InvalidConfigurationException(sprintf('nowo_runtime_env.providers.%s.connection must be a DBAL connection name.', $name));
            }
            try {
                new DateTimeZone($provider['expires_timezone']);
                $query = new ReadOnlyQuery($name, $provider['sql'], $provider['parameters'], $provider['value_column'], $provider['previous_column'], $provider['previous_expires_column'], $provider['max_length']);
            } catch (InvalidReadOnlyQuery $e) {
                throw new InvalidConfigurationException($e->getMessage(), 0, $e);
            } catch (Exception) {
                throw new InvalidConfigurationException(sprintf('nowo_runtime_env.providers.%s.expires_timezone is not a known time zone.', $name));
            }

            $container->setDefinition(self::PROVIDER_PREFIX . $name, (new Definition(DbalQueryValueProvider::class, [
                new Reference(sprintf('doctrine.dbal.%s_connection', $provider['connection'])),
                new Definition(ReadOnlyQuery::class, [$name, $query->sql, $provider['parameters'], $query->valueColumn, $query->previousColumn, $query->previousExpiresColumn, $query->maxLength]),
                $provider['expires_timezone'],
            ]))->addTag(RuntimeSettingsConfiguration::PROVIDER_TAG, ['name' => $name, 'cache_ttl' => $provider['cache_ttl'], 'cache_scope' => $this->providerCacheScope($container, $name, $provider['connection'], $query->sql, $provider['parameters'])]));
            $connections[$name] = $provider['connection'];
        }
        $container->setParameter('nowo_runtime_env.provider_connections', $connections);
    }

    /**
     * 1.10 (audits ZDA-L2, ZDA-v): what a DBAL provider's answer depends on besides the setting id: the connection, the
     * query and the bound constants, each hashed so the scope has a fixed size. A constant's literal text is hashed
     * here; each `%env()%` inside it becomes `%env(nowo_runtime_env_scope:...)%`, the hash of its runtime value (see
     * {@see CacheScopeEnvVarProcessor}), so deployments with different values never share a cache entry. The contract
     * hashes the whole scope again into the cache token.
     *
     * @param array<string, bool|float|int|string|null> $parameters
     */
    private function providerCacheScope(ContainerBuilder $container, string $provider, string $connection, string $sql, array $parameters): string
    {
        $container->setDefinition(CacheScopeEnvVarProcessor::class, (new Definition(CacheScopeEnvVarProcessor::class))
            ->addTag('container.env_var_processor'));

        ksort($parameters, SORT_STRING);
        $scope  = 'dbal:' . $connection . '|' . hash('sha256', $sql);
        $length = strlen($scope);
        foreach ($parameters as $name => $value) {
            $text = match (true) {
                $value === null => '',
                is_bool($value) => $value ? '1' : '0',
                default         => (string) $value,
            };
            // '%env(NAME)%' for every placeholder (and the literal text around them).
            $template = is_string($value) ? (string) $container->resolveEnvPlaceholders($text) : $text;
            preg_match_all('/%env\(([^()%]+)\)%/', $template, $envs);
            $part = '|' . $name . '=' . CacheScopeEnvVarProcessor::hash($template);
            $length += strlen($part);
            foreach ($envs[1] as $env) {
                $placeholder = $container->getParameterBag()->get('env(' . CacheScopeEnvVarProcessor::PREFIX . ':' . $env . ')');
                $part .= ':' . (is_string($placeholder) ? $placeholder : '');
                $length += 1 + CacheScopeEnvVarProcessor::HASH_LENGTH;
            }
            $scope .= $part;
        }

        // The runtime length is known now (every hashed part has a fixed size): never a scope the contract refuses.
        if ($length > RuntimeValueProviderBinding::MAX_CACHE_SCOPE_LENGTH) {
            throw new InvalidConfigurationException(sprintf('nowo_runtime_env.providers.%s: too many parameters for the cache scope (%d bytes, at most %d).', $provider, $length, RuntimeValueProviderBinding::MAX_CACHE_SCOPE_LENGTH));
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $security
     */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        $customId = $security['access_checker'] ?? null;
        if (is_string($customId) && $customId !== '') {
            $container->setAlias(RuntimeEnvAccessCheckerInterface::class, $customId);

            return;
        }

        if (($security['allow_unauthenticated'] ?? false) === true) {
            $container->setDefinition(AllowAllRuntimeEnvAccessChecker::class, new Definition(AllowAllRuntimeEnvAccessChecker::class));
            $container->setAlias(RuntimeEnvAccessCheckerInterface::class, AllowAllRuntimeEnvAccessChecker::class);

            return;
        }

        /** @var list<string> $roles */
        $roles = array_values(array_filter(
            is_array($security['access_roles'] ?? null) ? $security['access_roles'] : [],
            static fn (mixed $r): bool => is_string($r) && $r !== '',
        ));

        $container->setDefinition(ConfigurableRuntimeEnvAccessChecker::class, (new Definition(ConfigurableRuntimeEnvAccessChecker::class))
            ->setAutowired(false)
            ->setArgument('$authorizationChecker', new Reference('security.authorization_checker'))
            ->setArgument('$accessRoles', $roles));
        $container->setAlias(RuntimeEnvAccessCheckerInterface::class, ConfigurableRuntimeEnvAccessChecker::class);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configurePanel(ContainerBuilder $container, array $config): void
    {
        if (!(bool) $config['panel']['enabled']) {
            $container->removeDefinition(RuntimeEnvManageController::class);
            $container->removeDefinition(RuntimeEnvAccessSubscriber::class);

            return;
        }

        $container->setDefinition(RuntimeEnvPages::class, (new Definition(RuntimeEnvPages::class))
            ->setAutowired(false)
            ->setArgument('$layoutTemplate', $config['web_ui']['layout_template'])
            ->setArgument('$templates', $config['web_ui']['templates'])
            ->setArgument('$twig', new Reference('twig', ContainerInterface::NULL_ON_INVALID_REFERENCE)));

        $container->setDefinition(RuntimeEnvPageFactory::class, (new Definition(RuntimeEnvPageFactory::class))
            ->setAutowired(false)
            ->setArgument('$urlGenerator', new Reference('router'))
            ->setArgument('$pages', new Reference(RuntimeEnvPages::class)));

        $container->getDefinition(RuntimeEnvManageController::class)
            // The routes come from RuntimeEnvRouteLoader (configurable prefix): no `routing.controller` tag from the
            // #[Route] autoconfiguration, so a host `routing.controllers` import never mounts them without the prefix.
            // Tags autoconfiguration would add: controller arguments + the AbstractController service locator.
            ->setAutoconfigured(false)
            ->addTag('container.service_subscriber')
            ->setArgument('$repository', new Reference(RuntimeEnvVariableRepositoryInterface::class))
            ->setArgument('$writer', new Reference(RuntimeEnvWriter::class))
            ->setArgument('$pathPrefix', $config['web_ui']['path_prefix'])
            ->setArgument('$listPageSize', $config['web_ui']['list_page_size'])
            ->setArgument('$pageFactory', new Reference(RuntimeEnvPageFactory::class))
            ->setArgument('$translator', new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$rowVersions', new Reference(RuntimeEnvRowVersions::class));

        $container->getDefinition(RuntimeEnvAccessSubscriber::class)
            ->setArgument('$accessChecker', new Reference(RuntimeEnvAccessCheckerInterface::class))
            ->setArgument('$pathPrefix', $config['web_ui']['path_prefix'])
            ->setArgument('$allowUnauthenticated', (bool) $config['security']['allow_unauthenticated']);
    }

    private function configureTwig(ContainerBuilder $container): void
    {
        $container->setDefinition(RuntimeEnvTwigExtension::class, (new Definition(RuntimeEnvTwigExtension::class))
            ->setAutowired(false)
            ->setArgument('$bag', new Reference(RuntimeEnvBag::class))
            ->addTag('twig.extension'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureRouteLoader(ContainerBuilder $container, array $config): void
    {
        $container->setDefinition(RuntimeEnvRouteLoader::class, (new Definition(RuntimeEnvRouteLoader::class))
            ->setAutowired(false)
            ->setArgument('$panelEnabled', (bool) $config['panel']['enabled'])
            ->setArgument('$pathPrefix', (string) $config['web_ui']['path_prefix'])
            ->addTag('routing.loader'));
    }

    /**
     * REQ-ASSETS-004: the published stylesheet is addressed through the named asset package `nowo_runtime_env`
     * (`/bundles/noworuntimeenv`, the `assets:install` target), never through a hard-coded public path.
     *
     * Skipped without symfony/asset (the `assets` node would not exist). The package is local: the host's default
     * `framework.assets.base_urls` (CDN) are not inherited by it.
     */
    private function prependAssetPackage(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('framework') || !class_exists(Package::class)) {
            return;
        }

        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    Configuration::ALIAS => [
                        'base_path' => '/bundles/noworuntimeenv',
                    ],
                ],
            ],
        ]);
    }

    private function prependDoctrineMappings(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $mappings = [
            'mappings' => [
                'RuntimeEnvBundle' => [
                    'type'      => 'attribute',
                    'dir'       => dirname(__DIR__) . '/Entity',
                    'prefix'    => 'Nowo\RuntimeEnvBundle\Entity',
                    'alias'     => 'RuntimeEnvBundle',
                    'is_bundle' => false,
                ],
            ],
        ];
        // 1.6: the version table is mapped only when runtime_setting.enabled (no migration otherwise).
        if ($this->configuredRuntimeSettings($container)) {
            $mappings['mappings']['RuntimeEnvBundleRuntimeSetting'] = [
                'type'      => 'attribute',
                'dir'       => dirname(__DIR__) . '/RuntimeSetting/Entity',
                'prefix'    => 'Nowo\RuntimeEnvBundle\RuntimeSetting\Entity',
                'alias'     => 'RuntimeEnvBundleRuntimeSetting',
                'is_bundle' => false,
            ];
        }

        // 1.5: on database.entity_manager when the host declares it under entity_managers (and pin the host's own
        // default so the prepended entry never becomes the default manager); else the default manager as before.
        // RuntimeEnvEntityManagerPass keeps the mapping on that manager only and fails on an undeclared one.
        $name        = $this->configuredEntityManager($container);
        $hostDefault = $this->hostDefaultEntityManager($container, $name);
        if ($hostDefault !== null) {
            $container->prependExtensionConfig('doctrine', ['orm' => [
                'default_entity_manager' => $hostDefault,
                'entity_managers'        => [$name => $mappings],
            ]]);

            return;
        }

        $container->prependExtensionConfig('doctrine', ['orm' => $mappings]);
    }

    /**
     * `runtime_setting.enabled` from the raw configs (prepend runs before the configuration is processed); the last
     * literal true or false wins.
     */
    private function configuredRuntimeSettings(ContainerBuilder $container): bool
    {
        $enabled = false;
        foreach ($container->getExtensionConfig($this->getAlias()) as $config) {
            $value = $config['runtime_setting']['enabled'] ?? null;
            if (is_bool($value)) {
                $enabled = $value;
            }
        }

        return $enabled;
    }

    /**
     * `database.entity_manager` from the raw configs (prepend runs before the configuration is processed).
     */
    private function configuredEntityManager(ContainerBuilder $container): string
    {
        $name = 'default';
        foreach ($container->getExtensionConfig($this->getAlias()) as $config) {
            $value = $config['database']['entity_manager'] ?? null;
            if (is_string($value) && $value !== '') {
                $name = $value;
            }
        }

        return $name;
    }

    /**
     * The host's default entity manager (its `default_entity_manager`, else the first declared one) when the host
     * declares `$name` under `doctrine.orm.entity_managers`; null otherwise.
     */
    private function hostDefaultEntityManager(ContainerBuilder $container, string $name): ?string
    {
        $declared = false;
        $default  = null;
        $first    = null;
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $orm = is_array($config['orm'] ?? null) ? $config['orm'] : [];
            if (is_string($orm['default_entity_manager'] ?? null)) {
                $default = $orm['default_entity_manager'];
            }
            $managers = $orm['entity_managers'] ?? null;
            if (is_array($managers) && $managers !== []) {
                $first ??= (string) array_key_first($managers);
                $declared = $declared || isset($managers[$name]);
            }
        }

        return $declared ? ($default ?? $first) : null;
    }

    /**
     * Default FormKit profile of the bundle forms: translation domain, no auto help / placeholder convention keys.
     * REQ-UI-003: no framework classes here — the UiKit form scaffold theme paints the fields through its slots.
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => NowoRuntimeEnvBundle::FORM_KIT_PROFILE,
            'translation_domain' => NowoRuntimeEnvBundle::TRANSLATION_DOMAIN,
            'auto_help'          => false,
            'auto_placeholder'   => false,
            'defaults'           => [
                'attr'     => [],
                'row_attr' => [],
            ],
            'field_types' => [],
        ];
    }

    /**
     * Registers the FormKit profile {@see NowoRuntimeEnvBundle::FORM_KIT_PROFILE} used by every bundle form type
     * (#[FormKitConfig]). A host overrides it by defining `nowo_form_kit.profiles.runtime_env` itself (nothing is
     * prepended then). Does not change the host `default_profile`.
     */
    private function prependFormKitProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        foreach ($container->getExtensionConfig('nowo_form_kit') as $config) {
            $profiles = $config['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(NowoRuntimeEnvBundle::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [NowoRuntimeEnvBundle::FORM_KIT_PROFILE => self::formKitProfile()],
        ]);
    }

    /**
     * When UiKit is installed and the host set the deprecated web_ui.css_framework / web_ui.icon_set (REQ-UI-003: both
     * are inherited from nowo_ui_kit), copy them to nowo_ui_kit unless the host set those keys there.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        $config   = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $webUi    = $config['web_ui'];
        $defaults = [];

        if (!$hostHasCssFramework && is_string($webUi['css_framework'])) {
            $defaults['css_framework'] = $webUi['css_framework'] === 'bootstrap' ? 'bootstrap5' : $webUi['css_framework'];
        }
        if (!$hostHasIconSet && is_string($webUi['icon_set'])) {
            $defaults['icon_set'] = $webUi['icon_set'];
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }

    /**
     * Effective manage URL prefix: web_ui.path_prefix, else the deprecated panel.path_prefix, else the default.
     *
     * @param array<string, mixed> $config
     */
    private function resolvePathPrefix(array $config): string
    {
        foreach ([$config['web_ui']['path_prefix'] ?? null, $config['panel']['path_prefix'] ?? null] as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                return $prefix;
            }
        }

        return Configuration::DEFAULT_PATH_PREFIX;
    }

    /**
     * Effective layout override: web_ui.layout_template, else the deprecated templates.layout; null (UiKit
     * nowo_ui_kit.layouts.admin) when neither is set or either names the removed pre-1.3 bundle layout.
     *
     * @param array<string, mixed> $config
     */
    private function resolveLayout(array $config): ?string
    {
        foreach ([$config['web_ui']['layout_template'] ?? null, $config['templates']['layout'] ?? null] as $layout) {
            if (is_string($layout) && $layout !== '' && $layout !== Configuration::LEGACY_DEFAULT_LAYOUT) {
                return $layout;
            }
        }

        return null;
    }

    /**
     * Per-page template overrides: web_ui.templates.<page>, else the deprecated templates.<page> when it differs from
     * the bundle template.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, string>
     */
    private function resolveTemplates(array $config): array
    {
        /** @var array<string, string> $templates */
        $templates = $config['web_ui']['templates'];
        foreach (RuntimeEnvPages::TEMPLATES as $page => $default) {
            $legacy = $config['templates'][$page] ?? null;
            if (!isset($templates[$page]) && is_string($legacy) && $legacy !== '' && $legacy !== $default) {
                $templates[$page] = $legacy;
            }
        }

        return $templates;
    }

    /**
     * The security switches must be literal booleans: an `%env(bool:…)%` placeholder is a non-empty string while the
     * container is built, so it would read as true (fail open) whatever the runtime value is.
     *
     * @param array<string, mixed> $config
     */
    private function assertLiteralBooleans(array $config): void
    {
        $switches = [
            'enabled'                        => $config['enabled'] ?? null,
            'panel.enabled'                  => $config['panel']['enabled'] ?? null,
            'web_ui.enabled'                 => $config['web_ui']['enabled'] ?? null,
            'security.allow_unauthenticated' => $config['security']['allow_unauthenticated'] ?? null,
            'runtime_setting.enabled'        => $config['runtime_setting']['enabled'] ?? null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_runtime_env.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for security switches.', $path));
            }
        }
    }

    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        return $container->hasExtension('security');
    }
}
