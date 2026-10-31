<?php

declare(strict_types=1);

namespace Widthx\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Widthx\Laravel\SiteRoles;

/** Route middleware: `->middleware('widthx.role:admin,manager')` passes when the account holds any of the roles. */
final class RequireWidthxRole
{
    public function __construct(private readonly SiteRoles $roles)
    {
    }

    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        abort_unless($this->roles->has(...$roles), 403);
        return $next($request);
    }
}
