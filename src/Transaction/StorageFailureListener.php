<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Transaction;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

use function is_int;
use function is_string;
use function str_starts_with;

/**
 * 1.6, 1.8 (REQ-RUNTIME-003 rule 6): a storage failure on a route of the bundle answers a conflict instead of a 500.
 *
 * - lock conflict (a {@see TransientStorageFailure} of the runtime-setting store's runner, or a deadlock, lock wait
 *   timeout or serialization failure anywhere down the chain): the status of the {@see ConflictResponsePolicy} (**409**
 *   by default, 503 when the host configures it) with a conflict code ({@see ConflictCode}):
 *   - `CONFLICT_001` with `Retry-After` when nothing of the request was committed;
 *   - `CONFLICT_002` without `Retry-After` when part of it may already be committed: a transaction of the bundle's
 *     connection committed since the request started ({@see CommitLedger}), or the commit outcome is unknown;
 * - a unique key refused the write of a concurrent request (two admins creating the same name): `409` with the bundle's
 *   own code {@see self::NAME_TAKEN}, never a `CONFLICT_*` code (it is not a lock conflict: retrying the same request
 *   cannot succeed). A lost connection is never mapped.
 *
 * Every answer carries its code in the `X-Conflict-Code` header. The pages get an {@see HttpException} with a fixed
 * message, rendered by the application's error page; the storage error stays the previous exception (for the logs), so
 * no SQL, parameter or value reaches the client. The chain of previous exceptions is walked.
 *
 * Only the bundle's controllers: the host's routes keep the host's own error handling. Stateless apart from the shared
 * ledger, a monotonic counter read by difference only (FrankenPHP worker mode, kernel reset off).
 */
final readonly class StorageFailureListener
{
    /** kernel.exception priority: after the application's own listeners, before the framework's error listener (-128). */
    public const PRIORITY = -64;

    /** kernel.request priority: before the router (32), so the count is taken before any controller writes. */
    public const REQUEST_PRIORITY = 256;

    /** Request attribute holding the ledger count when the request started. */
    public const COMMITS_ATTRIBUTE = '_nowo_runtime_env_commits';

    /** Response header carrying the conflict code. */
    public const CODE_HEADER = 'X-Conflict-Code';

    /** Code of a duplicate name (not a lock conflict). */
    public const NAME_TAKEN = 'RUNTIME_ENV_NAME_TAKEN';

    /** Message of a CONFLICT_001 page (nothing committed). */
    public const RETRY_SAFE_MESSAGE = 'The service is temporarily busy. Retry later.';

    /** Message of a CONFLICT_002 page (part of the request may be committed). */
    public const COMMITTED_MESSAGE = 'Part of the change may already be saved. Reload before trying again.';

    /** Controller namespace of the bundle (manage pages). */
    private const CONTROLLER_PREFIX = 'Nowo\\RuntimeEnvBundle\\';

    private ConflictResponsePolicy $policy;

    private TransientFailureClassifier $classifier;

    /**
     * @param ConflictResponsePolicy|null $policy the conflict status and default Retry-After (null = 409, 1 s)
     * @param CommitLedger|null $ledger real commits of the bundle's connection (null = no commit tracking)
     */
    public function __construct(?ConflictResponsePolicy $policy = null, private ?CommitLedger $ledger = null)
    {
        $this->policy     = $policy ?? new ConflictResponsePolicy();
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Remembers how many transactions the bundle's connection had committed when the request started.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($this->ledger instanceof CommitLedger) {
            $event->getRequest()->attributes->set(self::COMMITS_ATTRIBUTE, $this->ledger->count());
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isBundleRequest($request)) {
            return;
        }

        $failure = $event->getThrowable();
        $code    = $this->lockConflictCode($failure, $request);
        if ($code instanceof ConflictCode) {
            $headers = [self::CODE_HEADER => $code->value];
            if ($code->retrySafe()) {
                $headers['Retry-After'] = (string) $this->policy->retryAfter($this->hint($failure));
            }
            $event->setThrowable(new HttpException($this->policy->status, $code->retrySafe() ? self::RETRY_SAFE_MESSAGE : self::COMMITTED_MESSAGE, $failure, $headers));

            return;
        }

        if ($this->isUniqueViolation($failure)) {
            $event->setThrowable(new ConflictHttpException('A concurrent request changed the same variable. Reload and retry.', $failure, 0, [self::CODE_HEADER => self::NAME_TAKEN]));
        }
    }

    /**
     * The conflict code of a lock conflict, or null when the failure is not one.
     */
    private function lockConflictCode(Throwable $failure, Request $request): ?ConflictCode
    {
        $transient = $this->classifier->isTransient($failure);
        for ($current = $failure; !$transient && $current instanceof Throwable; $current = $current->getPrevious()) {
            $transient = $current instanceof TransientStorageFailure;
        }

        if (!$transient && !$this->classifier->isCommitOutcomeUnknown($failure)) {
            return null;
        }

        return $this->committedEarlier($request) ? ConflictCode::Committed : ConflictCode::forFailure($failure);
    }

    /** Whether a transaction of the bundle's connection committed since the request started. */
    private function committedEarlier(Request $request): bool
    {
        $start = $request->attributes->get(self::COMMITS_ATTRIBUTE);

        return $this->ledger instanceof CommitLedger && is_int($start) && $this->ledger->count() > $start;
    }

    /** The first Retry-After hint of the chain (a transient failure of the runner); null for none or zero. */
    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint !== null && $hint > 0 ? $hint : null;
            }
        }

        return null;
    }

    private function isUniqueViolation(Throwable $failure): bool
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
