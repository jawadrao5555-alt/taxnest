<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FbrPosAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::guard('fbrpos')->check()) {
            return redirect('/fbr-pos/login');
        }

        $user = Auth::guard('fbrpos')->user();

        if (!$user->company_id) {
            Auth::guard('fbrpos')->logout();
            return redirect('/fbr-pos/login')->with('error', 'No company associated with your account.');
        }

        $company = \App\Models\Company::find($user->company_id);
        if (!$company || !$company->fbr_pos_enabled) {
            Auth::guard('fbrpos')->logout();
            return redirect('/fbr-pos/login')->with('error', 'FBR POS is not enabled for your company.');
        }

        app()->instance('currentCompanyId', $user->company_id);

        return $next($request);
    }
}
