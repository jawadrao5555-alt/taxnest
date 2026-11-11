@php
    $dashboardMetricDate = $todayKhata['date'] ?? $todayKhata['business_date']
        ?? \App\Services\PosBusinessDay::current((int) $company->id);
    $dashboardMetricCashier = $dashCashierId ?? $cashierFilter ?? 'all';
    $dashboardMetricParams = ['date' => $dashboardMetricDate, 'cashier' => $dashboardMetricCashier];
    $dashboardViewer = auth('pos')->user();
    $dashboardMetricUrls = [];
    $orderSource = $dashboardOrderSource ?? 'bills';
    if ($orderSource === 'orders' && ($dashboardStyle ?? null) === 'saaf') {
        $orderSource = 'completed-orders';
    }
    if ($dashboardViewer?->posCustomAllows('reports') !== false) {
        $dashboardMetricUrls = [
            'revenue' => route('pos.dashboard.detail', ['metric' => 'revenue'] + $dashboardMetricParams),
            'orders' => route('pos.dashboard.detail', [
                'metric' => 'orders',
                'source' => $orderSource,
            ] + $dashboardMetricParams),
            'customers' => route('pos.dashboard.detail', ['metric' => 'customers'] + $dashboardMetricParams),
        ];
    }
    if ($dashboardViewer?->posCustomAllows('tables') !== false
        && \App\Services\PosFeatureService::moduleRelevant($company, 'tables')) {
        $dashboardMetricUrls['tables'] = route('pos.restaurant.tables');
    }
@endphp
<div data-dashboard-metrics data-dashboard-theme="{{ $dashboardStyle ?? 'default' }}">
    @include('pos.dashboard-styles.' . ($dashboardStyle ?? 'default'))
</div>
