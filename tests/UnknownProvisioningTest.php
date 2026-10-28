<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests;

use Illuminate\Support\Facades\Auth;
use Widthx\Laravel\OidcIdentity;
use Widthx\Laravel\Tests\Fixtures\User;

/** A mistyped provisioning mode must fail closed, never fall back to creating accounts. */
final class UnknownProvisioningTest extends TestCase
{
    protected function widthxConfig(): array
    {
        return ['provisioning' => 'linkk'];
    }

    public function testUnknownModeRefusesUnlinkedIdentities(): void
    {
        $this->signInAs('sub-1')->assertSessionHas('widthx_error', 'not_linked');

        self::assertSame(0, User::query()->count());
        self::assertSame(0, OidcIdentity::query()->count());
        self::assertFalse(Auth::guard()->check());
    }
}
