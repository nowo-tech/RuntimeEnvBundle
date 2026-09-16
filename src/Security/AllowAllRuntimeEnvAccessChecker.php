<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Security;

/**
 * Always grants access (demo / allow_unauthenticated).
 */
final class AllowAllRuntimeEnvAccessChecker implements RuntimeEnvAccessCheckerInterface
{
    public function canAccess(?object $user): bool
    {
        return true;
    }
}
