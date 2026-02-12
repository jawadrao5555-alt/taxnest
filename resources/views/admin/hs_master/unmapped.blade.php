<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Unmapped HS Queue</h2>
            <a href="{{ route('admin.hs-master-global.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-500 text-white rounded-lg text-sm font-medium hover:bg-gray-600 transition">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                Back to HS Master
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">HS Code</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">Company</th>
                                <th class="px-4 py-3 text-center font-semibold text-gray-600 dark:text-gray-300">Usage Count</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">First Seen</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">Reason</th>
                                <th class="px-4 py-3 text-center font-semibold text-gray-600 dark:text-gray-300">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700" x-data>
                            @forelse($records as $r)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                    <td class="px-4 py-3 font-mono font-semibold text-amber-700 dark:text-amber-400">{{ $r->hs_code }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $r->company->business_name ?? 'Company #' . $r->company_id }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ $r->usage_count }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $r->first_seen_at?->format('M d, Y') ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $r->flagged_reason ?? '—' }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <div x-data="{ open: false }" class="relative inline-block">
                                            <button @click="open = !open" type="button" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-medium hover:bg-emerald-700 transition">
                                                <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                                Map
                                            </button>
                                            <div x-show="open" @click.away="open = false" x-cloak
                                                class="absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 p-4 z-50">
                                                <form method="POST" action="{{ route('admin.hs-master-global.map', $r->id) }}" class="space-y-3">
                                                    @csrf
                                                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Map {{ $r->hs_code }} to Master</p>
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Description</label>
                                                        <input type="text" name="description" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    </div>
                                                    <div class="grid grid-cols-2 gap-2">
                                                        <div>
                                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Schedule</label>
                                                            <select name="schedule_type" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                                <option value="">None</option>
                                                                <option value="standard">Standard</option>
                                                                <option value="3rd_schedule">3rd Schedule</option>
                                                                <option value="exempt">Exempt</option>
                                                                <option value="zero_rated">Zero Rated</option>
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tax Rate (%)</label>
                                                            <input type="number" step="0.01" min="0" max="99.99" name="default_tax_rate" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                        </div>
                                                    </div>
                                                    <div class="flex flex-wrap gap-3 text-xs">
                                                        <label class="flex items-center space-x-1">
                                                            <input type="checkbox" name="sro_required" value="1" class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                                            <span class="text-gray-600 dark:text-gray-400">SRO</span>
                                                        </label>
                                                        <label class="flex items-center space-x-1">
                                                            <input type="checkbox" name="serial_required" value="1" class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                                            <span class="text-gray-600 dark:text-gray-400">Serial</span>
                                                        </label>
                                                        <label class="flex items-center space-x-1">
                                                            <input type="checkbox" name="mrp_required" value="1" class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                                            <span class="text-gray-600 dark:text-gray-400">MRP</span>
                                                        </label>
                                                        <label class="flex items-center space-x-1">
                                                            <input type="checkbox" name="st_withheld_applicable" value="1" class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                                            <span class="text-gray-600 dark:text-gray-400">ST Withheld</span>
                                                        </label>
                                                        <label class="flex items-center space-x-1">
                                                            <input type="checkbox" name="petroleum_levy_applicable" value="1" class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                                            <span class="text-gray-600 dark:text-gray-400">Petroleum</span>
                                                        </label>
                                                    </div>
                                                    <button type="submit" class="w-full py-2 bg-emerald-600 text-white rounded-lg text-xs font-medium hover:bg-emerald-700 transition">Create & Remove from Queue</button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">
                                        <p class="text-lg font-medium">No unmapped HS codes</p>
                                        <p class="text-sm mt-1">All HS codes are currently mapped in the master.</p>
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
