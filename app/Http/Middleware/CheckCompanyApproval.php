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

            // Onboarding skip/complete only flip a UI flag (onboarding_completed) —
            // they must stay allowed while pending, otherwise a new company is
            // dead-locked on /onboarding: dashboard force-redirects there until the
            // flag is set, but every onboarding action (including Skip) is a POST
            // and would be blocked, leaving the user unable to view anything else.
            if ($request->is('onboarding/*')) {
                return $next($request);
            }

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
