<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\PosFeatureService;
use Closure;
use Illuminate\Http\Request;

/**
 * Parameterized per-feature gate: ->middleware('feature:tables').
 * Checks the company's resolved POS feature flags.
 */
class FeatureEnabled
{
    public function handle(Request $request, Closure $next, string $flag)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $features = PosFeatureService::forCompany($company);

        if (empty($features->{$flag})) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'This feature is not enabled for your business.'], 403);
            }
            return redirect('/pos/dashboard')->with('error', 'This feature is not enabled. Turn it on from Customize POS → Modules.');
        }

        return $next($request);
    }
}
