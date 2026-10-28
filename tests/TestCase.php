<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Widthx\Auth\Config;
use Widthx\Auth\PendingAuthorization;
use Widthx\Auth\Testing\FakeIdp;
use Widthx\Auth\Widthx;
use Widthx\Laravel\Tests\Fixtures\Admin;
use Widthx\Laravel\Tests\Fixtures\User;
use Widthx\Laravel\WidthxServiceProvider;

abstract class TestCase extends Orchestra
{
    protected FakeIdp $idp;

    /** Package config overrides for a test class (applied before the package migration runs). */
    protected function widthxConfig(): array
    {
        return [];
    }

    protected function getPackageProviders($app): array
    {
        return [WidthxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('widthx', array_merge($app['config']->get('widthx', []), [
            'issuer' => FakeIdp::ISSUER,
            'client_id' => 'client-a',
            'client_secret' => 's3cr3t',
            'redirect_uri' => 'https://app.test/auth/widthx/callback',
        ], $this->widthxConfig()));
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => Admin::class]);
        $app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'admins']);

        $this->idp = new FakeIdp();
        $app->singleton(Widthx::class, function ($app) {
            $c = $app['config']['widthx'];
            return Widthx::create(new Config(
                issuer: $c['issuer'],
                clientId: $c['client_id'],
                clientSecret: $c['client_secret'],
                redirectUri: $c['redirect_uri'],
                scopes: ['openid', 'email', 'profile'],
            ), $this->idp->httpClient());
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        // The app's own tables, as Laravel's skeleton creates them (users.password NOT NULL). The package never alters them.
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('admins', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        // Like `php artisan migrate` in an app: the service provider decides whether the package migration runs.
        $this->artisan('migrate', ['--force' => true])->run();
    }

    /** What an operator does to let an existing account sign in with Widthx (invite-only apps). */
    protected function link(Model $account, string $subject, string $issuer = FakeIdp::ISSUER): void
    {
        $account->oidcIdentities()->create(['issuer' => $issuer, 'subject' => $subject]);
    }

    /** Simulates the IdP redirecting back after a successful sign-in of $sub. */
    protected function signInAs(string $sub, array $claims = [])
    {
        [, $pending] = $this->app->make(Widthx::class)->client()->createAuthorization('/dashboard');
        /** @var PendingAuthorization $pending */
        $this->idp->tokenResponses[] = [[
            'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 900, 'token_type' => 'bearer',
            'id_token' => $this->idp->sign(FakeIdp::baseClaims('client-a', $claims + [
                'sub' => $sub, 'nonce' => $pending->nonce, 'sid' => 'sid-1', 'email' => 'ada@example.com', 'name' => 'Ada',
            ])),
        ], 200];
        return $this->withSession(['widthx.pending' => $pending->toArray()])
            ->get('/auth/widthx/callback?code=abc&state=' . urlencode($pending->state));
    }
}
