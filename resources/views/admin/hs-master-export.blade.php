<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">HS Master Compliance Export</h2>
            <div class="flex items-center space-x-3">
                <a href="/admin/hs-master-export?format=csv" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    CSV
                </a>
                <a href="/admin/hs-master-export?format=xlsx" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    Excel
                </a>
                <a href="/admin/hs-master-export?format=json" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    JSON
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 mb-6">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="text-center p-4 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg">
                        <p class="text-3xl font-bold text-emerald-700 dark:text-emerald-400">{{ $totalCount }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Total HS Codes</p>
                    </div>
                    <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <p class="text-3xl font-bold text-blue-700 dark:text-blue-400">{{ collect($records)->where('sroRequired', true)->count() }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">SRO Required</p>
                    </div>
                    <div class="text-center p-4 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                        <p class="text-3xl font-bold text-amber-700 dark:text-amber-400">{{ collect($records)->where('mrpRequired', true)->count() }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">MRP Required</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">#</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">HS Code</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Description</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">PCT Code</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Schedule</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Tax %</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">UOM</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">SRO Req</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">SRO #</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">MRP Req</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Source</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($records as $index => $record)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-800 dark:hover:bg-gray-700/50 transition">
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $index + 1 }}</td>
                                <td class="px-4 py-3 text-sm font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $record['hsCode'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 max-w-xs truncate">{{ $record['description'] }}</td>
                                <td class="px-4 py-3 text-sm font-mono text-gray-600 dark:text-gray-400">{{ $record['pctCode'] ?? '-' }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @php
                                        $badgeColors = [
                                            'standard' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                            '3rd_schedule' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
                                            'exempt' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                            'zero_rated' => 'bg-gray-100 text-gray-700 dark:bg-gray-600 dark:text-gray-200',
                                            'reduced' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                        ];
                                        $color = $badgeColors[$record['scheduleType']] ?? 'bg-gray-100 text-gray-700 dark:text-gray-300';
                                    @endphp
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold {{ $color }}">{{ ucwords(str_replace('_', ' ', $record['scheduleType'])) }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ $record['taxRate'] }}%</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $record['defaultUom'] ?? '-' }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @if($record['sroRequired'])
                                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">Yes</span>
                                    @else
                                        <span class="text-gray-400 text-xs">No</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $record['sroNumber'] ?? '-' }}</td>
                                <td class="px-4 py-3 text-sm">
                                    @if($record['mrpRequired'])
                                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">Yes</span>
                                    @else
                                        <span class="text-gray-400 text-xs">No</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-400">{{ $record['source'] }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400">
                Total HS Codes: <strong>{{ $totalCount }}</strong> | Exported at {{ now()->format('d M Y H:i:s') }}
            </div>
        </div>
    </div>
</x-admin-layout>
