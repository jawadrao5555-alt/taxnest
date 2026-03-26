<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">Override & Tax Intelligence Logs</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" x-data="{ activeTab: 'mis' }">

            @if(isset($layerStats) && $layerStats->count() > 0)
            <div class="bg-white rounded-xl shadow-sm border border-cyan-100 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Tax Intelligence Override Distribution</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                        <p class="text-3xl font-bold text-blue-700">{{ $layerStats['sector'] ?? 0 }}</p>
                        <p class="text-sm text-blue-600 mt-1">Sector Overrides</p>
                    </div>
                    <div class="text-center p-4 bg-purple-50 rounded-lg">
                        <p class="text-3xl font-bold text-purple-700">{{ $layerStats['province'] ?? 0 }}</p>
                        <p class="text-sm text-purple-600 mt-1">Province Overrides</p>
                    </div>
                    <div class="text-center p-4 bg-emerald-50 rounded-lg">
                        <p class="text-3xl font-bold text-emerald-700">{{ $layerStats['customer'] ?? 0 }}</p>
                        <p class="text-sm text-emerald-600 mt-1">Customer Overrides</p>
                    </div>
                    <div class="text-center p-4 bg-amber-50 rounded-lg">
                        <p class="text-3xl font-bold text-amber-700">{{ $layerStats['sro'] ?? 0 }}</p>
                        <p class="text-sm text-amber-600 mt-1">SRO Overrides</p>
                    </div>
                </div>
            </div>
            @endif

            <div class="flex space-x-2 mb-6">
                <button @click="activeTab = 'mis'"
                    :class="activeTab === 'mis' ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
                    class="px-4 py-2 rounded-lg font-medium text-sm transition">
                    MIS Override History
                </button>
                <button @click="activeTab = 'tax'"
                    :class="activeTab === 'tax' ? 'bg-cyan-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
                    class="px-4 py-2 rounded-lg font-medium text-sm transition">
                    Tax Intelligence Usage
                </button>
            </div>

            <div x-show="activeTab === 'mis'">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800">Direct MIS Override History</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($logs as $log)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $log->created_at->format('M d, Y H:i') }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900">{{ $log->user->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $log->company->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $log->invoice->invoice_number ?? 'N/A' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">{{ str_replace('_', ' ', $log->action) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate">{{ $log->reason }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500 font-mono">{{ $log->ip_address ?? '-' }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No override logs found</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($logs->hasPages())
                    <div class="px-6 py-4 border-t border-gray-100">
                        {{ $logs->links() }}
                    </div>
                    @endif
                </div>
            </div>

            <div x-show="activeTab === 'tax'" x-cloak>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800">Tax Intelligence Override Usage</h3>
                        <p class="text-sm text-gray-500 mt-1">Automatic overrides applied by the multi-layer tax resolution engine</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">HS Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Layer</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Original</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Overridden</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($taxOverrideUsage as $usage)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $usage->created_at->format('M d, Y H:i') }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900">{{ $usage->company->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $usage->invoice->invoice_number ?? ($usage->invoice_id ? 'INV-'.$usage->invoice_id : '-') }}</td>
                                    <td class="px-4 py-3 text-sm font-mono text-gray-700">{{ $usage->hs_code }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                            @if($usage->override_layer === 'customer') bg-emerald-100 text-emerald-800
                                            @elseif($usage->override_layer === 'province') bg-purple-100 text-purple-800
                                            @elseif($usage->override_layer === 'sector') bg-blue-100 text-blue-800
                                            @else bg-amber-100 text-amber-800
                                            @endif">
                                            {{ ucfirst($usage->override_layer) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500 max-w-xs">
                                        @if(is_array($usage->original_values))
                                            @foreach($usage->original_values as $k => $v)
                                                <span class="inline-block bg-gray-100 px-1.5 py-0.5 rounded mr-1 mb-1">{{ $k }}: {{ $v }}</span>
                                            @endforeach
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500 max-w-xs">
                                        @if(is_array($usage->overridden_values))
                                            @foreach($usage->overridden_values as $k => $v)
                                                <span class="inline-block bg-cyan-100 text-cyan-800 px-1.5 py-0.5 rounded mr-1 mb-1">{{ $k }}: {{ $v }}</span>
                                            @endforeach
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No tax intelligence overrides applied yet</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex space-x-3">
                <a href="/admin/dashboard" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg font-medium text-sm hover:bg-gray-700 transition">Back to Dashboard</a>
                <a href="/tax-overrides" class="inline-flex items-center px-4 py-2 bg-cyan-600 text-white rounded-lg font-medium text-sm hover:bg-cyan-700 transition">Manage Tax Rules</a>
            </div>
        </div>
    </div>
</x-admin-layout>
