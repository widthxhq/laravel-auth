<?php

declare(strict_types=1);

namespace Widthx\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Widthx\Auth\AccessTokenVerifier;
use Widthx\Auth\Exception\InsufficientScopeException;
use Widthx\Auth\Exception\InvalidTokenException;
use Widthx\Auth\Widthx;

/**
 * Route middleware for Laravel APIs: `->middleware('widthx.token:projects:read,projects:write')`.
 * The verified token is available as `$request->attributes->get('widthx_token')`.
 * Authorization (roles, tenants) remains the app's job, keyed by `$token->subject`.
 */
final class VerifyWidthxToken
{
    public function __construct(private readonly Widthx $widthx)
    {
    }

    public function handle(Request $request, Closure $next, string ...$scopes): mixed
    {
        $audience = (string) config('widthx.api_audience');
        try {
            $token = $this->widthx->accessTokens($audience)->verify(AccessTokenVerifier::bearer($request->header('Authorization')), $scopes);
        } catch (InvalidTokenException) {
            return new JsonResponse(['error' => 'invalid_token'], 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        } catch (InsufficientScopeException $e) {
            return new JsonResponse(['error' => 'insufficient_scope'], 403, ['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="' . implode(' ', $e->missing) . '"']);
        }
        $request->attributes->set('widthx_token', $token);
        return $next($request);
    }
}
