<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Routing;

use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function rtrim;

/**
 * Loads the manage CRUD routes when the manage UI is enabled.
 *
 * The routes are declared with #[Route] on {@see RuntimeEnvManageController} (path relative to the prefix, name,
 * methods, requirements); this loader reads those attributes and prepends `web_ui.path_prefix`, so the prefix stays
 * configurable while the route definitions live on the controller (REQ-SF-004). Route names and paths are unchanged
 * since 1.0.
 */
final class RuntimeEnvRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(
        private readonly bool $panelEnabled,
        private readonly string $pathPrefix,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Do not add the "nowo_runtime_env" loader twice.');
        }
        // @igor-ignore - Route loader builds routes at compile/cache warm; not per-request mutation.
        $this->loaded = true;

        $collection = new RouteCollection();
        if (!$this->panelEnabled) {
            return $collection;
        }

        $prefix = rtrim($this->pathPrefix, '/') ?: '/_runtime_env';

        foreach ((new ReflectionClass(RuntimeEnvManageController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                $declared = $attribute->newInstance();
                $path     = $declared->path;
                $collection->add((string) $declared->name, new Route(
                    $prefix . (is_string($path) ? $path : ''),
                    ['_controller' => RuntimeEnvManageController::class . '::' . $method->getName()],
                    $declared->requirements,
                    methods: $declared->methods,
                ));
            }
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_runtime_env';
    }
}
