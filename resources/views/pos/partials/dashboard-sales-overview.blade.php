@php
    $reportScopeLabel = match ($tab) {
        'combined' => __('pos.dc_summary_streams'),
        'local' => __('pos.local_invoices'),
        'exempt' => __('pos.exempt_invoices'),
        default => __('pos.pra_invoices'),
    };
@endphp
<p class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $reportScopeLabel }}</p>
<x-pos.report-panel class="mb-5 overflow-auto p-4" data-pos-report-summary>
    @include('pos.partials.report-summary-tables')
</x-pos.report-panel>
@if($showProducts)
    <x-pos.report-panel class="overflow-auto p-4" data-pos-report-results>
        @include('pos.partials.report-product-table')
    </x-pos.report-panel>
@endif
