<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\Invoice;
use Carbon\Carbon;

/**
 * Decides whether a company is allowed to perform billable actions
 * (creating invoices, products, users, terminals).
 *
 * Logic order (first match wins):
 *   1. override_type = lifetime          -> ALLOW
 *   2. override_type = usage_free        -> ALLOW until invoice_count >= free_invoice_limit
 *   3. override_type = temporary | grace -> ALLOW while now < override_until
 *   4. ELSE                              -> normal subscription check (active + not expired)
 *
 * Returns: ['allowed' => bool, 'reason' => string, 'override' => string|null]
 */
class SubscriptionAccessService
{
    public static function hasAccess(Company $company): array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('id')
            ->first();

        // No subscription at all — fail closed (matches existing onboarding flow that requires a plan)
        if (!$subscription) {
            return ['allowed' => false, 'reason' => 'No active subscription. Please subscribe to a plan.', 'override' => null];
        }

        $type = $subscription->override_type ?? 'none';

        // 1. Lifetime
        if ($type === 'lifetime') {
            return ['allowed' => true, 'reason' => 'Lifetime free access.', 'override' => 'lifetime'];
        }

        // 2. Usage-free (cap by invoice count; NULL limit = unlimited free invoices)
        if ($type === 'usage_free') {
            if ($subscription->free_invoice_limit === null) {
                return ['allowed' => true, 'reason' => 'Unlimited free invoices.', 'override' => 'usage_free'];
            }
            $limit = (int) $subscription->free_invoice_limit;
            if ($limit <= 0) {
                return ['allowed' => false, 'reason' => 'Free invoice limit not configured.', 'override' => 'usage_free'];
            }
            $count = self::billableCount($company);
            if ($count >= $limit) {
                return [
                    'allowed' => false,
                    'reason' => "Free invoice limit reached ({$count}/{$limit}). Please upgrade your plan.",
                    'override' => 'usage_free',
                ];
            }
            return [
                'allowed' => true,
                'reason' => "Usage-free: {$count}/{$limit} invoices used.",
                'override' => 'usage_free',
            ];
        }

        // 3. Temporary / grace
        if (in_array($type, ['temporary', 'grace'], true)) {
            $until = $subscription->override_until ? Carbon::parse($subscription->override_until) : null;
            if ($until && $until->isFuture()) {
                // Optional invoice allowance on temporary grants: only bills created
                // AFTER the grant count, so old history never eats the allowance.
                if ($subscription->free_invoice_limit !== null) {
                    $limit = (int) $subscription->free_invoice_limit;
                    $used = self::billableCount($company, $subscription->override_granted_at);
                    if ($limit > 0 && $used >= $limit) {
                        return [
                            'allowed' => false,
                            'reason' => "Temporary invoice allowance reached ({$used}/{$limit}). Please subscribe to a plan.",
                            'override' => $type,
                        ];
                    }
                    return [
                        'allowed' => true,
                        'reason' => ucfirst($type) . " access until {$until->format('Y-m-d')} ({$used}/{$limit} invoices used).",
                        'override' => $type,
                    ];
                }
                return [
                    'allowed' => true,
                    'reason' => ucfirst($type) . " access until {$until->format('Y-m-d')}.",
                    'override' => $type,
                ];
            }
            // Override expired — fall through to normal subscription check below
        }

        // 4. Normal subscription check
        if (!$subscription->active) {
            return ['allowed' => false, 'reason' => 'Your subscription is inactive. Contact admin.', 'override' => null];
        }

        if ($subscription->end_date && Carbon::parse($subscription->end_date)->isPast()) {
            return ['allowed' => false, 'reason' => 'Your plan has expired. Contact admin.', 'override' => null];
        }

        if ($subscription->isTrialExpired()) {
            return ['allowed' => false, 'reason' => 'Your free trial has expired. Please subscribe to a plan.', 'override' => null];
        }

        // Free-trial invoice cap (3-day OR 20-invoice — whichever comes first).
        // Applies to DI / PRA POS / FBR POS trial subscriptions uniformly.
        $plan = $subscription->pricingPlan ?? $subscription->loadMissing('pricingPlan')->pricingPlan;
        if ($plan && $plan->is_trial) {
            $limit = (int) ($plan->invoice_limit ?? 0);
            if ($limit > 0) {
                $count = self::billableCount($company);
                if ($count >= $limit) {
                    return [
                        'allowed' => false,
                        'reason' => "Free trial invoice limit reached ({$count}/{$limit}). Please subscribe to a plan.",
                        'override' => null,
                    ];
                }
            }
        }

        return ['allowed' => true, 'reason' => 'Active subscription.', 'override' => null];
    }

    /**
     * Lightweight trial summary used by the in-app reminder banner and the
     * email reminder command. Returns null when the company is NOT on an
     * active, still-allowed trial (locked / paid companies are handled by the
     * lock modal instead).
     *
     * @return array{on_trial: bool, days_left: ?int, invoices_left: ?int}|null
     */
    public static function trialStatus(Company $company): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('id')
            ->first();

        if (!$subscription) {
            return null;
        }

        $plan = $subscription->pricingPlan ?? $subscription->loadMissing('pricingPlan')->pricingPlan;
        if (!$plan || !$plan->is_trial) {
            return null;
        }

        // An active admin override (lifetime / temporary / legacy grant) supersedes
        // the trial — no trial-ending banners or reminder emails while it's in force.
        if ($subscription->hasActiveOverride()) {
            return null;
        }

        // Already blocked? The lock modal owns the messaging — skip the reminder.
        $access = self::hasAccess($company);
        if (!($access['allowed'] ?? false)) {
            return null;
        }

        $daysLeft = null;
        if ($subscription->trial_ends_at) {
            // Calendar-day diff (same formula as paidEndingReminder):
            // expiring later TODAY = 0 days left → banner says "ends today".
            $daysLeft = $subscription->trial_ends_at->isFuture()
                ? (int) now()->startOfDay()->diffInDays($subscription->trial_ends_at->copy()->startOfDay())
                : 0;
        }

        $invoicesLeft = null;
        $limit = (int) ($plan->invoice_limit ?? 0);
        if ($limit > 0) {
            $invoicesLeft = max(0, $limit - self::billableCount($company));
        }

        return [
            'on_trial' => true,
            'days_left' => $daysLeft,
            'invoices_left' => $invoicesLeft,
        ];
    }

    /**
     * Reminder data for an active TEMPORARY (or legacy grace) override:
     * how long the granted free access lasts and how many invoices remain.
     * Lifetime overrides return null (no banner — permanent access).
     * Returns null once hasAccess() denies (the lock modal owns messaging then).
     */
    /**
     * Paid (non-trial, non-override) subscription ending within 2 days.
     * Owner request (1 Aug 2026): warn 2 days ahead so shops renew in time.
     * Returns null unless an active standard subscription's end_date is
     * today..+2 days.
     */
    public static function paidEndingReminder(Company $company): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('id')
            ->first();

        if (!$subscription
            || $subscription->override_type !== 'none'
            || !$subscription->end_date
            || !$subscription->pricingPlan
            || $subscription->pricingPlan->is_trial) {
            return null;
        }

        $end = Carbon::parse($subscription->end_date)->endOfDay();
        if ($end->isPast()) {
            return null;
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay());
        if ($daysLeft > 2) {
            return null;
        }

        return [
            'until' => $end->format('Y-m-d'),
            'days_left' => $daysLeft,
        ];
    }

    public static function overrideReminder(Company $company): ?array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('id')
            ->first();

        if (!$subscription || !in_array($subscription->override_type, ['temporary', 'grace'], true)) {
            return null;
        }

        $until = $subscription->override_until;
        if (!$until || !$until->isFuture()) {
            return null;
        }

        $access = self::hasAccess($company);
        if (!($access['allowed'] ?? false)) {
            return null;
        }

        // Calendar-day diff (same formula as paidEndingReminder):
        // expiring later TODAY = 0 days left → banner says "ends today".
        $daysLeft = (int) now()->startOfDay()->diffInDays($until->copy()->startOfDay());

        $invoicesLeft = null;
        if ($subscription->free_invoice_limit !== null) {
            $invoicesLeft = max(0, (int) $subscription->free_invoice_limit - self::billableCount($company, $subscription->override_granted_at));
        }

        return [
            'until' => $until->format('Y-m-d'),
            'days_left' => $daysLeft,
            'invoices_left' => $invoicesLeft,
        ];
    }

    /**
     * Count a company's billable documents by product type.
     * DI uses the invoices table; PRA POS uses pos_transactions;
     * FBR POS uses its own fbr_pos_transactions table.
     */
    protected static function billableCount(Company $company, $since = null): int
    {
        // FBR POS bills live in their own table (fbr_pos_transactions).
        if ($company->product_type === 'fbrpos') {
            return \App\Models\FbrPosTransaction::where('company_id', $company->id)
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->count();
        }

        // PRA POS bills live in pos_transactions (archived rows hidden by a global scope).
        if ($company->product_type === 'pos') {
            return \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $company->id)
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->count();
        }

        // Digital Invoice uses the invoices table.
        return Invoice::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->where('company_id', $company->id)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->count();
    }

    /**
     * Grant-status reconciliation. When a DATE-based grant (temporary / grace)
     * has passed its override_until, the company that was unlocked by that grant
     * must fall back to 'pending' (view-only) — mirroring a freshly registered
     * company — and the spent grant is cleared so it can never re-trigger nor
     * fight a later manual re-approval.
     *
     * NOT touched here:
     *   - lifetime  : never expires.
     *   - usage_free: capped by invoice count (handled in hasAccess), not by date.
     *   - suspended / rejected companies: left exactly as an admin set them.
     *   - companies that have SINCE gained valid access (paid plan / lifetime):
     *     confirmed via hasAccess() so a paying customer is never locked out.
     *
     * Idempotent — safe to call on every admin list load and from the daily job.
     *
     * @return int number of companies flipped back to pending
     */
    public static function reconcileExpiredGrants(): int
    {
        // Every subscription whose DATE-based grant (temporary / grace) has lapsed.
        $expired = fn () => Subscription::whereIn('override_type', ['temporary', 'grace'])
            ->whereNotNull('override_until')
            ->where('override_until', '<', now());

        $companyIds = $expired()->where('active', true)->distinct()->pluck('company_id');

        $flipped = 0;
        if ($companyIds->isNotEmpty()) {
            $companies = Company::whereIn('id', $companyIds)
                ->where('company_status', 'active')
                ->whereNotIn('status', ['suspended', 'rejected'])
                ->get();

            foreach ($companies as $company) {
                // A company that has SINCE gained valid access (paid plan, active
                // trial, lifetime) keeps working — only lock those whose effective
                // access is now gone.
                if (self::hasAccess($company)['allowed'] ?? false) {
                    continue;
                }
                $company->update(['status' => 'pending', 'company_status' => 'pending']);
                $flipped++;
            }
        }

        // Clear EVERY spent grant — flipped, still-valid, or suspended alike — so
        // it is never re-scanned and a stale expired grant can never silently
        // demote a company whose paid plan lapses later. hasAccess() already
        // treats an expired temporary/grace grant identically to 'none', so this
        // clears no access that still mattered.
        $expired()->update(['override_type' => 'none', 'override_until' => null, 'override_granted_at' => null, 'free_invoice_limit' => null]);

        return $flipped;
    }
}
