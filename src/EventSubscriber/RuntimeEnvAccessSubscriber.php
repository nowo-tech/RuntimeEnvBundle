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
 */
final class RuntimeEnvAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RuntimeEnvAccessCheckerInterface $accessChecker,
        private readonly string $pathPrefix,
        private readonly bool $allowUnauthenticated,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path   = $event->getRequest()->getPathInfo();
        $prefix = rtrim($this->pathPrefix, '/') ?: '/_runtime_env';
        if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
            return;
        }

        if ($this->allowUnauthenticated) {
            return;
        }

        $user = $this->tokenStorage?->getToken()?->getUser();
        if (!$this->accessChecker->canAccess(is_object($user) ? $user : null)) {
            throw new AccessDeniedHttpException('Access denied to Runtime Env manage UI.');
        }
    }
}
