<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\RuntimeSetting\Provider;

use Nowo\RuntimeEnvBundle\RuntimeSetting\Provider\CacheScopeEnvVarProcessor;
use PHPUnit\Framework\TestCase;
use stdClass;

use function hash;
use function str_repeat;
use function strlen;
use function substr;

final class CacheScopeEnvVarProcessorTest extends TestCase
{
    public function testHashesTheEnvValueToAFixedLength(): void
    {
        $processor = new CacheScopeEnvVarProcessor();

        self::assertSame(substr(hash('sha256', 'acme'), 0, 16), $processor->getEnv('nowo_runtime_env_scope', 'TENANT_CODE', static fn (string $name): string => $name === 'TENANT_CODE' ? 'acme' : 'other'));
        self::assertSame(16, strlen($processor->getEnv('nowo_runtime_env_scope', 'BIG', static fn (): string => str_repeat('v', 100000))));
        self::assertSame(['nowo_runtime_env_scope' => 'string'], CacheScopeEnvVarProcessor::getProvidedTypes());
    }

    public function testNonStringValues(): void
    {
        self::assertSame(CacheScopeEnvVarProcessor::hash('1'), CacheScopeEnvVarProcessor::hash(true));
        self::assertSame(CacheScopeEnvVarProcessor::hash('0'), CacheScopeEnvVarProcessor::hash(false));
        self::assertSame(CacheScopeEnvVarProcessor::hash('7'), CacheScopeEnvVarProcessor::hash(7));
        self::assertSame(CacheScopeEnvVarProcessor::hash(''), CacheScopeEnvVarProcessor::hash(null));
        self::assertSame(CacheScopeEnvVarProcessor::hash(''), CacheScopeEnvVarProcessor::hash(new stdClass()));
    }
}
