<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Routing;

use Nowo\RuntimeEnvBundle\Routing\RuntimeEnvRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuntimeEnvRouteLoaderTest extends TestCase
{
    public function testSupportsOnlyExpectedType(): void
    {
        $loader = new RuntimeEnvRouteLoader(true, '/admin/runtime-env');

        self::assertTrue($loader->supports(null, 'nowo_runtime_env'));
        self::assertFalse($loader->supports(null, 'other'));
    }

    public function testLoadReturnsEmptyCollectionWhenPanelDisabled(): void
    {
        $collection = (new RuntimeEnvRouteLoader(false, '/admin/runtime-env'))->load(null, 'nowo_runtime_env');

        self::assertCount(0, $collection);
    }

    public function testLoadBuildsCrudRoutesWithTrimmedPrefix(): void
    {
        $collection = (new RuntimeEnvRouteLoader(true, '/admin/runtime-env/'))->load(null, 'nowo_runtime_env');

        self::assertSame('/admin/runtime-env', $collection->get('nowo_runtime_env_index')?->getPath());
        self::assertSame(['GET'], $collection->get('nowo_runtime_env_index')?->getMethods());
        self::assertSame('/admin/runtime-env/new', $collection->get('nowo_runtime_env_new')?->getPath());
        self::assertSame(['GET', 'POST'], $collection->get('nowo_runtime_env_new')?->getMethods());
        self::assertSame('/admin/runtime-env/{id}/edit', $collection->get('nowo_runtime_env_edit')?->getPath());
        self::assertSame('\d+', $collection->get('nowo_runtime_env_edit')?->getRequirement('id'));
        self::assertSame('/admin/runtime-env/{id}/delete', $collection->get('nowo_runtime_env_delete')?->getPath());
        self::assertSame(['POST'], $collection->get('nowo_runtime_env_delete')?->getMethods());
    }

    public function testLoadFallsBackToDefaultPrefixWhenConfiguredPrefixIsEmpty(): void
    {
        $collection = (new RuntimeEnvRouteLoader(true, ''))->load(null, 'nowo_runtime_env');

        self::assertSame('/_runtime_env', $collection->get('nowo_runtime_env_index')?->getPath());
    }

    public function testLoadCannotRunTwice(): void
    {
        $loader = new RuntimeEnvRouteLoader(true, '/admin/runtime-env');
        $loader->load(null, 'nowo_runtime_env');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Do not add the "nowo_runtime_env" loader twice.');

        $loader->load(null, 'nowo_runtime_env');
    }
}
