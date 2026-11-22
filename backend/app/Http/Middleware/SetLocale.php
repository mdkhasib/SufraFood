<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $lang = $request->header('X-Lang') ?: ($request->hasSession() ? $request->session()->get('lang') : null) ?: $request->user()?->language;
        if (in_array($lang, ['en', 'bn'])) {
            app()->setLocale($lang);
        }
        if ($u = $request->user()) {
            if (! $u->last_seen_at || $u->last_seen_at->lt(now()->subMinutes(5))) {
                $u->forceFill(['last_seen_at' => now()])->saveQuietly();
            }
        }

        return $next($request);
    }
}
