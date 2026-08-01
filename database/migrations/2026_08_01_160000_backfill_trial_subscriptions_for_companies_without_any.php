<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill (Aug 2026): every company created BEFORE the
 * TrialSubscriptionService::ensureTrial wiring may still have ZERO
 * subscription rows. Those companies fail closed in hasAccess() and look
 * "locked" without ever having been shown a trial.
 *
 * This attaches the standard trial row to every such company:
 *  - trial window = company.created_at + 3 days (same as signup trials), so
 *    recently-created companies get whatever remains of a real trial while
 *    old companies get a bounded, ALREADY-EXPIRED trial row (owner's
 *    preference) — they see "trial expired, please subscribe" instead of a
 *    bare lock with no explanation.
 *  - plan = the product's own trial plan when one exists, falling back to any
 *    trial plan, falling back to a plan-less row carrying trial_ends_at
 *    (SubscriptionAccessService treats that as a bounded trial, not a bare
 *    plan-less grant).
 *
 * Idempotent + PROD-safe (migrate --force):
 *  - only companies with NO subscription row of any kind are touched;
 *  - soft-deleted companies and internal accounts are skipped;
 *  - a re-run finds no subscription-less companies and inserts nothing.
 *
 * down() is a no-op: we cannot distinguish backfilled rows from organic ones
 * safely once overrides/payments may have ridden on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $trialPlans = DB::table('pricing_plans')
            ->where('is_trial', true)
            ->orderBy('id')
            ->get()
            ->keyBy('product_type');
        $fallbackTrialPlan = $trialPlans->first();

        $companies = DB::table('companies')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('is_internal_account')->orWhere('is_internal_account', false);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('subscriptions')
                    ->whereColumn('subscriptions.company_id', 'companies.id');
            })
            ->get(['id', 'product_type', 'created_at']);

        foreach ($companies as $company) {
            $plan = $trialPlans->get($company->product_type ?? 'di') ?? $fallbackTrialPlan;

            $start = $company->created_at
                ? \Illuminate\Support\Carbon::parse($company->created_at)
                : now();
            $ends = $start->copy()->addDays(3);

            DB::table('subscriptions')->insert([
                'company_id' => $company->id,
                'pricing_plan_id' => $plan->id ?? null,
                'billing_cycle' => 'monthly',
                'discount_percent' => 0,
                'final_price' => 0,
                'start_date' => $start,
                'end_date' => $ends,
                'trial_ends_at' => $ends,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op — backfilled rows are indistinguishable from
        // organic trial rows once created, and deleting them would reopen the
        // "no subscription row" hole this migration closes.
    }
};
