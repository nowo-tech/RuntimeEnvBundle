<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\DependencyInjection;

use Nowo\RuntimeEnvBundle\DependencyInjection\Compiler\DbalQueryProvidersPass;
use Nowo\RuntimeEnvBundle\DependencyInjection\RuntimeEnvExtension;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\CacheScopeEnvVarProcessor;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\DbalQueryValueProvider;
use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\ReadOnlyQuery;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_walk_recursive;
use function hash;
use function implode;
use function is_string;
use function sprintf;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * 1.10: `nowo_runtime_env.providers` (DBAL query providers for nowo-tech/runtime-setting-contract 1.1).
 */
final class RuntimeEnvExtensionProvidersTest extends TestCase
{
    private const SQL = 'SELECT value, prev, prev_until FROM tenant_setting WHERE tenant = :tenant AND name = :key AND active = :active AND (:none IS NULL) AND weight > :min AND weight > 0';

    public function testRegistersATaggedProviderPerEntry(): void
    {
        $container = $this->load([
            'tenant_settings' => [
                'connection'              => 'tenants',
                'sql'                     => self::SQL,
                'parameters'              => ['tenant' => 'ENV:TENANT_CODE', 'active' => true, 'none' => null, 'min' => 2],
                'previous_column'         => 'prev',
                'previous_expires_column' => 'prev_until',
                'expires_timezone'        => 'Europe/Madrid',
                'max_length'              => 2048,
                'cache_ttl'               => 60,
            ],
        ]);

        $definition = $container->getDefinition(RuntimeEnvExtension::PROVIDER_PREFIX . 'tenant_settings');
        self::assertSame(DbalQueryValueProvider::class, $definition->getClass());
        $tag = $definition->getTag(RuntimeSettingsConfiguration::PROVIDER_TAG);
        self::assertSame('tenant_settings', $tag[0]['name']);
        self::assertSame(60, $tag[0]['cache_ttl']);
        // ZDA-L2 / ZDA-v: connection, query hash and hashed constants (an env value hashed at runtime), sorted by name.
        $h = static fn (string $text): string => CacheScopeEnvVarProcessor::hash($text);
        self::assertSame(
            'dbal:tenants|' . hash('sha256', self::SQL) . '|active=' . $h('1') . '|min=' . $h('2') . '|none=' . $h('') . '|tenant=' . $h('%env(TENANT_CODE)%') . ':%env(nowo_runtime_env_scope:TENANT_CODE)%',
            $container->resolveEnvPlaceholders($tag[0]['cache_scope']),
        );
        self::assertTrue($container->getDefinition(CacheScopeEnvVarProcessor::class)->hasTag('container.env_var_processor'));
        self::assertEquals(new Reference('doctrine.dbal.tenants_connection'), $definition->getArgument(0));
        self::assertSame('Europe/Madrid', $definition->getArgument(2));

        $query = $definition->getArgument(1);
        self::assertInstanceOf(Definition::class, $query);
        self::assertSame(ReadOnlyQuery::class, $query->getClass());
        self::assertSame(['tenant_settings', self::SQL], [$query->getArgument(0), $query->getArgument(1)]);
        self::assertSame('%env(TENANT_CODE)%', $container->resolveEnvPlaceholders($query->getArgument(2)['tenant']));
        self::assertSame(['value', 'prev', 'prev_until', 2048], [$query->getArgument(3), $query->getArgument(4), $query->getArgument(5), $query->getArgument(6)]);
        self::assertSame(['tenant_settings' => 'tenants'], $container->getParameter('nowo_runtime_env.provider_connections'));
    }

    public function testAScopeThatWouldBeTooLongFailsTheBuild(): void
    {
        $parameters = [];
        $conditions = [];
        for ($i = 0; $i < 50; ++$i) {
            $name              = sprintf('p%02d', $i) . str_repeat('x', 60);
            $parameters[$name] = str_repeat('v', 10000);
            $conditions[]      = 'c = :' . $name;
        }

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_runtime_env.providers.wide: too many parameters for the cache scope');

        $this->load(['wide' => ['connection' => 'default', 'sql' => 'SELECT value FROM t WHERE ' . implode(' AND ', $conditions), 'parameters' => $parameters]]);
    }

    public function testHugeConstantsKeepTheScopeShort(): void
    {
        $container = $this->load(['p' => ['connection' => 'default', 'sql' => 'SELECT value FROM t WHERE name = :key AND x = :big', 'parameters' => ['big' => str_repeat('v', 100000)]]]);

        $scope = $container->getDefinition(RuntimeEnvExtension::PROVIDER_PREFIX . 'p')->getTag(RuntimeSettingsConfiguration::PROVIDER_TAG)[0]['cache_scope'];
        self::assertIsString($scope);
        self::assertLessThan(120, strlen($scope));
    }

    public function testNoProvidersByDefault(): void
    {
        $container = $this->load([]);

        self::assertSame([], $container->getParameter('nowo_runtime_env.provider_connections'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedEntries(): iterable
    {
        $ok = ['connection' => 'default', 'sql' => 'SELECT value FROM t WHERE name = :key'];

        yield 'invalid name' => [['Tenant-Settings' => $ok], 'invalid provider name'];
        yield 'sql from env' => [['p' => ['sql' => 'ENV:PROVIDER_SQL'] + $ok], 'p.sql must be a literal string'];
        yield 'connection from env' => [['p' => ['connection' => 'ENV:CONN'] + $ok], 'p.connection must be a literal string'];
        yield 'previous column from env' => [['p' => ['previous_column' => 'ENV:COL', 'previous_expires_column' => 'e'] + $ok], 'p.previous_column must be a literal string or null'];
        yield 'sql with a container parameter' => [['p' => ['sql' => 'SELECT value FROM %table% WHERE name = :key'] + $ok], 'a "%"'];
        yield 'bad connection name' => [['p' => ['connection' => 'my-db'] + $ok], 'p.connection must be a DBAL connection name'];
        yield 'bad time zone' => [['p' => ['expires_timezone' => 'Mars/Olympus'] + $ok], 'p.expires_timezone is not a known time zone'];
        yield 'write query' => [['p' => ['sql' => 'SELECT value FROM t WHERE name = :key FOR UPDATE'] + $ok], 'nowo_runtime_env.providers.p: sql must be read-only'];
        yield 'too long ttl' => [['p' => ['cache_ttl' => 3601] + $ok], 'cache_ttl'];
        yield 'missing connection' => [['p' => ['sql' => 'SELECT value FROM t WHERE name = :key']], 'connection'];
    }

    /**
     * @param array<string, mixed> $providers
     */
    #[DataProvider('refusedEntries')]
    public function testRefuses(array $providers, string $reason): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($reason);

        $this->load($providers);
    }

    public function testThePassRequiresADeclaredConnection(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_runtime_env.provider_connections', ['ok' => 'default', 'tenant' => 'tenants']);
        $container->register('doctrine.dbal.default_connection', stdClass::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_runtime_env.providers.tenant.connection: DBAL connection "tenants" is not declared');

        (new DbalQueryProvidersPass())->process($container);
    }

    public function testThePassAcceptsDeclaredConnectionsAndNoProviders(): void
    {
        $container = new ContainerBuilder();
        (new DbalQueryProvidersPass())->process($container);

        $container->setParameter('nowo_runtime_env.provider_connections', ['ok' => 'default']);
        $container->register('doctrine.dbal.default_connection', stdClass::class);
        (new DbalQueryProvidersPass())->process($container);

        $container->setParameter('nowo_runtime_env.provider_connections', ['odd' => 12]);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('DBAL connection "?"');
        (new DbalQueryProvidersPass())->process($container);
    }

    /**
     * @param array<string, mixed> $providers
     */
    private function load(array $providers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        // "ENV:NAME" stands for an %env(NAME)% value, as the kernel hands it to the extension (a placeholder).
        array_walk_recursive($providers, static function (mixed &$value) use ($container): void {
            if (is_string($value) && str_starts_with($value, 'ENV:')) {
                $value = $container->getParameterBag()->get('env(' . substr($value, 4) . ')');
            }
        });
        (new RuntimeEnvExtension())->load([[
            'web_ui'    => ['enabled' => false],
            'security'  => ['allow_unauthenticated' => true],
            'providers' => $providers,
        ]], $container);

        return $container;
    }
}
