<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Schema;


/**
 * Task 1484 — the package a shop clicked on the public pricing table, carried
 * through signup and honoured at approval.
 *
 * Single source of truth for all three products so no surface can drift:
 *  - signup resolves the ?plan= name (and, for Digital Invoice, the ?cycle=)
 *    into columns on the new company,
 *  - approval charges the period that product actually sells,
 *  - the admin screens show exactly what approval is about to activate.
 *
 * Everything is re-resolved SERVER-SIDE against pricing_plans, so a tampered,
 * unknown, trial or wrong-product name is simply ignored — never stored, and
 * therefore never activated.
 */
class RequestedPackageService
{
    /**
     * A real, paid (non-trial) package of that product, matched by name the
     * same way the pricing table's link writes it. Anything else → null.
     *
     * Name matching happens in PHP (not SQL) so it stays case-insensitive
     * regardless of the database collation.
     */
    public static function resolvePlan(?string $name, string $productType): ?PricingPlan
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $plan = PricingPlan::where('product_type', $productType)
            ->where('is_trial', false)
            ->get()
            ->first(fn (PricingPlan $plan) => mb_strtolower($plan->name) === mb_strtolower($name));

        // A retired package must never come back through a stale ?plan= link on
        // a signup form — whichever product line retired it.
        if (PlanSellabilityService::isRetired($plan)) {
            return null;
        }

        return $plan;
    }

    /** The three cycles the POS lines sell (Aug 2026). Order = cheapest per month first. */
    public const POS_CYCLES = ['annual', 'quarterly', 'monthly'];

    /**
     * The cycle a requested package is actually charged on.
     *
     * Digital Invoice quotes a MONTHLY price and the pricing page lets the
     * visitor pick one of four cycles — that choice is honoured (unknown or
     * missing falls back to monthly). Both POS lines sell all three of their own
     * cycles (PRA since Aug 2026, FBR POS since 23 Aug 2026). Every other
     * product is licensed by the YEAR, so the visitor never picks a cycle and
     * none can be smuggled in.
     */
    public static function cycleForPlan(PricingPlan $plan, ?string $stored = null): string
    {
        $type = $plan->product_type ?? 'di';

        if ($type === 'di') {
            return SubscriptionAssignmentService::normalizeCycle($stored);
        }

        if ($type === 'pos' || $type === 'fbrpos') {
            // Deliberately NOT normalizeCycle(): that helper maps anything it
            // does not recognise to 'monthly', which here is the DEAREST cycle
            // per month. A tampered, empty or DI-only value ('semi_annual')
            // must fall back to the annual default instead of silently
            // upgrading the shop onto the most expensive rate.
            $raw = mb_strtolower(trim((string) $stored));
            $raw = $raw === 'yearly' ? 'annual' : $raw;

            return in_array($raw, self::POS_CYCLES, true) ? $raw : 'annual';
        }

        return 'annual';
    }

    /**
     * Company columns to store at signup. Empty array when nothing valid was
     * picked, so a signup with no package behind it is untouched.
     *
     * hasColumn-guarded: a deploy-before-migrate window (or a minimal test
     * schema) must never 500 a registration.
     */
    public static function companyAttributes(?PricingPlan $plan, ?string $rawCycle = null): array
    {
        if (!$plan || !Schema::hasColumn('companies', 'requested_plan_id')) {
            return [];
        }

        $attributes = ['requested_plan_id' => $plan->id];

        if (Schema::hasColumn('companies', 'requested_billing_cycle')) {
            $attributes['requested_billing_cycle'] = self::cycleForPlan($plan, $rawCycle);
        }

        return $attributes;
    }

    /**
     * What the admin sees while a company is still waiting for approval: the
     * package it asked for, the cycle it will be charged on and the exact
     * amount — the same numbers assignRequestedPlanOnApproval is about to write.
     *
     * Returns null once the company is approved (the live subscription speaks
     * for itself from then on) or when nothing valid was requested.
     *
     * The price is the package's own price: a brand-new signup has no paid
     * extra-branch slots, and this runs per row on the admin lists.
     */
    public static function pendingSummary(?Company $company): ?array
    {
        if (!$company) {
            return null;
        }

        // DI signups land as status=pending/company_status=active, POS + FBR POS
        // as pending/pending — either one means "not approved yet".
        $awaiting = ($company->status ?? null) === 'pending'
            || ($company->company_status ?? null) === 'pending';
        if (!$awaiting) {
            return null;
        }

        // Never lazy-load: live runs with preventLazyLoading(), and the admin
        // lists that call this per row already eager-load the relation.
        $plan = $company->relationLoaded('requestedPlan')
            ? $company->getRelation('requestedPlan')
            : PricingPlan::find($company->requested_plan_id ?? null);
        if (!$plan || $plan->is_trial || PlanSellabilityService::isRetired($plan)) {
            return null;
        }

        $cycle = self::cycleForPlan($plan, $company->requested_billing_cycle ?? null);
        $priced = SubscriptionAssignmentService::computePrice($plan, $cycle);
        $cycle = $priced['cycle'];

        $per = match ($cycle) {
            'annual' => '/ year',
            'semi_annual' => 'every 6 months',
            'quarterly' => 'every 3 months',
            default => '/ month',
        };
        $period = match ($cycle) {
            'annual' => '1 full year',
            'semi_annual' => '6 months',
            'quarterly' => '3 months',
            default => '1 month',
        };
        $price = 'Rs ' . number_format((float) $priced['final_price']) . ' ' . $per;

        return [
            'name' => $plan->name,
            'cycle' => $cycle,
            'cycle_label' => Subscription::getCycleLabel($cycle),
            'price' => (float) $priced['final_price'],
            'price_label' => $price,
            // Ready-made strings so every admin surface reads identically.
            'badge' => $plan->name . ' — ' . $price,
            'note' => 'Approving will activate this package for ' . $period . '.',
        ];
    }
}
