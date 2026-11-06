<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        $guard = auth('admin');
        if (!$guard->check()) {
            return redirect()->guest('/admin/login');
        }

        $version = $guard->user()->recovery_session_version;
        if ($version && $version !== $request->session()->get('admin.recovery_version')) {
            if (!$guard->viaRemember()) {
                $guard->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->guest('/admin/login');
            }
            $request->session()->put('admin.recovery_version', $version);
        }

        return $next($request);
    }
}
