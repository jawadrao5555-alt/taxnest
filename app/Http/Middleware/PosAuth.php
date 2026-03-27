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

        $company = \App\Models\Company::find($user->company_id);
        if (!$company) {
            Auth::guard('pos')->logout();
            return redirect('/pos/login')->with('error', 'Company not found. Please contact admin.');
        }

        if ($company->product_type !== 'pos') {
            Auth::guard('pos')->logout();
            if ($company->product_type === 'fbrpos') {
                return redirect('/fbr-pos/login')->with('error', 'This is an FBR POS account. Please login from the FBR POS portal.');
            }
            return redirect('/login')->with('error', 'This account is not registered for NestPOS.');
        }

        app()->instance('currentCompanyId', $user->company_id);

        return $next($request);
    }
}
