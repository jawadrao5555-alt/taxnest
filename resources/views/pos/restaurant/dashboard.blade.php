<x-pos-layout>

<div class="w-full overflow-x-hidden" x-data="rDash()" x-init="init()">
    <div class="px-4 sm:px-6 py-4 max-w-7xl mx-auto">
        @include('pos.dashboard-styles.' . ($dashboardStyle ?? 'default'))
    </div>
</div>

<script>
function rDash() {
    return {
        refreshing: false,
        init() {
            this.$nextTick(() => {
                this.renderSalesChart();
                this.renderOrderTypeChart();
            });
            setInterval(() => this.refreshDashboard(), 120000);
        },
        async refreshDashboard() {
            this.refreshing = true;
            try { window.location.reload(); } catch(e) {}
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
                            g.addColorStop(1, 'rgba(124, 58, 237, 0.25)');
                            return g;
                        },
                        borderColor: 'rgba(124, 58, 237, 0.8)',
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 10, weight: '600' }, bodyFont: { size: 10 }, padding: 8, cornerRadius: 6, displayColors: false } },
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
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 10, usePointStyle: true, pointStyle: 'circle', font: { size: 9, weight: '500' } } },
                        tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 10, weight: '600' }, bodyFont: { size: 10 }, padding: 8, cornerRadius: 6, displayColors: true }
                    }
                }
            });
        },
    };
}
</script>
</x-pos-layout>
