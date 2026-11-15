<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Transaction;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

use function is_string;
use function max;
use function str_starts_with;

/**
 * 1.6: a storage failure on a route of the bundle answers 503 or 409 instead of 500 (same design as
 * InstanceSettingsBundle 1.19).
 *
 * - transient ({@see TransientStorageFailure} of the runtime-setting store's runner, or a DBAL
 *   {@see RetryableException}: deadlock, lock wait timeout): `503 Service Unavailable` with `Retry-After`;
 * - conflict (a unique key refused the write of a concurrent request: two admins creating the same name): `409 Conflict`.
 *
 * The chain of previous exceptions is walked. The pages get a {@see ServiceUnavailableHttpException} /
 * {@see ConflictHttpException} with a fixed message, rendered by the application's error page; the storage error stays
 * the previous exception (for the logs), so no SQL, parameter or value reaches the client.
 *
 * Only the bundle's controllers: the host's routes keep the host's own error handling. Stateless.
 */
final readonly class StorageFailureListener
{
    /** kernel.exception priority: after the application's own listeners, before the framework's error listener (-128). */
    public const PRIORITY = -64;

    /** Controller namespace of the bundle (manage pages). */
    private const CONTROLLER_PREFIX = 'Nowo\\RuntimeEnvBundle\\';

    /** Retry-After when the failure carries no hint. */
    private const DEFAULT_RETRY_AFTER = 1;

    public function onException(ExceptionEvent $event): void
    {
        if (!$this->isBundleRequest($event->getRequest())) {
            return;
        }

        $failure = $event->getThrowable();
        $retry   = $this->retryAfter($failure);
        if ($retry !== null) {
            $event->setThrowable(new ServiceUnavailableHttpException($retry, 'The service is temporarily unavailable. Retry later.', $failure));

            return;
        }

        if ($this->isConflict($failure)) {
            $event->setThrowable(new ConflictHttpException('A concurrent request changed the same variable. Reload and retry.', $failure));
        }
    }

    /**
     * The Retry-After seconds of a transient failure, anywhere down the chain; null when the failure is not transient.
     */
    private function retryAfter(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return max(1, $current->retryAfterSeconds() ?? self::DEFAULT_RETRY_AFTER);
            }
            if ($current instanceof RetryableException) {
                return self::DEFAULT_RETRY_AFTER;
            }
        }

        return null;
    }

    private function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    private function isBundleRequest(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        return is_string($controller) && str_starts_with($controller, self::CONTROLLER_PREFIX);
    }
}
