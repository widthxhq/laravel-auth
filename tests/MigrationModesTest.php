<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Widthx\Laravel\WidthxServiceProvider;

/** Default style: nothing to do, the package migration runs with `php artisan migrate`. */
final class MigrationModesTest extends TestCase
{
    public const MIGRATION = '2026_10_28_000000_create_oidc_identities_tables.php';

    public static function packageMigrations(): string
    {
        return realpath(__DIR__ . '/../database/migrations');
    }

    public function testPackageMigrationRunsByDefault(): void
    {
        self::assertContains(self::packageMigrations(), array_map('realpath', $this->app['migrator']->paths()));
        self::assertTrue(Schema::hasTable('oidc_identities'));
        self::assertTrue(Schema::hasTable('oidc_session_links'));
    }

    public function testMigrationsCanBePublishedToCustomise(): void
    {
        $paths = ServiceProvider::pathsToPublish(WidthxServiceProvider::class, 'widthx-migrations');
        self::assertSame([self::packageMigrations()], array_map('realpath', array_keys($paths)));
    }

    public function testMigrationIsIdempotent(): void
    {
        Artisan::call('migrate:rollback', ['--force' => true]);
        self::assertFalse(Schema::hasTable('oidc_identities'));

        // The app created the tables itself (e.g. from its schema dump); the package migration must not fail on them.
        Schema::create('oidc_identities', fn ($t) => $t->id());
        Schema::create('oidc_session_links', fn ($t) => $t->id());
        self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }
}
