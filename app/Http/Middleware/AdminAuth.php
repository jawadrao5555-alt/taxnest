<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth('admin')->check()) {
            return redirect()->guest('/admin/login');
        }

        // Exiting a delegated session must remain reachable even without its factor.
        if ($request->routeIs('saas.admin.impersonation.stop')) {
            return $next($request);
        }
        return \App\Services\TwoStepLogin::challenge($request, 'admin') ?? $next($request);
    }
}
