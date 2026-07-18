<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\PosTerminal;
use App\Models\User;
use App\Models\Product;
use App\Services\SubscriptionAccessService;

class CheckPlanLimit
{
    public function handle(Request $request, Closure $next, string $resource = '')
    {
        if (empty($resource)) {
            return $next($request);
        }

        $companyId = app('currentCompanyId');
        if (!$companyId) {
            return $next($request);
        }

        // Step 1: Override + access gate — runs BEFORE per-resource limit check.
        $company = Company::find($companyId);
        if ($company) {
            $access = SubscriptionAccessService::hasAccess($company);
            if (!$access['allowed']) {
                if ($request->expectsJson()) {
                    return response()->json(['error' => $access['reason']], 403);
                }
                return back()->with('error', $access['reason']);
            }

            // Lifetime + active temporary/grace bypass per-resource limits entirely.
            if (in_array($access['override'], ['lifetime', 'temporary', 'grace'], true)) {
                return $next($request);
            }
            // usage_free continues to the per-resource check below (no plan caps apply,
            // but we still want products/users/terminals counts capped by plan if set).
        }

        $subscription = Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        if (!$subscription || !$subscription->pricingPlan) {
            return $next($request);
        }

        $plan = $subscription->pricingPlan;
        $exceeded = false;
        $limitName = '';

        // Limit convention (matches PlanLimitService + the admin plan builder,
        // which validates min:-1): NULL or any negative value = UNLIMITED.
        // Only a limit >= 0 is a real cap — treating -1 as a cap blocked Pro
        // plan companies (max_products = -1) from creating any products.
        switch ($resource) {
            case 'terminals':
                if ($plan->max_terminals !== null && (int) $plan->max_terminals >= 0) {
                    $current = PosTerminal::where('company_id', $companyId)->where('is_active', true)->count();
                    if ($current >= (int) $plan->max_terminals) {
                        $exceeded = true;
                        $limitName = "terminals (max: {$plan->max_terminals})";
                    }
                }
                break;
            case 'users':
                if ($plan->max_users !== null && (int) $plan->max_users >= 0) {
                    $current = User::where('company_id', $companyId)->count();
                    if ($current >= (int) $plan->max_users) {
                        $exceeded = true;
                        $limitName = "users (max: {$plan->max_users})";
                    }
                }
                break;
            case 'products':
                if ($plan->max_products !== null && (int) $plan->max_products >= 0) {
                    $current = Product::where('company_id', $companyId)->where('is_active', true)->count();
                    if ($current >= (int) $plan->max_products) {
                        $exceeded = true;
                        $limitName = "products (max: {$plan->max_products})";
                    }
                }
                break;
            case 'pos_products':
                // POS catalog lives in pos_products — counting the DI `products`
                // table here meant capped POS plans were never actually enforced.
                if ($plan->max_products !== null && (int) $plan->max_products >= 0) {
                    $current = \App\Models\PosProduct::where('company_id', $companyId)->where('is_active', true)->count();
                    if ($current >= (int) $plan->max_products) {
                        $exceeded = true;
                        $limitName = "products (max: {$plan->max_products})";
                    }
                }
                break;
            case 'inventory':
                if (!$plan->inventory_enabled) {
                    $exceeded = true;
                    $limitName = 'inventory (not included in your plan)';
                }
                break;
        }

        if ($exceeded) {
            if ($request->expectsJson()) {
                return response()->json(['error' => "Plan limit exceeded for {$limitName}. Please upgrade your subscription."], 403);
            }
            return back()->with('error', "Plan limit exceeded for {$limitName}. Please upgrade your subscription.");
        }

        return $next($request);
    }
}
