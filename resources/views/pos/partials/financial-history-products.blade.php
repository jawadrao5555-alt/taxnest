@if(isset($financialHistory) && $canAnalyticsPlan)
@php
    $presentation = \App\Services\PosReportPresentation::fromHistory($financialHistory);
@endphp
<x-pos.report-panel id="financial-products" class="my-5 overflow-auto p-4" data-pos-report-results>
    @include('pos.partials.report-product-table')
</x-pos.report-panel>
@endif
