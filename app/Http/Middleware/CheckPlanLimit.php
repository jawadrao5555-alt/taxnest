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
use App\Services\PlanLimitService;

class CheckPlanLimit
{
    /**
     * Per-process memo for the fbr_pos_transactions.offline_uuid column probe
     * (schema-drift guard on the hot sale path — the column can only ever
     * APPEAR, and deploys restart PHP, so caching false is safe too).
     */
    protected static ?bool $fbrOfflineUuidColumn = null;

    protected static function fbrOfflineUuidColumnExists(): bool
    {
        return self::$fbrOfflineUuidColumn ??= \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'offline_uuid');
    }

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
        $subscription = null;
        if ($company) {
            $access = SubscriptionAccessService::hasAccess($company);
            if (!$access['allowed']) {
                $reason = SubscriptionAccessService::localizedLockReason($access['reason']);
                if ($request->expectsJson()) {
                    return response()->json(['error' => $reason], 403);
                }
                return back()->with('error', $reason);
            }

            // A grant waives payment, not the package it runs on. Only the
            // legacy escape hatch (a Trial or plan-less carrier row) remains
            // fully open because there is no real package whose caps can apply.
            $subscription = PlanLimitService::getActiveSubscription($companyId);
            if (PlanLimitService::grantWaivesPackageLimits($subscription)) {
                return $next($request);
            }
        }

        $subscription ??= Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        if (!$subscription || !$subscription->pricingPlan) {
            return $next($request);
        }

        $plan = $subscription->pricingPlan;
        $exceeded = false;
        $limitName = '';
        $customReason = null;
        // Localized per-resource fallback (task 498): pos.tl_reason_{key}_cap
        // with :max substituted — English $limitName kept only as last resort.
        $limitKey = null;
        $limitMax = null;

        // Limit convention (matches PlanLimitService + the admin plan builder,
        // which validates min:-1): NULL or any negative value = UNLIMITED.
        // Only a limit >= 0 is a real cap — treating -1 as a cap blocked Pro
        // plan companies (max_products = -1) from creating any products.
        switch ($resource) {
            case 'terminals':
                if ($plan->max_terminals !== null && (int) $plan->max_terminals >= 0) {
                    // FBR POS counters live in fbr_pos_terminals — counting the
                    // PRA table here would let fbrpos companies add unlimited
                    // counters (their PosTerminal count is always 0).
                    $current = ($plan->product_type ?? null) === 'fbrpos'
                        ? \App\Models\FbrPosTerminal::where('company_id', $companyId)->where('is_active', true)->count()
                        : PosTerminal::where('company_id', $companyId)->where('is_active', true)->count();
                    if ($current >= (int) $plan->max_terminals) {
                        $exceeded = true;
                        $limitName = "terminals (max: {$plan->max_terminals})";
                        $limitKey = 'terminals';
                        $limitMax = (int) $plan->max_terminals;
                    }
                }
                break;
            case 'users':
                // NOTE (Task 1350): this arm guards exactly ONE route — the DI
                // panel's POST /company/users (web guard). It is NOT a POS team
                // -account gate and must not become one:
                //   • POS team pages call PlanLimitService::canAddPosUser()
                //     directly (user_limit, owner + confined roles exempt) and
                //     never pass through here.
                //   • max_users is this route's own column. Do not repoint this
                //     at user_limit and do not mirror user_limit into
                //     max_users — the POS seat count and this all-users count
                //     mean different things, so either move would silently
                //     tighten the DI cap.
                // scripts/plan-gate-check.php asserts no POS/FBR POS route ever
                // picks up plan.limit:users.
                if ($plan->max_users !== null && (int) $plan->max_users >= 0) {
                    $current = User::where('company_id', $companyId)->count();
                    if ($current >= (int) $plan->max_users) {
                        $exceeded = true;
                        $limitName = "users (max: {$plan->max_users})";
                        $limitKey = 'users';
                        $limitMax = (int) $plan->max_users;
                    }
                }
                break;
            case 'products':
                if ($plan->max_products !== null && (int) $plan->max_products >= 0) {
                    $current = Product::where('company_id', $companyId)->where('is_active', true)->count();
                    if ($current >= (int) $plan->max_products) {
                        $exceeded = true;
                        $limitName = "products (max: {$plan->max_products})";
                        $limitKey = 'products';
                        $limitMax = (int) $plan->max_products;
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
                        $limitKey = 'products';
                        $limitMax = (int) $plan->max_products;
                    }
                }
                break;
            case 'inventory':
                // DISABLE is always allowed: a downgraded company with the
                // inventory column still ON must be able to turn it OFF
                // (otherwise sales keep auto-deducting stock they can't manage).
                // Only requests that keep/turn the feature ON are gated.
                if ($request->has('enabled') && !$request->boolean('enabled')) {
                    break;
                }
                // Active trial evaluates everything (owner rule) — only paid
                // plans without the inventory feature are blocked.
                if (!$plan->inventory_enabled && !$plan->is_trial) {
                    $exceeded = true;
                    $limitName = 'inventory (not included in your plan)';
                    $limitKey = 'inventory';
                }
                break;
            case 'invoices':
                // Product-aware bill/invoice quota (strict plan mapping, Aug 2026):
                // fbrpos = monthly FBR POS final-bill quota; pos = NO middleware
                // check (PRA quota lives in-controller on the four FINAL paths
                // only — provisionals are quota-free there, a middleware block
                // here would wrongly stop provisional saves at quota-full);
                // di / legacy NULL product rows = lifetime DI invoice count.
                $productType = $plan->product_type ?? 'di';
                if ($productType === 'fbrpos') {
                    // REPLAY GUARD BEFORE QUOTA (offline-first invariant): a retry
                    // of an already-saved bill (same offline_uuid) must reach the
                    // controller's replay guard and get the saved bill back — never
                    // a quota error for a bill that already exists.
                    $offlineUuid = trim((string) $request->input('offline_uuid', ''));
                    if ($offlineUuid !== ''
                        && self::fbrOfflineUuidColumnExists()
                        && \App\Models\FbrPosTransaction::where('company_id', $companyId)->where('offline_uuid', $offlineUuid)->exists()) {
                        break;
                    }
                    $check = PlanLimitService::canCreateFbrPosBill($companyId);
                } elseif ($productType === 'pos') {
                    break; // PRA POS: in-controller quota only (provisional-aware)
                } else {
                    $check = PlanLimitService::canCreateInvoice($companyId);
                }
                if (!($check['allowed'] ?? true)) {
                    $exceeded = true;
                    $limitName = 'invoices';
                    $customReason = $check['reason'] ?? null;
                }
                break;
        }

        if ($exceeded) {
            if ($customReason) {
                $msg = SubscriptionAccessService::localizedLockReason($customReason);
            } elseif ($limitKey) {
                $msg = __("pos.tl_reason_{$limitKey}_cap", ['max' => $limitMax]);
            } else {
                $msg = "Plan limit exceeded for {$limitName}. Please upgrade your subscription.";
            }
            if ($request->expectsJson()) {
                return response()->json(['error' => $msg, 'message' => $msg], 403);
            }
            return back()->with('error', $msg);
        }

        return $next($request);
    }
}
