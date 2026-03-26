<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">HS Master Global Control</h2>
            <a href="{{ route('admin.hs-master-global.unmapped') }}" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-medium hover:bg-amber-700 transition">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                Unmapped Queue ({{ $stats['unmapped_queue'] }})
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ $stats['total'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Total HS Codes</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400">{{ $stats['active'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Active</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-amber-600 dark:text-amber-400">{{ $stats['unmapped_queue'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Unmapped Queue</p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <form method="GET" action="{{ route('admin.hs-master-global.index') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search HS code or description..."
                            class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                        <select name="schedule_type" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                            <option value="">All Schedules</option>
                            @foreach($scheduleTypes as $st)
                                <option value="{{ $st }}" {{ request('schedule_type') === $st ? 'selected' : '' }}>{{ ucwords(str_replace('_', ' ', $st)) }}</option>
                            @endforeach
                        </select>
                        <select name="tax_rate" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                            <option value="">All Tax Rates</option>
                            @foreach($taxRates as $rate)
                                <option value="{{ $rate }}" {{ request('tax_rate') == $rate ? 'selected' : '' }}>{{ $rate }}%</option>
                            @endforeach
                        </select>
                        <button type="submit" class="bg-emerald-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-emerald-700 transition">Search</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">HS Code</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">Description</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">Schedule</th>
                                <th class="px-4 py-3 text-center font-semibold text-gray-600 dark:text-gray-300">Tax Rate</th>
                                <th class="px-4 py-3 text-center font-semibold text-gray-600 dark:text-gray-300">Confidence</th>
                                <th class="px-4 py-3 text-center font-semibold text-gray-600 dark:text-gray-300">Active</th>
                                <th class="px-4 py-3 text-center font-semibold text-gray-600 dark:text-gray-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($records as $r)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-800 dark:hover:bg-gray-700/50 transition">
                                    <td class="px-4 py-3 font-mono font-semibold text-emerald-700 dark:text-emerald-400">{{ $r->hs_code }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300 max-w-xs truncate">{{ $r->description ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        @if($r->schedule_type)
                                            <span class="px-2 py-1 rounded-full text-xs font-medium
                                                @if($r->schedule_type === '3rd_schedule') bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300
                                                @elseif($r->schedule_type === 'exempt') bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300
                                                @elseif($r->schedule_type === 'zero_rated') bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300
                                                @else bg-gray-100 text-gray-700 dark:bg-gray-600 dark:text-gray-300
                                                @endif">
                                                {{ ucwords(str_replace('_', ' ', $r->schedule_type)) }}
                                            </span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center font-medium text-gray-700 dark:text-gray-300">{{ $r->default_tax_rate !== null ? $r->default_tax_rate . '%' : '—' }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium
                                            @if($r->confidence_score >= 80) bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300
                                            @elseif($r->confidence_score >= 50) bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300
                                            @else bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300
                                            @endif">
                                            {{ $r->confidence_score }}%
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if($r->is_active)
                                            <span class="inline-block w-3 h-3 rounded-full bg-green-500" title="Active"></span>
                                        @else
                                            <span class="inline-block w-3 h-3 rounded-full bg-red-500" title="Inactive"></span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <a href="{{ route('admin.hs-master-global.edit', $r->id) }}" class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-medium hover:bg-blue-700 transition">
                                            <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">
                                        <p class="text-lg font-medium">No HS codes found</p>
                                        <p class="text-sm mt-1">Add records to the HS Master Global table to get started.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                    {{ $records->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
