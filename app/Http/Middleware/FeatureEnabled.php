<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\PosFeatureService;
use Closure;
use Illuminate\Http\Request;

/**
 * Parameterized per-feature gate: ->middleware('feature:tables') or
 * ->middleware('feature:khata_enabled').
 *
 * Routed through THE availability predicate (Task 1582): a module flag reads
 * the resolved feature map (masked for plan AND category relevance), a plan
 * gate reads planAllows() (relevance-aware). A module that does not belong to
 * the shop's business category is therefore unreachable by URL with the same
 * friendly outcome a switched-off module gets — never a locked upsell.
 */
class FeatureEnabled
{
    public function handle(Request $request, Closure $next, string $flag)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!PosFeatureService::moduleAvailable($company, $flag)) {
            $notForCategory = $company && !PosFeatureService::moduleRelevant($company, $flag);
            $message = $notForCategory
                ? __('pos.feature_not_for_business')
                : 'This feature is not enabled. Turn it on from Customize POS → Modules.';
            if ($request->expectsJson()) {
                return response()->json(['error' => $notForCategory ? $message : 'This feature is not enabled for your business.'], 403);
            }
            $home = $request->is('fbr-pos/*') ? '/fbr-pos/dashboard' : '/pos/dashboard';
            return redirect($home)->with('error', $message);
        }

        return $next($request);
    }
}
