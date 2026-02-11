<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompanyIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $user = auth()->user();

            if (!$user->is_active) {
                auth()->logout();
                return redirect('/login')->with('error', 'Your account has been deactivated.');
            }

            if ($user->role === 'super_admin') {
                if ($user->company_id) {
                    app()->instance('currentCompanyId', $user->company_id);
                }
                return $next($request);
            }

            $companyId = $user->company_id;

            if (!$companyId) {
                if ($request->is('billing/*') || $request->is('billing')) {
                    return $next($request);
                }
                return redirect('/billing/plans')->with('error', 'Please set up your company first.');
            }

            $company = \App\Models\Company::find($companyId);
            if ($company && $company->company_status !== 'active') {
                auth()->logout();
                if ($company->company_status === 'pending') {
                    return redirect('/login')->with('error', 'Your company registration is pending approval.');
                }
                if ($company->company_status === 'suspended') {
                    return redirect('/login')->with('error', 'Your company has been suspended.');
                }
                if ($company->company_status === 'rejected') {
                    return redirect('/login')->with('error', 'Your company registration was rejected.');
                }
                return redirect('/login')->with('error', 'Your company account is not active.');
            }

            app()->instance('currentCompanyId', $companyId);
        }

        return $next($request);
    }
}
