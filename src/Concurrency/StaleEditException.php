<?php

declare(strict_types=1);

namespace Nowo\RuntimeEnvBundle\Concurrency;

use RuntimeException;

/**
 * 1.9 (REQ-RUNTIME-003 rule 4): an edit was made on a page that is no longer current. Someone saved the same variable
 * after the page was opened, so the row version the page carries no longer matches.
 *
 * It is a domain answer, not a lock conflict: nothing was written, retrying the same request never succeeds, and the
 * admin has to reload the page. The manage page catches it and renders the form again with a 409 and
 * `X-Error-Code: RUNTIME_ENV_STALE_EDIT` (never an `X-Conflict-Code`: that header is reserved for lock conflicts).
 */
final class StaleEditException extends RuntimeException
{
    /** Value of the `X-Error-Code` header of the 409 answer. */
    public const ERROR_CODE = 'RUNTIME_ENV_STALE_EDIT';

    /** Translation key (domain NowoRuntimeEnvBundle) of the flash shown above the form. */
    public const MESSAGE_KEY = 'runtime_env.flash.stale_edit';

    /** English text, used when no translator is wired. */
    public const MESSAGE = 'Someone else saved this variable after you opened the page. Your changes were not saved. Reload the page and apply them again.';

    /**
     * @param int $id the edited variable
     * @param int $expected the row version the page was built from
     */
    public function __construct(
        public readonly int $id,
        public readonly int $expected,
    ) {
        parent::__construct(self::MESSAGE);
    }
}
