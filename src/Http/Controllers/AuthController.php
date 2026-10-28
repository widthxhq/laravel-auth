<?php

declare(strict_types=1);

namespace Widthx\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Widthx\Auth\Exception\AuthorizationException;
use Widthx\Auth\Exception\WidthxAuthException;
use Widthx\Auth\PendingAuthorization;
use Widthx\Auth\Widthx;
use Widthx\Laravel\AccountRefused;
use Widthx\Laravel\AccountResolver;
use Widthx\Laravel\SessionLinks;

final class AuthController
{
    private const PENDING = 'widthx.pending';
    public const TOKENS = 'widthx.tokens';

    public function __construct(private readonly Widthx $widthx, private readonly AccountResolver $accounts)
    {
    }

    public function login(Request $request): RedirectResponse
    {
        $extra = $request->query('prompt') === 'login' ? ['prompt' => 'login'] : [];
        [$url, $pending] = $this->widthx->client()->createAuthorization((string) $request->query('return_to', config('widthx.home')), $extra);
        $request->session()->put(self::PENDING, $pending->toArray());
        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $data = $request->session()->pull(self::PENDING);
        $pending = is_array($data) ? PendingAuthorization::fromArray($data) : null;
        try {
            $tokens = $this->widthx->client()->handleCallback($request->query(), $pending);
        } catch (AuthorizationException $e) {
            Log::info('widthx login failed', ['error' => $e->error]);
            return redirect(config('widthx.home'))->with('widthx_error', $e->error);
        } catch (WidthxAuthException $e) {
            Log::warning('widthx login error', ['exception' => $e::class]);
            return redirect(config('widthx.home'))->with('widthx_error', 'login_failed');
        }

        $claims = $tokens->idTokenClaims;
        try {
            $user = $this->accounts->resolve($claims);
        } catch (AccountRefused $e) {
            return redirect(config('widthx.home'))->with('widthx_error', $e->reason);
        }

        Auth::guard(config('widthx.guard'))->login($user);
        $request->session()->regenerate(); // prevent session fixation
        $request->session()->put(self::TOKENS, [
            'id_token' => $tokens->idToken,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'expires_at' => $tokens->expiresAt,
            'email_verified' => (bool) ($claims['email_verified'] ?? false),
        ]);
        SessionLinks::record($request->session()->getId(), $claims['sid'] ?? null, (string) $claims['sub']);
        return redirect($pending?->returnTo ?? config('widthx.home'));
    }

    /**
     * mode=local ends only this app's session; mode=central also ends the Widthx IdP session. The whole Laravel
     * session is invalidated, so other guards sharing it are signed out too.
     */
    public function logout(Request $request): RedirectResponse
    {
        $idToken = $request->session()->get(self::TOKENS . '.id_token');
        SessionLinks::forget($request->session()->getId());
        Auth::guard(config('widthx.guard'))->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($request->input('mode') === 'central' && is_string($idToken)) {
            return redirect()->away($this->widthx->client()->endSessionUrl($idToken));
        }
        return redirect(config('widthx.home'));
    }
}
