<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Routing;

use Nowo\RuntimeEnvBundle\Controller\RuntimeEnvManageController;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function rtrim;

/**
 * Loads manage CRUD routes when the panel is enabled.
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
        $this->loaded = true;

        $collection = new RouteCollection();
        if (!$this->panelEnabled) {
            return $collection;
        }

        $prefix = rtrim($this->pathPrefix, '/') ?: '/_runtime_env';

        $collection->add('nowo_runtime_env_index', new Route(
            $prefix,
            ['_controller' => RuntimeEnvManageController::class . '::index'],
            methods: ['GET'],
        ));
        $collection->add('nowo_runtime_env_new', new Route(
            $prefix . '/new',
            ['_controller' => RuntimeEnvManageController::class . '::new'],
            methods: ['GET', 'POST'],
        ));
        $collection->add('nowo_runtime_env_edit', new Route(
            $prefix . '/{id}/edit',
            ['_controller' => RuntimeEnvManageController::class . '::edit'],
            ['id' => '\d+'],
            methods: ['GET', 'POST'],
        ));
        $collection->add('nowo_runtime_env_delete', new Route(
            $prefix . '/{id}/delete',
            ['_controller' => RuntimeEnvManageController::class . '::delete'],
            ['id' => '\d+'],
            methods: ['POST'],
        ));

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_runtime_env';
    }
}
