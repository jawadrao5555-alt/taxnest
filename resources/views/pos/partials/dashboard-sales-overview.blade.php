<x-pos.report-panel class="mb-5 overflow-auto p-4" data-pos-report-summary>
    @include('pos.partials.report-summary-tables')
</x-pos.report-panel>
@if($showProducts)
    <x-pos.report-panel class="overflow-auto p-4" data-pos-report-results>
        @include('pos.partials.report-product-table')
    </x-pos.report-panel>
@endif
