<?php

declare(strict_types=1);

namespace Widthx\Laravel;

use Illuminate\Contracts\Session\Session;
use Widthx\Laravel\Http\Controllers\AuthController;

/**
 * The signed-in account's roles on this app's site (ADR 0006), from the ID token: `Widthx` puts `site` and
 * `roles` in every token of a site's client and recomputes them at each refresh (RefreshWidthxSession).
 * Roles count only when the token's `site` is this app's `WIDTHX_SITE`.
 */
final class SiteRoles
{
    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Roles from verified ID token claims: a list (possibly empty), or null when the token is for another site
     * than WIDTHX_SITE (a misconfiguration or a token meant for someone else: refuse it).
     *
     * @param array<string, mixed> $claims
     * @return list<string>|null
     */
    public static function fromClaims(array $claims): ?array
    {
        $site = config('widthx.site');
        if (!is_string($site) || $site === '') {
            return [];
        }
        if (($claims['site'] ?? null) !== $site) {
            return null;
        }
        $roles = $claims['roles'] ?? [];
        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    /** @return list<string> */
    public function all(): array
    {
        $roles = $this->session->get(AuthController::TOKENS . '.roles', []);
        return is_array($roles) ? array_values($roles) : [];
    }

    /** True when the account holds any of the given roles. */
    public function has(string ...$roles): bool
    {
        return array_intersect($roles, $this->all()) !== [];
    }
}
