<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\PosTerminal;
use App\Models\User;
use App\Models\Product;

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

        switch ($resource) {
            case 'terminals':
                if ($plan->max_terminals !== null) {
                    $current = PosTerminal::where('company_id', $companyId)->where('is_active', true)->count();
                    if ($current >= $plan->max_terminals) {
                        $exceeded = true;
                        $limitName = "terminals (max: {$plan->max_terminals})";
                    }
                }
                break;
            case 'users':
                if ($plan->max_users !== null) {
                    $current = User::where('company_id', $companyId)->count();
                    if ($current >= $plan->max_users) {
                        $exceeded = true;
                        $limitName = "users (max: {$plan->max_users})";
                    }
                }
                break;
            case 'products':
                if ($plan->max_products !== null) {
                    $current = Product::where('company_id', $companyId)->where('is_active', true)->count();
                    if ($current >= $plan->max_products) {
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
