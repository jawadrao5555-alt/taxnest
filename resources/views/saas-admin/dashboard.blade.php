<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Admin Dashboard</h1>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <p class="text-xs text-gray-500 mb-1">Total Companies</p>
            <p class="text-2xl font-bold text-white">{{ number_format($stats['total_companies']) }}</p>
            @if($stats['pending_companies'] > 0)
            <p class="text-xs text-amber-400 mt-1">{{ $stats['pending_companies'] }} pending approval</p>
            @endif
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <p class="text-xs text-gray-500 mb-1">Active Subscriptions</p>
            <p class="text-2xl font-bold text-indigo-400">{{ number_format($stats['active_subscriptions']) }}</p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <p class="text-xs text-gray-500 mb-1">Total POS Revenue</p>
            <p class="text-2xl font-bold text-emerald-400">PKR {{ number_format($stats['total_pos_revenue'], 0) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ number_format($stats['total_pos_transactions']) }} transactions</p>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <p class="text-xs text-gray-500 mb-1">Today's POS Activity</p>
            <p class="text-2xl font-bold text-white">{{ number_format($stats['today_pos_transactions']) }}</p>
            <p class="text-xs text-gray-500 mt-1">transactions today</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Platform Stats</h3>
            <div class="space-y-3">
                <div class="flex justify-between text-sm"><span class="text-gray-400">Total Users</span><span class="text-white font-medium">{{ $stats['total_users'] }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-gray-400">Franchises</span><span class="text-white font-medium">{{ $stats['total_franchises'] }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-gray-400">Pending Companies</span><span class="{{ $stats['pending_companies'] > 0 ? 'text-amber-400' : 'text-white' }} font-medium">{{ $stats['pending_companies'] }}</span></div>
            </div>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">System Controls</h3>
            <div class="space-y-2">
                @foreach($systemControls as $control)
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-400">{{ ucwords(str_replace('_', ' ', $control->key)) }}</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $control->value === 'enabled' ? 'bg-emerald-900/30 text-emerald-400' : 'bg-red-900/30 text-red-400' }}">{{ $control->value }}</span>
                </div>
                @endforeach
            </div>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Recent Companies</h3>
            <div class="space-y-2">
                @foreach($recentCompanies as $company)
                <div class="flex items-center justify-between text-sm">
                    <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-gray-300 hover:text-indigo-400 truncate">{{ $company->name }}</a>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $company->status === 'approved' ? 'bg-emerald-900/30 text-emerald-400' : ($company->status === 'pending' ? 'bg-amber-900/30 text-amber-400' : 'bg-red-900/30 text-red-400') }}">{{ $company->status }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    @if($monthlyRevenue->count() > 0)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-8">
        <h3 class="text-sm font-semibold text-white mb-4">Monthly POS Revenue</h3>
        <canvas id="revenueChart" height="100"></canvas>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                new Chart(document.getElementById('revenueChart'), {
                    type: 'bar',
                    data: {
                        labels: {!! json_encode($monthlyRevenue->pluck('month')) !!},
                        datasets: [{
                            label: 'Revenue (PKR)',
                            data: {!! json_encode($monthlyRevenue->pluck('revenue')) !!},
                            backgroundColor: 'rgba(99, 102, 241, 0.3)',
                            borderColor: 'rgb(99, 102, 241)',
                            borderWidth: 1, borderRadius: 4
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { ticks: { color: '#6b7280' }, grid: { color: '#1f2937' } }, x: { ticks: { color: '#6b7280' }, grid: { display: false } } } }
                });
            });
        </script>
    </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-white mb-3">Recent Audit Activity</h3>
        <div class="space-y-2">
            @forelse($recentAuditLogs as $log)
            <div class="flex items-center justify-between text-sm border-b border-gray-800 pb-2">
                <div><span class="text-gray-300">{{ $log->action }}</span>@if($log->target_type)<span class="text-gray-600"> - {{ $log->target_type }} #{{ $log->target_id }}</span>@endif</div>
                <span class="text-xs text-gray-500 whitespace-nowrap">{{ $log->created_at->diffForHumans() }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-500 text-center py-4">No audit logs yet</p>
            @endforelse
        </div>
    </div>
</div>
</x-admin-layout>
