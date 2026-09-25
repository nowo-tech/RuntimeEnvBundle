<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\EventSubscriber;

use Nowo\RuntimeEnvBundle\Security\RuntimeEnvAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_object;
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

    public function __construct(
        private readonly RuntimeEnvAccessCheckerInterface $accessChecker,
        private readonly string $pathPrefix,
        private readonly bool $allowUnauthenticated,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path    = $request->getPathInfo();
        $prefix  = rtrim($this->pathPrefix, '/') ?: '/_runtime_env';
        if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
            return;
        }

        if ($this->allowUnauthenticated) {
            return;
        }

        $user = $request->attributes->has('_firewall_context')
            ? $this->tokenStorage?->getToken()?->getUser()
            : null;
        if (!$this->accessChecker->canAccess(is_object($user) ? $user : null)) {
            throw new AccessDeniedHttpException('Access denied to Runtime Env manage UI.');
        }
    }
}
