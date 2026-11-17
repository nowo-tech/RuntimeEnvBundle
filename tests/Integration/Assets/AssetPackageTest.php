<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Integration\Assets;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * REQ-ASSETS-004: the URL the template renders with `asset('css/runtime-env.css', 'nowo_runtime_env')`.
 */
final class AssetPackageTest extends TestCase
{
    private const EXPECTED = '/bundles/noworuntimeenv/css/runtime-env.css';

    private ?AssetPackageKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-runtime-env-assets');
    }

    public function testTheRenderedUrlKeepsTheBasePath(): void
    {
        self::assertSame(self::EXPECTED, $this->url([]));
    }

    public function testTheHostsDefaultBaseUrlsAreNotInherited(): void
    {
        self::assertSame(self::EXPECTED, $this->url(['base_urls' => ['https://cdn.example.test']]));
    }

    public function testTheHostsDefaultBasePathIsNotInherited(): void
    {
        self::assertSame(self::EXPECTED, $this->url(['base_path' => '/sub']));
    }

    /**
     * @param array<string, mixed> $assets
     */
    private function url(array $assets): string
    {
        $this->kernel = new AssetPackageKernel($assets);
        $this->kernel->boot();
        $packages = $this->kernel->getContainer()->get('test.assets');
        self::assertInstanceOf(Packages::class, $packages);

        return $packages->getUrl('css/runtime-env.css', 'nowo_runtime_env');
    }
}
