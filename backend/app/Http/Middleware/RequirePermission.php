<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** perm:a|b  → user needs any of the listed permissions (feature + role gates). */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $perms)
    {
        $u = $request->user();
        if (! $u || ! $u->hasAnyPerm(explode('|', $perms))) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'This feature is not available for you.'], 403);
            }
            abort(403, 'This feature is not available for you.');
        }

        return $next($request);
    }
}
