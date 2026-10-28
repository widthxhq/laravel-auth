<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Widthx\Auth\Testing\FakeIdp;
use Widthx\Laravel\OidcIdentity;
use Widthx\Laravel\Tests\Fixtures\Admin;
use Widthx\Laravel\Tests\Fixtures\User;

/** Admin console: separate admins table and guard, invite-only (link), app-owned profile. */
final class LinkProvisioningTest extends TestCase
{
    protected function widthxConfig(): array
    {
        return [
            'guard' => 'admin', // the account model comes from the guard's provider (admins → Admin)
            'provisioning' => 'link',
            'sync_profile' => false,
        ];
    }

    public function testUnknownIdentityGetsNoAccountAndIsNotSignedIn(): void
    {
        $this->signInAs('stranger')
            ->assertRedirect('/')
            ->assertSessionHas('widthx_error', 'not_linked');

        self::assertSame(0, Admin::query()->count());
        self::assertSame(0, OidcIdentity::query()->count());
        self::assertFalse(Auth::guard('admin')->check());
        self::assertFalse(Auth::guard('web')->check());
        self::assertSame(0, DB::table('oidc_session_links')->count());
    }

    public function testUnlinkedAccountWithTheSameEmailIsNotTakenOver(): void
    {
        $admin = Admin::query()->create(['email' => 'ada@example.com', 'name' => 'Ada (not linked yet)']);

        $this->signInAs('stranger')->assertSessionHas('widthx_error', 'not_linked');

        self::assertSame(0, $admin->oidcIdentities()->count());
        self::assertFalse(Auth::guard('admin')->check());
    }

    public function testIdentityLinkedToAnotherAccountTypeDoesNotOpenTheConsole(): void
    {
        // The same person is an app user; that link must not grant the admin console.
        $user = User::query()->create(['email' => 'ada@example.com', 'name' => 'Ada', 'password' => 'x']);
        $this->link($user, 'sub-ada');

        $this->signInAs('sub-ada')->assertSessionHas('widthx_error', 'not_linked');

        self::assertFalse(Auth::guard('admin')->check());
    }

    public function testLinkedAdminSignsInOnTheConfiguredGuardOnly(): void
    {
        $admin = Admin::query()->create(['email' => 'ops@pharmed.test', 'name' => 'Ops']);
        $this->link($admin, 'sub-admin');

        $this->signInAs('sub-admin')->assertRedirect('/dashboard');

        self::assertSame($admin->id, Auth::guard('admin')->id());
        self::assertFalse(Auth::guard('web')->check(), 'the default guard stays signed out');
        self::assertSame(1, DB::table('oidc_session_links')->where('subject', 'sub-admin')->count());
    }

    public function testOnePersonCanHoldAUserAndAnAdminAccount(): void
    {
        $user = User::query()->create(['email' => 'ada@example.com', 'name' => 'Ada', 'password' => 'x']);
        $admin = Admin::query()->create(['email' => 'ada@example.com', 'name' => 'Ada']);
        $this->link($user, 'sub-ada');
        $this->link($admin, 'sub-ada');

        $this->signInAs('sub-ada')->assertRedirect('/dashboard');

        self::assertSame($admin->id, Auth::guard('admin')->id());
    }

    public function testAnAccountHoldsAtMostOneIdentityPerIssuer(): void
    {
        $admin = Admin::query()->create(['email' => 'ops@pharmed.test']);
        $this->link($admin, 'sub-1');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->link($admin, 'sub-2');
    }

    public function testProfileIsNotOverwrittenWhenSyncIsOff(): void
    {
        $admin = Admin::query()->create(['email' => 'ops@pharmed.test', 'name' => 'Ops']);
        $this->link($admin, 'sub-admin');

        $this->signInAs('sub-admin', ['email' => 'someone-else@example.com', 'name' => 'Changed'])->assertRedirect('/dashboard');

        self::assertSame(['ops@pharmed.test', 'Ops'], [$admin->fresh()->email, $admin->fresh()->name]);
    }

    public function testIssuersAreStoredWithoutATrailingSlash(): void
    {
        // An operator pastes the issuer URL with a trailing slash: the link still matches.
        $admin = Admin::query()->create(['email' => 'ops@pharmed.test']);
        $this->link($admin, 'sub-admin', FakeIdp::ISSUER . '/');

        self::assertSame(FakeIdp::ISSUER, $admin->oidcIdentities()->sole()->issuer);
        $this->signInAs('sub-admin')->assertRedirect('/dashboard');
    }

    public function testTheAccountOfALinkCannotBeMassAssigned(): void
    {
        $identity = (new OidcIdentity())->fill([
            'issuer' => FakeIdp::ISSUER, 'subject' => 's', 'authenticatable_type' => Admin::class, 'authenticatable_id' => 1,
        ]);

        self::assertNull($identity->authenticatable_type);
        self::assertNull($identity->authenticatable_id);
    }

    public function testLogoutUsesTheConfiguredGuard(): void
    {
        $admin = Admin::query()->create(['email' => 'ops@pharmed.test']);
        $this->link($admin, 'sub-admin');
        $this->signInAs('sub-admin');
        self::assertTrue(Auth::guard('admin')->check());

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/auth/widthx/logout')->assertRedirect('/');

        self::assertFalse(Auth::guard('admin')->check());
    }
}
