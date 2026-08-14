<x-pos-layout>

<style>
@keyframes countUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes cardReveal { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
.stat-animate { animation: countUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) both; }
.card-reveal { animation: cardReveal 0.4s cubic-bezier(0.16, 1, 0.3, 1) both; }
.card-reveal:nth-child(1) { animation-delay: 0.05s; }
.card-reveal:nth-child(2) { animation-delay: 0.1s; }
.card-reveal:nth-child(3) { animation-delay: 0.15s; }
.card-reveal:nth-child(4) { animation-delay: 0.2s; }
.card-reveal:nth-child(5) { animation-delay: 0.25s; }
.card-reveal:nth-child(6) { animation-delay: 0.3s; }
.refresh-spin { animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }
</style>

<div class="w-full overflow-x-hidden" x-data="rDash()" x-init="init()">
    <div class="px-4 sm:px-6 py-4 max-w-7xl mx-auto">
        @include('pos.partials.pending-bills-tile')
        @include('pos.partials.today-khata')
        @include('pos.dashboard-styles.' . ($dashboardStyle ?? 'default'))

        {{-- ─── Kitchen Efficiency (owner, Jul 2026) — KDS timing report ─── --}}
        @if(isset($kitchenStats))
        @php
            // Minutes → "12 min" / "1h 5m"; null → em-dash.
            $fmtMins = function ($m) {
                if ($m === null) return '—';
                if ($m < 1) return __('pos.less_than_one_min');
                if ($m >= 60) { $h = intdiv((int) $m, 60); $r = (int) round($m - $h * 60); return $h . 'h ' . $r . 'm'; }
                return (fmod($m, 1) == 0.0 ? (string) (int) $m : (string) $m) . __('pos.min_suffix');
            };
        @endphp
        <div class="mt-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.kitchen_efficiency') }}</h3>
                    <p class="text-[11px] text-gray-400 mt-0.5">{{ __('pos.kitchen_efficiency_hint') }}</p>
                </div>
                <a href="{{ route('pos.restaurant.kds') }}" class="flex-shrink-0 text-xs font-semibold text-teal-700 dark:text-teal-400 hover:underline">{{ __('pos.open_kitchen_display') }}</a>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <div class="rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('pos.avg_prep_today') }}</p>
                    <p class="text-lg font-extrabold text-teal-700 dark:text-teal-400 mt-1">{{ $fmtMins($kitchenStats['avg_prep_today']) }}</p>
                </div>
                <div class="rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('pos.avg_prep_week') }}</p>
                    <p class="text-lg font-extrabold text-teal-700 dark:text-teal-400 mt-1">{{ $fmtMins($kitchenStats['avg_prep_week']) }}</p>
                </div>
                <div class="rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('pos.ready_to_cleared') }}</p>
                    <p class="text-lg font-extrabold text-teal-700 dark:text-teal-400 mt-1">{{ $fmtMins($kitchenStats['avg_clear_today']) }}</p>
                </div>
                <div class="rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('pos.cleared_today') }}</p>
                    <p class="text-lg font-extrabold text-gray-900 dark:text-white mt-1">{{ $kitchenStats['cleared_today'] }}</p>
                </div>
                <div class="rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ __('pos.in_kitchen_now') }}</p>
                    <p class="text-lg font-extrabold {{ $kitchenStats['in_kitchen_now'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white' }} mt-1">{{ $kitchenStats['in_kitchen_now'] }}</p>
                </div>
            </div>
            @if(is_null($kitchenStats['avg_prep_today']) && $kitchenStats['cleared_today'] === 0)
            <p class="mt-3 text-[11px] text-gray-400">{{ __('pos.no_kitchen_timing_today') }}</p>
            @endif
        </div>
        @endif
    </div>
</div>

<script>
function animateCount(el, target, duration) {
    if (!el || isNaN(target)) return;
    const start = 0;
    const startTime = performance.now();
    const isDecimal = target % 1 !== 0;
    function step(now) {
        const elapsed = now - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        const current = Math.round(start + (target - start) * eased);
        el.textContent = (isDecimal ? current.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}) : current.toLocaleString());
        if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
}
function rDash() {
    return {
        refreshing: false,
        init() {
            this.$nextTick(() => {
                this.renderSalesChart();
                this.renderOrderTypeChart();
                this.animateStatNumbers();
            });
            // Task 644: auto-refresh used to double-fetch the FULL page every 30s
            // (XHR reachability GET + reload) even in hidden tabs — real weight on
            // shop internet. Now: skip entirely while the tab is hidden, and do a
            // catch-up refresh when the cashier comes back after 30s+ away.
            setInterval(() => { if (!document.hidden) this.refreshDashboard(); }, 30000);
            this._lastRefresh = Date.now();
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && Date.now() - this._lastRefresh > 30000) this.refreshDashboard();
            });
        },
        animateStatNumbers() {
            document.querySelectorAll('[data-count-target]').forEach(function(el) {
                const target = parseFloat(el.getAttribute('data-count-target'));
                if (!isNaN(target) && target > 0) {
                    animateCount(el, target, 1200);
                    el.classList.add('stat-animate');
                }
            });
        },
        async refreshDashboard() {
            if (this.refreshing) return;
            this.refreshing = true;
            this._lastRefresh = Date.now();
            try {
                // Task 644: HEAD = body-less reachability probe (was a full-page
                // GET whose 230KB body was thrown away before the reload refetched it).
                const res = await fetch(window.location.href, { method: 'HEAD', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (res.ok) {
                    window.location.reload();
                }
            } catch(e) {}
            this.refreshing = false;
        },
        renderSalesChart() {
            const ctx = document.getElementById('salesChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: @json($salesChartLabels),
                    datasets: [{
                        label: @js(__('pos.revenue_rs')),
                        data: @json($salesChartData),
                        backgroundColor: function(context) {
                            const chart = context.chart;
                            const {ctx: c, chartArea} = chart;
                            if (!chartArea) return 'rgba(124, 58, 237, 0.15)';
                            const g = c.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                            g.addColorStop(0, 'rgba(124, 58, 237, 0.05)');
                            g.addColorStop(1, 'rgba(124, 58, 237, 0.3)');
                            return g;
                        },
                        borderColor: 'rgba(124, 58, 237, 0.8)',
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 1200, easing: 'easeOutQuart', delay: function(context) { return context.dataIndex * 80; } },
                    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 11, weight: '600' }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8, displayColors: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.03)', drawBorder: false }, ticks: { font: { size: 9, weight: '500' }, padding: 6 }, border: { display: false } },
                        x: { grid: { display: false }, ticks: { font: { size: 9, weight: '500' }, padding: 4 }, border: { display: false } }
                    }
                }
            });
        },
        renderOrderTypeChart() {
            const ctx = document.getElementById('orderTypeChart');
            if (!ctx) return;
            const data = @json($orderTypeCounts);
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(data).map(k => k.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())),
                    datasets: [{
                        data: Object.values(data),
                        backgroundColor: ['#7c3aed', '#3b82f6', '#f59e0b', '#10b981'],
                        borderWidth: 0,
                        spacing: 2,
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 1000, easing: 'easeOutQuart', animateRotate: true, animateScale: true },
                    cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, pointStyle: 'circle', font: { size: 10, weight: '500' } } },
                        tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 11, weight: '600' }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8, displayColors: true }
                    }
                }
            });
        },
    };
}
</script>
</x-pos-layout>
