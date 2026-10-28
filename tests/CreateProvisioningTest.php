<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Widthx\Auth\Testing\FakeIdp;
use Widthx\Laravel\OidcIdentity;
use Widthx\Laravel\Tests\Fixtures\User;

/** Defaults: self-service app, default guard, account created on first sign-in. */
final class CreateProvisioningTest extends TestCase
{
    public function testFirstSignInCreatesTheAccountAndItsIdentity(): void
    {
        $this->signInAs('sub-1')->assertRedirect('/dashboard');

        $user = User::query()->sole();
        self::assertSame(['ada@example.com', 'Ada'], [$user->email, $user->name]);
        self::assertNotSame('', $user->password, 'NOT NULL password column gets an unusable random hash');
        $identity = OidcIdentity::query()->sole();
        self::assertSame([FakeIdp::ISSUER, 'sub-1'], [$identity->issuer, $identity->subject]);
        self::assertTrue($identity->authenticatable->is($user));
        self::assertSame($user->id, Auth::guard()->id());
        self::assertSame(1, DB::table('oidc_session_links')->where('subject', 'sub-1')->count());
    }

    public function testReturningIdentitySignsIntoTheSameAccount(): void
    {
        $this->signInAs('sub-1');
        Auth::guard()->logout();

        $this->signInAs('sub-1')->assertRedirect('/dashboard');

        self::assertSame(1, User::query()->count());
        self::assertSame(1, OidcIdentity::query()->count());
    }

    public function testProfileIsSyncedOnEverySignInByDefault(): void
    {
        $user = User::query()->create(['email' => 'old@example.com', 'name' => 'Old', 'password' => 'x']);
        $this->link($user, 'sub-1');

        $this->signInAs('sub-1', ['email' => 'new@example.com', 'name' => 'New'])->assertRedirect('/dashboard');

        self::assertSame(['new@example.com', 'New'], [$user->fresh()->email, $user->fresh()->name]);
    }

    public function testAnUnlinkedAccountWithTheSameEmailIsNeitherTakenOverNorDuplicated(): void
    {
        // users.email is UNIQUE (as in Laravel's skeleton): no new account can be created, and the existing one is
        // never matched by email. The sign-in is refused cleanly instead of failing with a database error.
        $existing = User::query()->create(['email' => 'ada@example.com', 'name' => 'Existing, not linked', 'password' => 'x']);

        $this->signInAs('sub-1')->assertRedirect('/')->assertSessionHas('widthx_error', 'account_conflict');

        self::assertSame(1, User::query()->count());
        self::assertSame(0, OidcIdentity::query()->count());
        self::assertSame(0, $existing->oidcIdentities()->count());
        self::assertFalse(Auth::guard()->check());
    }

    public function testConcurrentFirstSignInsEndInTheSameAccount(): void
    {
        // Two callbacks for a new identity (double click, two tabs): the other one links its account between this
        // request's lookup and its insert. This request then signs in to that account instead of failing.
        $raced = false;
        DB::listen(function (QueryExecuted $q) use (&$raced) {
            if (!$raced && str_starts_with($q->sql, 'select') && str_contains($q->sql, 'oidc_identities')) {
                $raced = true;
                $winner = User::query()->create(['email' => 'ada@example.com', 'name' => 'Ada', 'password' => 'x']);
                $this->link($winner, 'sub-1');
            }
        });

        $this->signInAs('sub-1')->assertRedirect('/dashboard');

        self::assertTrue($raced);
        self::assertSame(1, User::query()->count());
        self::assertSame(User::query()->sole()->id, Auth::guard()->id());
    }

    public function testAProfileSyncThatWouldCollideKeepsTheLocalProfile(): void
    {
        $user = User::query()->create(['email' => 'ada@example.com', 'name' => 'Ada', 'password' => 'x']);
        User::query()->create(['email' => 'taken@example.com', 'name' => 'Someone else', 'password' => 'x']);
        $this->link($user, 'sub-1');

        $this->signInAs('sub-1', ['email' => 'taken@example.com', 'name' => 'Ada L.'])->assertRedirect('/dashboard');

        self::assertSame('ada@example.com', $user->fresh()->email);
        self::assertSame($user->id, Auth::guard()->id());
    }

    public function testDeletingAnAccountRemovesItsIdentities(): void
    {
        $this->signInAs('sub-1');
        Auth::guard()->logout();
        User::query()->sole()->delete();

        self::assertSame(0, OidcIdentity::query()->count());
        $this->signInAs('sub-1')->assertRedirect('/dashboard');
        self::assertSame(1, User::query()->count(), 'a fresh account for the returning identity');
    }

    public function testALinkWhoseAccountIsGoneIsRefused(): void
    {
        // The account row disappeared without the model (raw SQL, a soft-deleted account): never re-create it.
        $user = User::query()->create(['email' => 'ada@example.com', 'name' => 'Ada', 'password' => 'x']);
        $this->link($user, 'sub-1');
        DB::table('users')->delete();

        $this->signInAs('sub-1')->assertRedirect('/')->assertSessionHas('widthx_error', 'account_unavailable');

        self::assertSame(0, User::query()->count());
        self::assertFalse(Auth::guard()->check());
    }

    public function testAppTablesAreNotAltered(): void
    {
        self::assertSame(['id', 'name', 'email', 'password', 'remember_token', 'created_at', 'updated_at'],
            \Illuminate\Support\Facades\Schema::getColumnListing('users'));
    }
}
