<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Customised style: once the app has published (and possibly edited) the migration, only its copy runs. */
final class PublishedMigrationTest extends TestCase
{
    private const PUBLISHED = '2026_11_01_000000_create_oidc_identities_tables.php';
    private static string $appDatabase;

    protected function defineEnvironment($app): void
    {
        self::$appDatabase = sys_get_temp_dir() . '/widthx-published-' . getmypid();
        @mkdir(self::$appDatabase . '/migrations', 0777, true);
        // What `vendor:publish --tag=widthx-migrations` does: copy the migration under a new timestamp.
        copy(__DIR__ . '/../database/migrations/' . MigrationModesTest::MIGRATION, self::$appDatabase . '/migrations/' . self::PUBLISHED);
        $app->useDatabasePath(self::$appDatabase);
        parent::defineEnvironment($app);
    }

    protected function tearDown(): void
    {
        @unlink(self::$appDatabase . '/migrations/' . self::PUBLISHED);
        @rmdir(self::$appDatabase . '/migrations');
        @rmdir(self::$appDatabase);
        parent::tearDown();
    }

    public function testPublishedCopyReplacesThePackageMigration(): void
    {
        self::assertNotContains(MigrationModesTest::packageMigrations(), array_map('realpath', $this->app['migrator']->paths()));
        // The app's copy ran, exactly once.
        self::assertTrue(Schema::hasTable('oidc_identities'));
        self::assertSame([basename(self::PUBLISHED, '.php')],
            DB::table('migrations')->where('migration', 'like', '%oidc_identities%')->pluck('migration')->all());
    }
}
