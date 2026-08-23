@props([
    /** Collection<PricingPlan> — defaults to the paid FBR POS packages. */
    'plans' => null,
    /** Highlight the customer's own package (panel surfaces). */
    'currentPlanId' => null,
    'surface' => 'landing',
    'showHeading' => true,
])

@php
    /**
     * Task 1383 — FBR POS package comparison.
     *
     * NOT hand-written: every number and every tick comes from
     * FbrPosPlanComparisonService, which reads the same pricing_plans columns
     * the FBR gates read. A new gate column with no customer-facing name there
     * fails scripts/plan-gate-check.php before the deploy.
     *
     * Markup is the shared <x-plan-comparison-table>, so this reads exactly
     * like the PRA POS and Digital Invoice tables.
     */
    $fcmpPlans = $plans ?? \App\Services\FbrPosPlanComparisonService::plans();
    // Task 1483: the landing heading also carries the yearly price and the
    // signup link for that package; panel surfaces stay unchanged.
    $fcmpCols = \App\Services\FbrPosPlanComparisonService::planColumns(
        $fcmpPlans,
        $currentPlanId ? (int) $currentPlanId : null,
        $surface === 'landing'
    );
    $fcmpSections = \App\Services\FbrPosPlanComparisonService::sections($fcmpPlans);
    $fcmpIncluded = \App\Services\FbrPosPlanComparisonService::includedItems();
@endphp

<x-plan-comparison-table
    {{ $attributes }}
    :cols="$fcmpCols"
    :sections="$fcmpSections"
    :included="$fcmpIncluded"
    heading="Compare the packages"
    sub="Every tick below is read straight off the package your shop is on — the same setting the counter checks before it opens a feature. Nothing here is a marketing promise."
    col-label="Features"
    popular-label="Popular"
    current-label="Your plan"
    tick-label="Included"
    note="The headline price is for a full year — the cheapest way to run. Paying every 3 months or monthly costs a little more. Every package bills to FBR in real time from day one."
    tip="Scroll sideways to see all packages."
    included-title="Included in every package"
    included-sub="These never depend on which package you pick."
    picks-title="Pick a package"
    :surface="$surface"
    :show-heading="$showHeading"
/>
