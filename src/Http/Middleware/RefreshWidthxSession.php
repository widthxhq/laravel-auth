<?php

declare(strict_types=1);

namespace Widthx\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Widthx\Auth\Exception\AuthorizationException;
use Widthx\Auth\Widthx;
use Widthx\Laravel\Http\Controllers\AuthController;
use Widthx\Laravel\SessionLinks;
use Widthx\Laravel\SiteRoles;

/**
 * Keeps a Widthx sign-in fresh (ADR 0006): on the first request after the access token expires, refreshes it
 * server-side and updates the roles kept in the session. A refused refresh (access lost, account disabled,
 * session revoked) ends the app session; an unreachable Widthx keeps the current roles and retries later.
 * Added to the `web` group when WIDTHX_SITE is set (see `refresh_session`).
 */
final class RefreshWidthxSession
{
    /** Token endpoint errors that mean "this sign-in is over", not "try again later". */
    private const REFUSED = ['invalid_grant', 'access_denied', 'unauthorized_client', 'invalid_client', 'invalid_id_token'];

    public function __construct(private readonly Widthx $widthx)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $tokens = $request->session()->get(AuthController::TOKENS);
        $guard = Auth::guard(config('widthx.guard'));
        if (!is_array($tokens) || !is_string($tokens['refresh_token'] ?? null) || !$guard->check()
            || time() < (int) ($tokens['expires_at'] ?? PHP_INT_MAX) - 30) {
            return $next($request);
        }

        // Refresh tokens rotate: two requests refreshing at once would replay the old one and be refused. One
        // refreshes under a lock; the others take its result from the cache (keyed by the old token's hash).
        $old = hash('sha256', $tokens['refresh_token']);
        try {
            $fresh = Cache::lock("widthx.refresh.{$old}", 15)->block(10, function () use ($old, $tokens) {
                $done = Cache::get("widthx.refreshed.{$old}");
                if (is_array($done)) {
                    return $done;
                }
                $set = $this->widthx->client()->refresh($tokens['refresh_token'], is_string($tokens['sub'] ?? null) ? $tokens['sub'] : null);
                $roles = $set->idToken !== null ? SiteRoles::fromClaims($set->idTokenClaims) : ($tokens['roles'] ?? []);
                if ($roles === null) {
                    throw new AuthorizationException('invalid_id_token', 'token issued for another site');
                }
                $done = [
                    'id_token' => $set->idToken ?? $tokens['id_token'] ?? null,
                    'access_token' => $set->accessToken,
                    'refresh_token' => $set->refreshToken ?? $tokens['refresh_token'],
                    'expires_at' => $set->expiresAt,
                    'roles' => $roles,
                ];
                Cache::put("widthx.refreshed.{$old}", $done, 60);
                return $done;
            });
        } catch (AuthorizationException $e) {
            if (in_array($e->error, self::REFUSED, true)) {
                return $this->end($request, $e->error);
            }
            Log::warning('widthx refresh failed; keeping the current session', ['error' => $e->error]);
            return $next($request);
        } catch (\Throwable $e) {
            Log::warning('widthx refresh failed; keeping the current session', ['exception' => $e::class]);
            return $next($request);
        }

        $request->session()->put(AuthController::TOKENS, array_merge($tokens, $fresh));
        return $next($request);
    }

    private function end(Request $request, string $error): mixed
    {
        Log::info('widthx sign-in ended at refresh', ['error' => $error]);
        SessionLinks::forget($request->session()->getId());
        Auth::guard(config('widthx.guard'))->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($request->expectsJson()) {
            return new JsonResponse(['error' => 'access_revoked'], 401);
        }
        return redirect(config('widthx.home'))->with('widthx_error', 'access_revoked');
    }
}
