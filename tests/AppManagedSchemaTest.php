<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests;

use Illuminate\Support\Facades\Schema;

/** App-managed schema (schema dumps, hand-written SQL): WIDTHX_RUN_MIGRATIONS=false. */
final class AppManagedSchemaTest extends TestCase
{
    protected function widthxConfig(): array
    {
        return ['run_migrations' => false];
    }

    public function testPackageMigrationIsNotLoaded(): void
    {
        self::assertNotContains(MigrationModesTest::packageMigrations(), array_map('realpath', $this->app['migrator']->paths()));
        self::assertFalse(Schema::hasTable('oidc_identities'));
    }
}
