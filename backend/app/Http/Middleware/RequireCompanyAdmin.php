<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireCompanyAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        abort_unless($u && ($u->is_super_admin || $u->is_company_admin), 403, 'Company Admin only.');

        return $next($request);
    }
}
