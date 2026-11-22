<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_super_admin, 403, 'Super Admin only.');

        return $next($request);
    }
}
