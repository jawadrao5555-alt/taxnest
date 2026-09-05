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

        // storedTypesFor(): Nest ERPS rows may still carry the spelling they had
        // before the umbrella existed, and a ?plan= link must resolve either way.
        $plan = PricingPlan::whereIn('product_type', \App\Support\NestErps::storedTypesFor($productType))
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

    /** Every product line sells the YEAR and nothing else (owner, 23 Aug 2026). */
    public const POS_CYCLES = SubscriptionAssignmentService::SELLABLE_CYCLES;

    /**
     * The cycle a requested package is actually charged on.
     *
     * Since 23 Aug 2026 that is ALWAYS the year, for all three products. A
     * stale ?cycle=monthly link, a remembered signup session or a tampered
     * form value can therefore no longer smuggle a cycle the shop cannot buy
     * into approval — the admin activates the same annual package the pricing
     * page advertised.
     */
    public static function cycleForPlan(PricingPlan $plan, ?string $stored = null): string
    {
        return SubscriptionAssignmentService::purchaseCycle($stored, $plan->product_type ?? null);
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
