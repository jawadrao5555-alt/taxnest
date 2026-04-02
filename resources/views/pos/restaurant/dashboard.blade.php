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
        @include('pos.dashboard-styles.' . ($dashboardStyle ?? 'default'))
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
            setInterval(() => this.refreshDashboard(), 30000);
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
            this.refreshing = true;
            try {
                const res = await fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
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
                        label: 'Revenue (Rs.)',
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
