@props([
    /** Collection<PricingPlan> — defaults to the paid Digital Invoice packages. */
    'plans' => null,
    /** Highlight the customer's own package (panel surfaces). */
    'currentPlanId' => null,
    'surface' => 'landing',
    'showHeading' => true,
])

@php
    /**
     * Task 1383 — Digital Invoice package comparison.
     *
     * NOT hand-written: limit numbers come from the pricing_plans columns the
     * DI middleware really reads, and every tick comes from
     * DiFeatureService::planIncludes() — the same matrix lookup the panel's
     * gate ends on. A new DI gate with no customer-facing name fails
     * scripts/plan-gate-check.php before the deploy.
     *
     * Markup is the shared <x-plan-comparison-table>, so this reads exactly
     * like the PRA POS and FBR POS tables.
     */
    $dcmpPlans = $plans ?? \App\Services\DiPlanComparisonService::plans();
    // Task 1483: the landing heading carries the price (re-computed by the
    // page's billing-cycle switch) and the signup link for that package;
    // panel surfaces stay unchanged.
    $dcmpCols = \App\Services\DiPlanComparisonService::planColumns(
        $dcmpPlans,
        $currentPlanId ? (int) $currentPlanId : null,
        $surface === 'landing'
    );
    $dcmpSections = \App\Services\DiPlanComparisonService::sections($dcmpPlans);
    $dcmpIncluded = \App\Services\DiPlanComparisonService::includedItems();
@endphp

<x-plan-comparison-table
    {{ $attributes }}
    :cols="$dcmpCols"
    :sections="$dcmpSections"
    :included="$dcmpIncluded"
    heading="Compare the packages"
    sub="Every number and every tick below is read straight off the package itself — the same settings the panel checks before it opens a feature or refuses an invoice. Nothing here is a marketing promise."
    col-label="Features"
    popular-label="Most complete"
    current-label="Your plan"
    tick-label="Included"
    note="Prices follow the billing cycle you picked above; the packages themselves do not change."
    tip="Scroll sideways to see all packages."
    included-title="Included in every package"
    included-sub="These never depend on which package you pick."
    picks-title="Pick a package"
    :surface="$surface"
    :show-heading="$showHeading"
/>
