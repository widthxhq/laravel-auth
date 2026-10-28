<?php

declare(strict_types=1);

namespace Widthx\Laravel;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Widthx\Auth\Config;
use Widthx\Auth\Widthx;
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
    }

    public function boot(Router $router): void
    {
        $this->publishes([__DIR__ . '/../config/widthx.php' => config_path('widthx.php')], 'widthx-config');
        $this->publishesMigrations([__DIR__ . '/../database/migrations' => database_path('migrations')], 'widthx-migrations');
        if ($this->shouldRunMigrations()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
        $router->aliasMiddleware('widthx.token', VerifyWidthxToken::class);
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
