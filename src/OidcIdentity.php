<?php

declare(strict_types=1);

namespace Widthx\Laravel;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A local account's identity at an OpenID Provider (issuer + subject). Accounts are matched only through these
 * rows, never by email. Create one to link an existing account (invite-only apps): `$account->oidcIdentities()->create([...])`.
 */
final class OidcIdentity extends Model
{
    protected $table = 'oidc_identities';
    // The account is set only through the relation, so request data can never re-point a link to another account.
    protected $fillable = ['issuer', 'subject'];

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Issuers are stored without a trailing slash so a provider is always the same string. */
    public static function normalizeIssuer(string $issuer): string
    {
        return rtrim($issuer, '/');
    }

    protected function issuer(): Attribute
    {
        return Attribute::set(fn (string $value) => self::normalizeIssuer($value));
    }
}
