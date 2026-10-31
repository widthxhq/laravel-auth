<?php

declare(strict_types=1);

namespace Widthx\Laravel;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Widthx\Auth\Config;
use Widthx\Auth\Widthx;
use Widthx\Laravel\Http\Middleware\RefreshWidthxSession;
use Widthx\Laravel\Http\Middleware\RequireWidthxRole;
use Widthx\Laravel\Http\Middleware\VerifyWidthxToken;

final class WidthxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/widthx.php', 'widthx');

        $this->app->singleton(Widthx::class, function ($app) {
            $c = $app['config']['widthx'];
            return Widthx::create(
                new Config(
                    issuer: (string) $c['issuer'],
                    clientId: (string) $c['client_id'],
                    clientSecret: $c['client_secret'] ?: null,
                    redirectUri: (string) $c['redirect_uri'],
                    scopes: array_values(array_filter($c['scopes'])),
                    audience: $c['audience'],
                    postLogoutRedirectUri: $c['post_logout_redirect_uri'] ?: null,
                ),
                cache: $app->make(Cache::class), // Laravel cache implements PSR-16
            );
        });
        $this->app->bind(SiteRoles::class, fn ($app) => new SiteRoles($app['session.store']));
    }

    public function boot(Router $router): void
    {
        $this->publishes([__DIR__ . '/../config/widthx.php' => config_path('widthx.php')], 'widthx-config');
        $this->publishesMigrations([__DIR__ . '/../database/migrations' => database_path('migrations')], 'widthx-migrations');
        if ($this->shouldRunMigrations()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
        $router->aliasMiddleware('widthx.token', VerifyWidthxToken::class);
        $router->aliasMiddleware('widthx.role', RequireWidthxRole::class);
        $router->aliasMiddleware('widthx.fresh', RefreshWidthxSession::class);
        // Site roles must follow changes made at Widthx: refresh on expiry for every web request.
        // Through the HTTP kernel: it re-syncs its groups onto the router for every request.
        $kernel = $this->app->bound(HttpKernel::class) ? $this->app->make(HttpKernel::class) : null;
        if (config('widthx.site') && config('widthx.refresh_session', true) && $kernel instanceof FoundationHttpKernel) {
            $kernel->appendMiddlewareToGroup('web', RefreshWidthxSession::class);
        }
        // `@can('widthx-role', 'admin')`, `Gate::allows('widthx-role', ['admin', 'manager'])`.
        $this->app->make(Gate::class)->define('widthx-role', fn ($user, string|array $roles) => $this->app->make(SiteRoles::class)->has(...(array) $roles));
        if (config('widthx.routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/widthx.php');
        }
    }

    /** No app is forced to own a migration: see `run_migrations` in config/widthx.php for the three styles. */
    private function shouldRunMigrations(): bool
    {
        // A published copy (any timestamp) replaces the package's own, so the same change never runs twice.
        return (bool) config('widthx.run_migrations', true)
            && !glob($this->app->databasePath('migrations/*_create_oidc_identities_tables.php'));
    }
}
