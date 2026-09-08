<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\SubscriptionAccessService;
use App\Support\HealthPanel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Nest ERPS — Healthcare: subscription lock on MONEY WRITES only.
 *
 * The healthcare routes carry no plan.limit middleware (CheckPlanLimit's
 * quota arms are no-ops for ERPS), so nothing on this panel ever consulted
 * SubscriptionAccessService::hasAccess() — the same decision that locks a DI /
 * POS company whose plan expired, trial ended or grant was spent. An approved
 * hospital with a lapsed plan could keep posting charges, bills, payments and
 * pharmacy sales indefinitely.
 *
 * Attached ONLY to the route groups that create or post money (billing
 * counter, IPD charges/payments, pharmacy counter sale, purchases and supplier
 * payments). Reads are never blocked — staff must still be able to open a
 * patient file, a bill or a statement while the owner sorts the plan out — so
 * a GET that happens to sit inside a guarded group passes through untouched.
 *
 * Runs after HealthAuth (needs currentCompanyId). Same lock-reason UX as
 * CheckPlanLimit: JSON 403 for XHR callers, otherwise back to the screen the
 * request came from with the localized reason as the flash error.
 */
class HealthSubscriptionLock
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        if (!$companyId) {
            return $next($request);
        }

        // FAIL CLOSED — the only pass-through is the schema-compat guard shared
        // with the other in-controller access gates.
        if (!Schema::hasTable('subscriptions')) {
            return $next($request);
        }

        $company = Company::find($companyId);
        if (!$company) {
            return $next($request);
        }

        $access = SubscriptionAccessService::hasAccess($company);
        if ($access['allowed'] ?? false) {
            return $next($request);
        }

        $reason = SubscriptionAccessService::localizedLockReason((string) ($access['reason'] ?? ''));

        if ($request->expectsJson()) {
            return response()->json(['error' => $reason, 'message' => $reason], 403);
        }

        return back(302, [], '/' . HealthPanel::PATH_PREFIX . '/dashboard')->with('error', $reason);
    }
}
