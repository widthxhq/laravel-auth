<?php

declare(strict_types=1);

namespace Widthx\Laravel;

use RuntimeException;

/** A verified Widthx identity that may not sign in here; `$reason` is shown to the app as `widthx_error`. */
final class AccountRefused extends RuntimeException
{
    /** @param 'not_linked'|'account_unavailable'|'account_conflict' $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
