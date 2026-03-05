<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::guard('pos')->check()) {
            return redirect('/pos/login');
        }

        $user = Auth::guard('pos')->user();

        if (!$user->is_active) {
            Auth::guard('pos')->logout();
            return redirect('/pos/login')->with('error', 'Your account has been deactivated.');
        }

        if (!$user->company_id) {
            Auth::guard('pos')->logout();
            return redirect('/pos/login')->with('error', 'No company associated with your account.');
        }

        app()->instance('currentCompanyId', $user->company_id);

        return $next($request);
    }
}
