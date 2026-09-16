<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker (REQ-UI-002).
 */
final readonly class ConfigurableRuntimeEnvAccessChecker implements RuntimeEnvAccessCheckerInterface
{
    /** @param list<string> $accessRoles */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
    ) {
    }

    public function canAccess(?object $user): bool
    {
        if ($this->accessRoles === []) {
            return true;
        }

        foreach ($this->accessRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
