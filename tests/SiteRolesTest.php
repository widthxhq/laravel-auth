<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Widthx\Auth\Testing\FakeIdp;
use Widthx\Laravel\Http\Controllers\AuthController;
use Widthx\Laravel\SiteRoles;
use Widthx\Laravel\Tests\Fixtures\User;

/** Site memberships (ADR 0006): roles from the token, refresh on expiry, refusal ends the session. */
final class SiteRolesTest extends TestCase
{
    protected function widthxConfig(): array
    {
        return ['site' => 'pharmed-admin', 'provisioning' => 'create'];
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/whoami', fn () => response()->json([
                'user' => Auth::id(),
                'roles' => app(SiteRoles::class)->all(),
                'admin' => Gate::allows('widthx-role', 'admin'),
            ]));
            Route::get('/admin-only', fn () => 'ok')->middleware('widthx.role:admin,owner');
        });
    }

    private function refreshResponse(array $roles, string $site = 'pharmed-admin', string $sub = 'sub-ada'): void
    {
        $this->idp->tokenResponses[] = [[
            'access_token' => 'at2', 'refresh_token' => 'rt2', 'expires_in' => 900, 'token_type' => 'bearer',
            'id_token' => $this->idp->sign(FakeIdp::baseClaims('client-a', ['sub' => $sub, 'site' => $site, 'roles' => $roles])),
        ], 200];
    }

    /** Makes the access token look expired, as 15 minutes later. */
    private function expire(): void
    {
        $tokens = session(AuthController::TOKENS);
        $tokens['expires_at'] = time() - 1;
        session([AuthController::TOKENS => $tokens]);
    }

    public function testRolesOfThisSiteComeFromTheIdToken(): void
    {
        $this->signInAs('sub-ada', ['site' => 'pharmed-admin', 'roles' => ['editor', 'admin']])->assertRedirect('/dashboard');

        $this->getJson('/whoami')->assertJson(['roles' => ['editor', 'admin'], 'admin' => true]);
        $this->get('/admin-only')->assertOk();
    }

    public function testRoleMiddlewareRefusesOtherRoles(): void
    {
        $this->signInAs('sub-ada', ['site' => 'pharmed-admin', 'roles' => ['viewer']]);

        $this->get('/admin-only')->assertForbidden();
        $this->getJson('/whoami')->assertJson(['admin' => false]);
    }

    public function testATokenForAnotherSiteIsRefused(): void
    {
        $this->signInAs('sub-ada', ['site' => 'other-site', 'roles' => ['admin']])
            ->assertRedirect('/')
            ->assertSessionHas('widthx_error', 'site_mismatch');
        self::assertFalse(Auth::check());
        self::assertSame(0, User::query()->count());
    }

    public function testRolesFollowChangesAtTheNextRefresh(): void
    {
        $this->signInAs('sub-ada', ['site' => 'pharmed-admin', 'roles' => ['viewer']]);
        $this->getJson('/whoami')->assertJson(['roles' => ['viewer']]);

        $this->expire();
        $this->refreshResponse(['viewer', 'admin']);
        $this->getJson('/whoami')->assertJson(['roles' => ['viewer', 'admin'], 'admin' => true]);
        self::assertSame('rt2', session(AuthController::TOKENS . '.refresh_token'));
        self::assertGreaterThan(time(), session(AuthController::TOKENS . '.expires_at'));
    }

    public function testNoRefreshBeforeTheAccessTokenExpires(): void
    {
        $this->signInAs('sub-ada', ['site' => 'pharmed-admin', 'roles' => ['viewer']]);
        $calls = $this->idp->requestCount('/oauth2/token');

        $this->getJson('/whoami')->assertOk();

        self::assertSame($calls, $this->idp->requestCount('/oauth2/token'));
    }

    public function testARefusedRefreshEndsTheSession(): void
    {
        $this->signInAs('sub-ada', ['site' => 'pharmed-admin', 'roles' => ['admin']]);
        $this->expire();
        $this->idp->tokenResponses[] = [['error' => 'access_denied'], 403];

        $this->get('/whoami')->assertRedirect('/')->assertSessionHas('widthx_error', 'access_revoked');
        self::assertFalse(Auth::check());
        $this->getJson('/whoami')->assertJson(['user' => null, 'roles' => []]);
    }

    public function testARefusedRefreshAnswers401ToJsonRequests(): void
    {
        $this->signInAs('sub-ada', ['site' => 'pharmed-admin', 'roles' => ['admin']]);
        $this->expire();
        $this->idp->tokenResponses[] = [['error' => 'invalid_grant'], 400];

        $this->getJson('/whoami')->assertStatus(401)->assertJson(['error' => 'access_revoked']);
    }

    public function testAnUnreachableWidthxKeepsTheCurrentRoles(): void
    {
        $this->signInAs('sub-ada', ['site' => 'pharmed-admin', 'roles' => ['admin']]);
        $this->expire();
        $this->idp->tokenResponses[] = ['<html>Bad gateway</html>', 502];

        $this->getJson('/whoami')->assertOk()->assertJson(['roles' => ['admin']]);
        self::assertTrue(Auth::check());
    }

    public function testAConcurrentRequestReusesTheRefreshInsteadOfReplayingTheOldToken(): void
    {
        $this->signInAs('sub-ada', ['site' => 'pharmed-admin', 'roles' => ['viewer']]);
        $this->expire();
        $stale = session(AuthController::TOKENS);
        $this->refreshResponse(['viewer', 'admin']);
        $this->getJson('/whoami')->assertJson(['roles' => ['viewer', 'admin']]);

        // A second request that started with the old tokens: no second refresh (Widthx would refuse the replay).
        session([AuthController::TOKENS => $stale]);
        $calls = $this->idp->requestCount('/oauth2/token');
        $this->getJson('/whoami')->assertOk()->assertJson(['roles' => ['viewer', 'admin']]);
        self::assertSame($calls, $this->idp->requestCount('/oauth2/token'));
    }

    public function testARefreshThatSwitchesSiteEndsTheSession(): void
    {
        $this->signInAs('sub-ada', ['site' => 'pharmed-admin', 'roles' => ['admin']]);
        $this->expire();
        $this->refreshResponse(['admin'], 'other-site');

        $this->get('/whoami')->assertSessionHas('widthx_error', 'access_revoked');
        self::assertFalse(Auth::check());
    }
}
