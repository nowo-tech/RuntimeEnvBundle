<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\Security;

use Nowo\RuntimeEnvBundle\Security\AllowAllRuntimeEnvAccessChecker;
use Nowo\RuntimeEnvBundle\Security\ConfigurableRuntimeEnvAccessChecker;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class RuntimeEnvAccessCheckerTest extends TestCase
{
    public function testAllowAllAlwaysTrue(): void
    {
        self::assertTrue((new AllowAllRuntimeEnvAccessChecker())->canAccess(null));
    }

    public function testConfigurableGrantsWhenRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');

        $checker = new ConfigurableRuntimeEnvAccessChecker($auth, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        self::assertTrue($checker->canAccess(null));
    }

    public function testConfigurableDeniesWhenNoRoleMatches(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(false);

        $checker = new ConfigurableRuntimeEnvAccessChecker($auth, ['ROLE_ADMIN']);
        self::assertFalse($checker->canAccess(null));
    }

    public function testConfigurableGrantsWhenNoRolesConfigured(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::never())->method('isGranted');

        $checker = new ConfigurableRuntimeEnvAccessChecker($auth, []);
        self::assertTrue($checker->canAccess(new stdClass()));
    }
}
