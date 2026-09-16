<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Tests\Unit\EventSubscriber;

use Nowo\RuntimeEnvBundle\EventSubscriber\RuntimeEnvAccessSubscriber;
use Nowo\RuntimeEnvBundle\Security\RuntimeEnvAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class RuntimeEnvAccessSubscriberTest extends TestCase
{
    public function testSubscribedEventsExposeExpectedPriority(): void
    {
        self::assertSame([KernelEvents::REQUEST => ['onKernelRequest', 8]], RuntimeEnvAccessSubscriber::getSubscribedEvents());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $checker = $this->createMock(RuntimeEnvAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        $subscriber = new RuntimeEnvAccessSubscriber($checker, '/admin/runtime-env', false);
        $subscriber->onKernelRequest($this->createRequestEvent('/admin/runtime-env', HttpKernelInterface::SUB_REQUEST));

        self::assertTrue(true);
    }

    public function testUnrelatedPathsAreIgnored(): void
    {
        $checker = $this->createMock(RuntimeEnvAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        $subscriber = new RuntimeEnvAccessSubscriber($checker, '/admin/runtime-env', false);
        $subscriber->onKernelRequest($this->createRequestEvent('/health'));

        self::assertTrue(true);
    }

    public function testAllowUnauthenticatedSkipsAccessCheck(): void
    {
        $checker = $this->createMock(RuntimeEnvAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        $subscriber = new RuntimeEnvAccessSubscriber($checker, '/admin/runtime-env', true);
        $subscriber->onKernelRequest($this->createRequestEvent('/admin/runtime-env/edit'));

        self::assertTrue(true);
    }

    public function testMatchingPathPassesObjectUserToAccessChecker(): void
    {
        $user         = $this->createMock(UserInterface::class);
        $token        = $this->createMock(TokenInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $checker      = $this->createMock(RuntimeEnvAccessCheckerInterface::class);

        $token->expects(self::once())->method('getUser')->willReturn($user);
        $tokenStorage->expects(self::once())->method('getToken')->willReturn($token);
        $checker->expects(self::once())->method('canAccess')->with($user)->willReturn(true);

        $subscriber = new RuntimeEnvAccessSubscriber($checker, '/admin/runtime-env/', false, $tokenStorage);
        $subscriber->onKernelRequest($this->createRequestEvent('/admin/runtime-env/12/edit'));

        self::assertTrue(true);
    }

    public function testMissingUserIsNormalizedToNullAndDeniedWhenCheckerRejects(): void
    {
        $token        = $this->createMock(TokenInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $checker      = $this->createMock(RuntimeEnvAccessCheckerInterface::class);

        $token->method('getUser')->willReturn(null);
        $tokenStorage->method('getToken')->willReturn($token);
        $checker->expects(self::once())->method('canAccess')->with(null)->willReturn(false);

        $subscriber = new RuntimeEnvAccessSubscriber($checker, '', false, $tokenStorage);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Access denied to Runtime Env manage UI.');

        $subscriber->onKernelRequest($this->createRequestEvent('/_runtime_env/delete'));
    }

    private function createRequestEvent(string $path, int $requestType = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($path),
            $requestType,
        );
    }
}
