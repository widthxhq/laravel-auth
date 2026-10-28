<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests;

use Illuminate\Support\Facades\Auth;
use LogicException;
use Widthx\Laravel\OidcIdentity;
use Widthx\Laravel\Tests\Fixtures\User;

/** WIDTHX_USER_MODEL must be the model of WIDTHX_GUARD's provider, or a session would point at the wrong table. */
final class GuardModelTest extends TestCase
{
    protected function widthxConfig(): array
    {
        // Admin guard, but the model left at the app's users: an id from `users` would sign in as admins#id.
        return ['guard' => 'admin', 'user_model' => User::class];
    }

    public function testAModelThatIsNotTheGuardsModelIsAConfigurationError(): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->signInAs('stranger');
            self::fail('expected a configuration error');
        } catch (LogicException $e) {
            self::assertStringContainsString('WIDTHX_USER_MODEL', $e->getMessage());
        }

        self::assertSame(0, User::query()->count());
        self::assertSame(0, OidcIdentity::query()->count());
        self::assertFalse(Auth::guard('admin')->check());
    }
}
