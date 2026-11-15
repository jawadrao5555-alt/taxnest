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
    note="The table shows annual package prices. Additional configured periods appear below when available. Fiscal reporting depends on category, approved integration and verification; School and Hospital fiscal submission remains disabled."
    tip="Scroll sideways to see all packages."
    included-title="Included in every package"
    included-sub="These never depend on which package you pick."
    picks-title="Pick a package"
    :surface="$surface"
    :show-heading="$showHeading"
/>

@if($surface === 'landing' && config('billing.shared_configured_cycles', false))
<section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 my-6" aria-label="Configured billing periods">
    @foreach($fcmpPlans as $plan)
    <article class="rounded-xl border p-4 bg-white text-gray-900">
        <h3 class="font-bold">{{ $plan->name }}</h3>
        @include('billing._configured-cycle-quote', ['plan' => $plan, 'company' => null, 'signupRoute' => 'fbrpos.register'])
    </article>
    @endforeach
</section>
@endif
