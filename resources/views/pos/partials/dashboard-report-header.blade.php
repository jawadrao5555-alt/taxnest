@php
    $reportUser = auth('pos')->user();
    $reportCashier = $dashCashierId ?? $cashierFilter ?? 'all';
    $reportDate = $todayKhata['date'] ?? \App\Services\PosBusinessDay::current((int) $company->id);
@endphp
@include('pos.partials.report-screen-style')
<section class="pos-report-document mt-6" data-dashboard-report-section>
<x-pos.report-header :title="__('pos.reports_word')" :subtitle="__('pos.business_day_label') . ' · ' . $reportDate" :back="false" heading="h2">
    <x-slot:actions>
        @if(($reportUser?->posCustomAllows('reports') ?? true))
            <a data-pos-report-link="sales" href="{{ route('pos.reports', ['from' => $reportDate, 'to' => $reportDate, 'cashier' => $reportCashier]) }}"
               class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">{{ __('pos.nav_sales_reports') }}</a>
        @endif
        @if(($reportUser?->posCustomAllows('tax_reports') ?? true))
            <a data-pos-report-link="tax" href="{{ route('pos.tax-reports') }}"
               class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-200">{{ __('pos.nav_tax_reports') }}</a>
        @endif
        @if(\App\Services\PosAccessService::dayCloseAllowed($reportUser, $company))
            <a data-pos-report-link="day-close" href="{{ route('pos.day-close', ['date' => $reportDate]) }}"
               class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-200">{{ __('pos.nav_day_close') }}</a>
        @endif
    </x-slot:actions>
</x-pos.report-header>
@if($reportUser?->posCustomAllows('reports') !== false)
    <div data-dashboard-sales-overview="{{ route('pos.reports.dashboard-overview', ['cashier' => $reportCashier]) }}"
         x-data="{ fragment: '' }" x-html="fragment"
         x-init="
            const panel = $el;
            const load = async () => {
                try {
                    const response = await fetch(panel.dataset.dashboardSalesOverview);
                    if (response.ok && response.headers.get('X-Pos-Report-Fragment') === 'sales') {
                        fragment = await response.text();
                    }
                } catch (_) {
                    // The full Sales Reports link remains available above.
                }
            };
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver(([entry]) => {
                    if (!entry.isIntersecting) return;
                    observer.disconnect();
                    load();
                }, { rootMargin: '200px' });
                observer.observe(panel);
            } else {
                load();
            }
         ">
    </div>
@endif
</section>
