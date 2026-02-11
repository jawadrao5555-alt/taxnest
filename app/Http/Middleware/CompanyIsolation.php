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
                $companyId = $request->query('company_id', $user->company_id);
                if ($companyId) {
                    app()->instance('currentCompanyId', (int) $companyId);
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
            if ($company && $company->suspended_at) {
                auth()->logout();
                return redirect('/login')->with('error', 'Your company has been suspended. Please contact support.');
            }

            app()->instance('currentCompanyId', $companyId);
        }

        return $next($request);
    }
}
