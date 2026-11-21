<?php

namespace App\Http\Middleware;

use App\Support\RoleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMenuAccess
{
    public function handle(Request $request, Closure $next, string ...$menus): Response
    {
        abort_unless(collect($menus)->contains(fn ($menu) => RoleAccess::allowsMenu($request->user(), $menu)), 403);

        return $next($request);
    }
}
