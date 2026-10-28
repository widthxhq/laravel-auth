<?php

declare(strict_types=1);

namespace Widthx\Laravel;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/** For the account model(s) that sign in through Widthx (the model of WIDTHX_GUARD's provider). */
trait HasOidcIdentities
{
    public static function bootHasOidcIdentities(): void
    {
        // A deleted account's identities go with it. Soft-deleted accounts keep them (and are refused at sign-in).
        static::deleted(function ($account) {
            if (!method_exists($account, 'isForceDeleting') || $account->isForceDeleting()) {
                $account->oidcIdentities()->delete();
            }
        });
    }

    public function oidcIdentities(): MorphMany
    {
        return $this->morphMany(OidcIdentity::class, 'authenticatable');
    }
}
