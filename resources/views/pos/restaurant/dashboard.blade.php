<x-pos-layout>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>*, *::before, *::after { font-family: 'Inter', system-ui, -apple-system, sans-serif; }</style>

<div class="min-h-screen bg-gray-50 dark:bg-gray-950 p-3 sm:p-5" x-data="rDash()" x-init="init()">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-lg sm:text-xl font-extrabold text-gray-900 dark:text-white">Dashboard</h1>
            <p class="text-xs text-gray-400 mt-0.5">{{ now()->format('D, M d') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <span x-show="refreshing" class="text-xs text-purple-500 animate-pulse font-medium">...</span>
            <button @click="refreshDashboard()" class="p-1.5 rounded-lg text-gray-400 hover:text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" title="Refresh">
                <svg class="w-4 h-4" :class="refreshing ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </button>
            <a href="{{ route('pos.restaurant.pos') }}" class="px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-purple-600 hover:bg-purple-700 shadow-md shadow-purple-600/20 transition">Open POS</a>
            <a href="{{ route('pos.restaurant.kds') }}" class="px-3 py-1.5 rounded-lg text-xs font-bold text-orange-600 bg-orange-50 border border-orange-200 hover:bg-orange-100 dark:bg-orange-900/20 dark:border-orange-800 transition">KDS</a>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="bg-white dark:bg-gray-900 rounded-xl p-3 border border-gray-100 dark:border-gray-800 shadow-sm">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] text-gray-400 font-medium">Today's Sales</p>
                    <p class="text-base font-extrabold text-gray-900 dark:text-white truncate">Rs. {{ number_format($todaySales) }}</p>
                </div>
            </div>
            @if($yesterdaySales > 0)
            @php $changePercent = $yesterdaySales > 0 ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100) : 0; @endphp
            <p class="text-[9px] mt-1.5 {{ $changePercent >= 0 ? 'text-green-600' : 'text-red-500' }}">
                {{ $changePercent >= 0 ? '+' : '' }}{{ $changePercent }}% vs yesterday
            </p>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl p-3 border border-gray-100 dark:border-gray-800 shadow-sm">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] text-gray-400 font-medium">Orders</p>
                    <p class="text-base font-extrabold text-gray-900 dark:text-white">{{ $todayOrders }}</p>
                </div>
            </div>
            <p class="text-[9px] mt-1.5 text-gray-400">{{ $heldCount }} active &bull; {{ $completedCount }} done</p>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl p-3 border border-gray-100 dark:border-gray-800 shadow-sm">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] text-gray-400 font-medium">Avg Order</p>
                    <p class="text-base font-extrabold text-gray-900 dark:text-white truncate">Rs. {{ $todayOrders > 0 ? number_format($todaySales / $todayOrders) : 0 }}</p>
                </div>
            </div>
            @if($peakHour)
            <p class="text-[9px] mt-1.5 text-purple-500">Peak: {{ $peakHour }}</p>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl p-3 border border-gray-100 dark:border-gray-800 shadow-sm">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] text-gray-400 font-medium">Tables</p>
                    <p class="text-base font-extrabold text-gray-900 dark:text-white">{{ $occupiedTables }}/{{ $totalTables }}</p>
                </div>
            </div>
            <p class="text-[9px] mt-1.5 text-gray-400">{{ $totalTables - $occupiedTables }} available</p>
        </div>
    </div>

    @if(auth('pos')->user() && auth('pos')->user()->pos_role === 'pos_admin')
    <div class="grid grid-cols-3 gap-3 mb-4">
        <div class="bg-gradient-to-br from-emerald-50 to-green-50 dark:from-emerald-900/20 dark:to-green-900/20 rounded-xl p-3 border border-emerald-200 dark:border-emerald-800 shadow-sm">
            <p class="text-[10px] text-emerald-600 font-medium">Profit</p>
            <p class="text-base font-extrabold {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600' }}">Rs. {{ number_format($todayProfit ?? 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-3 border border-gray-100 dark:border-gray-800 shadow-sm">
            <p class="text-[10px] text-gray-400 font-medium">Cost</p>
            <p class="text-base font-extrabold text-gray-900 dark:text-white">Rs. {{ number_format($todayCost ?? 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-3 border border-gray-100 dark:border-gray-800 shadow-sm">
            <p class="text-[10px] text-gray-400 font-medium">Margin</p>
            <p class="text-base font-extrabold {{ ($todayProfit ?? 0) >= 0 ? 'text-indigo-700 dark:text-indigo-400' : 'text-red-600' }}">{{ $todaySales > 0 ? round(($todayProfit ?? 0) / $todaySales * 100) : 0 }}%</p>
        </div>
    </div>
    @endif

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="bg-white dark:bg-gray-900 rounded-xl p-2.5 border border-gray-100 dark:border-gray-800 shadow-sm">
            <p class="text-[10px] text-gray-400 font-medium">Tax Collected</p>
            <p class="text-sm font-bold text-gray-900 dark:text-white mt-0.5">Rs. {{ number_format($todayTax) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-2.5 border border-gray-100 dark:border-gray-800 shadow-sm">
            <p class="text-[10px] text-gray-400 font-medium">Discounts</p>
            <p class="text-sm font-bold text-orange-600 mt-0.5">Rs. {{ number_format($todayDiscount) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-2.5 border border-gray-100 dark:border-gray-800 shadow-sm">
            <p class="text-[10px] text-gray-400 font-medium">Completed</p>
            <p class="text-sm font-bold text-green-600 mt-0.5">{{ $completedCount }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-2.5 border border-gray-100 dark:border-gray-800 shadow-sm">
            <p class="text-[10px] text-gray-400 font-medium">Low Stock</p>
            <p class="text-sm font-bold {{ $lowStockItems->count() > 0 ? 'text-red-600' : 'text-green-600' }} mt-0.5">{{ $lowStockItems->count() }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
        <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-100 dark:border-gray-800 shadow-sm">
            <h2 class="text-xs font-bold text-gray-900 dark:text-white mb-3">Sales — Last 7 Days</h2>
            <canvas id="salesChart" height="140"></canvas>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-100 dark:border-gray-800 shadow-sm">
            <h2 class="text-xs font-bold text-gray-900 dark:text-white mb-3">Order Types</h2>
            <canvas id="orderTypeChart" height="140"></canvas>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-4">
        <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-100 dark:border-gray-800 shadow-sm">
            <h2 class="text-xs font-bold text-gray-900 dark:text-white mb-2">Top Items</h2>
            <div class="space-y-1">
                @forelse($topProducts->take(5) as $idx => $p)
                <div class="flex items-center gap-2 py-1.5 {{ !$loop->last ? 'border-b border-gray-50 dark:border-gray-800' : '' }}">
                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold {{ $idx < 3 ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">{{ $idx + 1 }}</span>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p>
                    </div>
                    <span class="text-[10px] text-gray-400">{{ $p->total_qty }}x</span>
                    <span class="text-xs font-bold text-gray-900 dark:text-white">Rs. {{ number_format($p->total_revenue) }}</span>
                </div>
                @empty
                <p class="text-xs text-gray-400 py-3 text-center">No sales data</p>
                @endforelse
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-100 dark:border-gray-800 shadow-sm">
            <h2 class="text-xs font-bold text-gray-900 dark:text-white mb-2">Low Stock</h2>
            <div class="space-y-1">
                @forelse($lowStockItems->take(5) as $ing)
                <div class="flex items-center gap-2 py-1.5 {{ !$loop->last ? 'border-b border-gray-50 dark:border-gray-800' : '' }}">
                    <span class="w-2 h-2 rounded-full flex-shrink-0 {{ $ing->current_stock <= 0 ? 'bg-red-500 animate-pulse' : 'bg-amber-500' }}"></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p>
                    </div>
                    <span class="text-xs font-bold {{ $ing->current_stock <= 0 ? 'text-red-600' : 'text-amber-600' }}">
                        {{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}
                    </span>
                </div>
                @empty
                <div class="text-center py-3">
                    <p class="text-xs text-green-600 font-medium">All in stock</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-100 dark:border-gray-800 shadow-sm mb-4">
        <h2 class="text-xs font-bold text-gray-900 dark:text-white mb-2">Recent Orders</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left text-[10px] text-gray-400 border-b border-gray-100 dark:border-gray-800">
                        <th class="py-1.5 pr-3">Order</th>
                        <th class="py-1.5 pr-3 hidden sm:table-cell">Type</th>
                        <th class="py-1.5 pr-3">Table</th>
                        <th class="py-1.5 pr-3">Amount</th>
                        <th class="py-1.5 pr-3">Status</th>
                        <th class="py-1.5 hidden lg:table-cell">Time</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentOrders->take(8) as $ro)
                    <tr class="border-b border-gray-50 dark:border-gray-800">
                        <td class="py-1.5 pr-3 font-semibold text-gray-900 dark:text-white">{{ $ro->order_number }}</td>
                        <td class="py-1.5 pr-3 hidden sm:table-cell"><span class="text-[10px] px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 capitalize">{{ str_replace('_', ' ', $ro->order_type) }}</span></td>
                        <td class="py-1.5 pr-3">{{ $ro->table ? 'T-' . $ro->table->table_number : '-' }}</td>
                        <td class="py-1.5 pr-3 font-bold">Rs. {{ number_format($ro->total_amount) }}</td>
                        <td class="py-1.5 pr-3">
                            <span class="text-[9px] px-1.5 py-0.5 rounded-full font-medium
                                {{ $ro->status === 'completed' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $ro->status === 'held' ? 'bg-amber-100 text-amber-700' : '' }}
                                {{ $ro->status === 'preparing' ? 'bg-blue-100 text-blue-700' : '' }}
                                {{ $ro->status === 'ready' ? 'bg-purple-100 text-purple-700' : '' }}
                                {{ $ro->status === 'cancelled' ? 'bg-red-100 text-red-700' : '' }}
                            ">{{ ucfirst($ro->status) }}</span>
                        </td>
                        <td class="py-1.5 text-gray-400 hidden lg:table-cell">{{ $ro->created_at->diffForHumans() }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="py-4 text-center text-gray-400 text-xs">No orders today</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if(auth('pos')->user() && auth('pos')->user()->pos_role === 'pos_admin')
    <div x-data="{ showSettings: false, mgrPin: '', cashierLimit: {{ $company->cashier_discount_limit ?? 10 }}, managerLimit: {{ $company->manager_discount_limit ?? 50 }}, saving: false, saved: false }" class="bg-white dark:bg-gray-900 rounded-xl p-4 border border-gray-100 dark:border-gray-800 shadow-sm mb-4">
        <button @click="showSettings = !showSettings" class="flex items-center justify-between w-full">
            <h2 class="text-sm font-bold text-gray-900 dark:text-white">Role & Discount Settings</h2>
            <svg class="w-4 h-4 text-gray-400 transition-transform" :class="showSettings ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div x-show="showSettings" x-transition class="mt-4 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Cashier Discount Limit (%)</label>
                    <input type="number" x-model.number="cashierLimit" min="0" max="100" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2 text-gray-900 dark:text-white">
                    <p class="text-[10px] text-gray-400 mt-1">Max discount % cashiers can apply</p>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Manager Discount Limit (%)</label>
                    <input type="number" x-model.number="managerLimit" min="0" max="100" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2 text-gray-900 dark:text-white">
                    <p class="text-[10px] text-gray-400 mt-1">Max discount after PIN override</p>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Manager Override PIN</label>
                    <input type="password" x-model="mgrPin" maxlength="6" placeholder="{{ $company->manager_override_pin ? '••••••' : 'Set 4-6 digit PIN' }}" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2 text-gray-900 dark:text-white">
                    <p class="text-[10px] text-gray-400 mt-1">{{ $company->manager_override_pin ? 'PIN is set. Enter new to change.' : 'Required for cashier override' }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button @click="async function() { saving = true; saved = false; try { const res = await fetch('{{ route('pos.restaurant.save-manager-pin') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ pin: mgrPin || undefined, cashier_discount_limit: cashierLimit, manager_discount_limit: managerLimit }) }); const d = await res.json(); if (d.success) { saved = true; mgrPin = ''; setTimeout(() => saved = false, 3000); } } catch(e) {} saving = false; }()" :disabled="saving" class="px-4 py-2 text-sm font-bold text-white bg-purple-600 rounded-xl hover:bg-purple-700 disabled:opacity-50 transition">
                    <span x-text="saving ? 'Saving...' : 'Save Settings'"></span>
                </button>
                <span x-show="saved" x-transition class="text-xs text-green-600 font-medium">Settings saved!</span>
            </div>
        </div>
    </div>
    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
function rDash() {
    return {
        refreshing: false,
        autoRefreshTimer: null,
        init() {
            this.$nextTick(() => {
                this.renderSalesChart();
                this.renderOrderTypeChart();
            });
            this.autoRefreshTimer = setInterval(() => this.refreshDashboard(), 120000);
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
                        label: 'Sales (Rs.)',
                        data: @json($salesChartData),
                        backgroundColor: 'rgba(124, 58, 237, 0.15)',
                        borderColor: 'rgba(124, 58, 237, 0.8)',
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 300 },
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 9 } } },
                        x: { grid: { display: false }, ticks: { font: { size: 9 } } }
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
                    animation: { duration: 300 },
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 8, usePointStyle: true, pointStyle: 'circle', font: { size: 10 } } }
                    }
                }
            });
        },
        renderHourlyChart() {}
    };
}
</script>
</x-pos-layout>
