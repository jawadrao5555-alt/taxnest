<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Company;

class CheckCompanyApproval
{
    public function handle(Request $request, Closure $next)
    {
        $companyId = app('currentCompanyId');
        if (!$companyId) {
            return $next($request);
        }

        $company = Company::find($companyId);
        if (!$company) {
            return $next($request);
        }

        if ($company->status === 'suspended') {
            return response()->view('errors.company-suspended', [], 403);
        }

        if ($company->status === 'rejected') {
            return response()->view('errors.company-rejected', [], 403);
        }

        if ($company->status === 'pending') {
            view()->share('companyPendingApproval', true);

            if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json([
                        'error' => 'Your account is pending admin approval. Actions are disabled until approved.'
                    ], 403);
                }

                return redirect()->back()->with('error', 'Your account is pending admin approval. You can view features but cannot perform actions until approved.');
            }
        }

        return $next($request);
    }
}
