<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Security;

interface RuntimeEnvAccessCheckerInterface
{
    public function canAccess(?object $user): bool;
}
