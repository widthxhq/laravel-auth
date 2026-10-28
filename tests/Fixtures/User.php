<?php

declare(strict_types=1);

namespace Widthx\Laravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Widthx\Laravel\HasOidcIdentities;

final class User extends Authenticatable
{
    use HasOidcIdentities;

    protected $table = 'users';
    protected $guarded = [];
}
