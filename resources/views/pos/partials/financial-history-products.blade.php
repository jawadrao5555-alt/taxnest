@if(isset($financialHistory) && $canAnalyticsPlan)
@php
    $presentation = \App\Services\PosReportPresentation::fromHistory($financialHistory);
@endphp
<section id="financial-products" class="bg-white dark:bg-gray-900 rounded-xl border p-4 my-5 overflow-auto">
    @include('pos.partials.report-product-table')
</section>
@endif
