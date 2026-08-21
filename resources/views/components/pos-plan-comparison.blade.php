@props([
    /** Collection<PricingPlan> — defaults to the paid PRA POS packages. */
    'plans' => null,
    /** Highlight the customer's own package (POS panel billing page). */
    'currentPlanId' => null,
    /** landing | panel — only changes the surrounding chrome, never the data. */
    'surface' => 'landing',
    'showHeading' => true,
])

@php
    /**
     * Task 1350 — PRA POS package comparison.
     *
     * NOT hand-written: every number and every tick comes from
     * PosPlanComparisonService, which reads the same pricing_plans columns the
     * gates read. A new gate column with no customer-facing name here fails
     * scripts/plan-gate-check.php before the deploy.
     *
     * The markup lives in <x-plan-comparison-table> (shared with FBR POS and
     * Digital Invoice, Task 1383); this component only supplies PRA's data and
     * its translated chrome.
     */
    $pcmpPlans = $plans ?? \App\Services\PosPlanComparisonService::plans();
    // Task 1483: on the landing the column heading is the buying surface, so
    // it also carries the price block and a signup link per package. The
    // panel surface stays exactly as it was — name + price, no buttons.
    $pcmpCols = \App\Services\PosPlanComparisonService::planColumns(
        $pcmpPlans,
        $currentPlanId ? (int) $currentPlanId : null,
        $surface === 'landing'
    );
    $pcmpSections = \App\Services\PosPlanComparisonService::sections($pcmpPlans);
    $pcmpIncluded = \App\Services\PosPlanComparisonService::includedItems();
    $pcmpBranchNote = __('pos.pcmp_branch_note', ['price' => number_format(\App\Services\BranchAddonService::PRICE_PER_YEAR)]);
@endphp

<x-plan-comparison-table
    {{ $attributes }}
    :cols="$pcmpCols"
    :sections="$pcmpSections"
    :included="$pcmpIncluded"
    :heading="__('pos.pcmp_heading')"
    :sub="__('pos.pcmp_sub')"
    :col-label="__('pos.pcmp_col_features')"
    :popular-label="__('pos.pcmp_popular')"
    :current-label="__('pos.pcmp_your_plan')"
    :tick-label="__('pos.pcmp_included_title')"
    :note="$pcmpBranchNote"
    :tip="__('pos.pcmp_scroll_tip')"
    :included-title="__('pos.pcmp_included_title')"
    :included-sub="__('pos.pcmp_included_sub')"
    :picks-title="__('pos.pcmp_pick_package')"
    :surface="$surface"
    :show-heading="$showHeading"
/>
