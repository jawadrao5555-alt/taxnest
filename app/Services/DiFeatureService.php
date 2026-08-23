<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;

/**
 * Digital Invoice plan feature gates (Aug 2026 — Premium tier foundation).
 *
 * DI historically only had LIMITS (invoice/user/branch). This service adds
 * POS-style HARD feature gates for the premium feature set that ships with
 * the Rs 12,999/mo Premium plan. Later premium tasks (white-label, public
 * API, AI reader, recurring invoices) must ONLY READ these gates via
 * planAllows() — the matrix below is the single source of truth and is not
 * edited by feature tasks.
 *
 * Resolution order (mirrors PosFeatureService::planAllows, with one DI
 * difference — override grants do NOT unlock everything, they unlock the
 * plan attached to the grant row):
 *   1. Unknown key            -> true  (not a gate, nothing to enforce)
 *   2. POS / FBR POS company  -> false (DI-only features)
 *   3. Internal account       -> true
 *   4. SubscriptionAccessService::hasAccess denies -> false
 *      (expired/locked companies lose premium features with their access)
 *   5. EFFECTIVE plan's matrix row decides — for override grants
 *      (lifetime/temporary) that is the plan attached to the granted
 *      subscription row ("granted plan ke mutabiq").
 *   6. Active trial           -> true (evaluate-before-buying, same owner
 *      rule as the POS Restaurant module, Jul 2026)
 */
class DiFeatureService
{
    /**
     * The four gate keys defined by Task 135. Later tasks read these —
     * never rename them.
     */
    public const GATES = ['white_label', 'public_api', 'ai_reader', 'recurring_invoices'];

    /**
     * Central plan -> features matrix for DI plans (matched by plan name,
     * product_type 'di'). Plans not listed here (e.g. self-built
     * "Custom Plan" rows) get NO premium features — fail closed.
     */
    public const PLAN_FEATURES = [
        // Sep 2026 restructure — the three packages that are actually sold.
        // AI Reader is now part of EVERY paid package (each carries its own
        // monthly page allowance); recurring_invoices is deliberately absent
        // because the feature has no implementation yet and must not be sold.
        'Asaan'      => ['ai_reader'],
        'Kaarobar'   => ['ai_reader', 'white_label', 'public_api'],
        'Unlimited'  => ['ai_reader', 'white_label', 'public_api'],

        // Legacy rows — retired from sale, kept for existing subscriptions.
        'Trial'      => [],
        'Retail'     => [],
        'Business'   => ['recurring_invoices'],
        'Industrial' => ['recurring_invoices'],
        'Enterprise' => ['recurring_invoices'],
        'Premium'    => ['recurring_invoices', 'white_label', 'public_api', 'ai_reader'],
    ];

    /** Per-request cache: company_id => [feature => bool] */
    protected static array $gateCache = [];

    /**
     * Does this DI company's effective plan include the given premium feature?
     */
    public static function planAllows(?Company $company, string $feature): bool
    {
        if (!in_array($feature, self::GATES, true)) {
            return true; // not a defined gate — nothing to enforce
        }
        if (!$company) {
            return false;
        }
        if (isset(self::$gateCache[$company->id][$feature])) {
            return self::$gateCache[$company->id][$feature];
        }

        return self::$gateCache[$company->id][$feature] = self::resolve($company, $feature);
    }

    /**
     * Does a specific plan row include the feature? (Matrix lookup only —
     * no company/override/trial logic. Used by billing/pricing surfaces.)
     */
    public static function planIncludes(?PricingPlan $plan, string $feature): bool
    {
        if (!$plan) {
            return false;
        }
        // Same product split billableCount uses: only DI plans (or legacy
        // NULL product_type rows, which are DI) can open DI gates.
        if ($plan->product_type && $plan->product_type !== 'di') {
            return false;
        }

        return in_array($feature, self::PLAN_FEATURES[$plan->name] ?? [], true);
    }

    /**
     * The subscription row whose plan decides the gates. Uses the exact same
     * row choice as SubscriptionAccessService::hasAccess (active, newest id)
     * so "am I allowed" and "which plan am I on" can never disagree.
     * pricingPlan is eager-loaded (prod runs strict lazy-loading).
     */
    public static function effectiveSubscription(Company $company): ?Subscription
    {
        return Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('id')
            ->with('pricingPlan')
            ->first();
    }

    /** Clear the per-request cache (tests / admin plan flips mid-request). */
    public static function flushGateCaches(): void
    {
        self::$gateCache = [];
    }

    protected static function resolve(Company $company, string $feature): bool
    {
        // DI gates never open for POS / FBR POS companies.
        if (in_array($company->product_type, ['pos', 'fbrpos'], true)) {
            return false;
        }

        if ($company->is_internal_account) {
            return true;
        }

        // The company must currently be allowed to work at all —
        // SubscriptionAccessService owns override/expiry/lock semantics.
        if (!(SubscriptionAccessService::hasAccess($company)['allowed'] ?? false)) {
            return false;
        }

        $subscription = self::effectiveSubscription($company);
        if (!$subscription) {
            return false;
        }

        // EFFECTIVE plan decides. Override grants (lifetime/temporary) carry
        // their granted plan on the same subscription row — a Business-plan
        // lifetime grant gets Business features, not everything. A bare
        // carrier row (no plan) grants base access only, no premium gates.
        if (self::planIncludes($subscription->pricingPlan, $feature)) {
            return true;
        }

        // Active-trial companies evaluate everything (owner rule, POS parity).
        if ($subscription->isTrialActive()) {
            return true;
        }

        return false;
    }
}
