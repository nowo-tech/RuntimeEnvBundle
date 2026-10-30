<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\EventSubscriber;

use Nowo\RuntimeEnvBundle\Security\RuntimeEnvAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_array;
use function is_object;
use function is_string;
use function ltrim;
use function rtrim;
use function str_starts_with;

/**
 * Soft gate for manage routes (REQ-UI-002). Host must also firewall {@code path_prefix}.
 *
 * Runs after the security firewall (priority 8). The token is only trusted when a firewall matched the current
 * main request, so a token left in the token storage by a previous worker request is never used.
 */
final class RuntimeEnvAccessSubscriber implements EventSubscriberInterface
{
    public const PRIORITY = 7;

    public const CONTROLLER_NAMESPACE = 'Nowo\\RuntimeEnvBundle\\Controller\\';

    public function __construct(
        private readonly RuntimeEnvAccessCheckerInterface $accessChecker,
        private readonly string $pathPrefix,
        private readonly bool $allowUnauthenticated,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * `_controller` of a bundle controller: `Class::method` string or `[Class, method]` array.
     */
    private function isBundleController(mixed $controller): bool
    {
        if (is_array($controller)) {
            $controller = $controller[0] ?? null;
        }

        return is_string($controller) && str_starts_with(ltrim($controller, '\\'), self::CONTROLLER_NAMESPACE);
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // The bundle's controllers are guarded wherever the host mounts them: a routing import with its own `prefix:`
        // moves them out of path_prefix (and out of the recipe's access_control), and a host template may embed them
        // as a sub-request (`render(controller(...))`), which no firewall sees.
        $isBundleController = $this->isBundleController($request->attributes->get('_controller'));
        if (!$event->isMainRequest()) {
            if (!$isBundleController) {
                return;
            }
        } else {
            $path   = $request->getPathInfo();
            $prefix = rtrim($this->pathPrefix, '/') ?: '/_runtime_env';
            if (!$isBundleController && $path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                return;
            }
        }

        if ($this->allowUnauthenticated) {
            return;
        }

        // The token is trusted only when a firewall matched the main request (sub-requests inherit it).
        $mainRequest = $event->isMainRequest() ? $request : $this->requestStack?->getMainRequest();
        $user        = $mainRequest instanceof Request && $mainRequest->attributes->has('_firewall_context')
            ? $this->tokenStorage?->getToken()?->getUser()
            : null;
        if (!$this->accessChecker->canAccess(is_object($user) ? $user : null)) {
            throw new AccessDeniedHttpException('Access denied to Runtime Env manage UI.');
        }
    }
}
