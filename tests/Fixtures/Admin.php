<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Widthx\Laravel\HasOidcIdentities;

/** A second, separate account table with its own guard (e.g. an admin console next to the app's users). */
final class Admin extends Authenticatable
{
    use HasOidcIdentities;

    protected $table = 'admins';
    protected $guarded = [];
}
