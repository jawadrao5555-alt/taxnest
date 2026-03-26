<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Global HS Intelligence Control</h2>
            <div class="flex items-center space-x-3">
                <form method="POST" action="/admin/hs-master/seed" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                        <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                        Sync From Sources
                    </button>
                </form>
                <a href="/admin/hs-master-export" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    Export
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ $insights['total_hs'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Total HS Codes</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-red-600 dark:text-red-400">{{ $insights['total_unmapped'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Unmapped HS Codes</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400">{{ $insights['sro_required'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">SRO Required</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-amber-600 dark:text-amber-400">{{ $insights['mrp_required'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">MRP Required</p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="border-b border-gray-200 dark:border-gray-700">
                    <nav class="flex -mb-px">
                        <a href="/admin/hs-master?tab=all" class="px-6 py-4 text-sm font-medium border-b-2 {{ $tab === 'all' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' }}">
                            All HS ({{ $insights['total_hs'] }})
                        </a>
                        <a href="/admin/hs-master?tab=unmapped" class="px-6 py-4 text-sm font-medium border-b-2 {{ $tab === 'unmapped' ? 'border-red-500 text-red-600 dark:text-red-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' }}">
                            Unmapped HS ({{ $insights['total_unmapped'] }})
                        </a>
                        <a href="/admin/hs-master?tab=insights" class="px-6 py-4 text-sm font-medium border-b-2 {{ $tab === 'insights' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' }}">
                            Intelligence Insights
                        </a>
                    </nav>
                </div>

                <div class="p-6">
                    @if($tab === 'all')
                        <form method="GET" action="/admin/hs-master" class="mb-6 grid grid-cols-1 sm:grid-cols-5 gap-3">
                            <input type="hidden" name="tab" value="all">
                            <input type="text" name="search" value="{{ $search }}" placeholder="Search HS, description, SRO..." class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                            <select name="schedule" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                <option value="">All Schedules</option>
                                @foreach($scheduleTypes as $st)
                                    <option value="{{ $st }}" {{ request('schedule') === $st ? 'selected' : '' }}>{{ ucwords(str_replace('_', ' ', $st)) }}</option>
                                @endforeach
                            </select>
                            <select name="sector" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                <option value="">All Sectors</option>
                                @foreach($sectors as $sector)
                                    <option value="{{ $sector }}" {{ request('sector') === $sector ? 'selected' : '' }}>{{ $sector }}</option>
                                @endforeach
                            </select>
                            <select name="mapping" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                <option value="">All Status</option>
                                <option value="Mapped" {{ request('mapping') === 'Mapped' ? 'selected' : '' }}>Mapped</option>
                                <option value="Partial" {{ request('mapping') === 'Partial' ? 'selected' : '' }}>Partial</option>
                                <option value="Unmapped" {{ request('mapping') === 'Unmapped' ? 'selected' : '' }}>Unmapped</option>
                            </select>
                            <button type="submit" class="bg-emerald-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-emerald-700">Search</button>
                        </form>

                        <div x-data="{ showAdd: false }" class="mb-4">
                            <button @click="showAdd = !showAdd" class="text-sm text-emerald-600 dark:text-emerald-400 hover:underline font-medium">+ Add New HS Code</button>
                            <div x-show="showAdd" x-cloak class="mt-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <form method="POST" action="/admin/hs-master" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                                    @csrf
                                    <input type="text" name="hs_code" placeholder="HS Code" required class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <input type="text" name="description" placeholder="Description" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <input type="text" name="pct_code" placeholder="PCT Code" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <select name="schedule_type" required class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                        @foreach($scheduleTypes as $st)
                                            <option value="{{ $st }}">{{ ucwords(str_replace('_', ' ', $st)) }}</option>
                                        @endforeach
                                    </select>
                                    <input type="number" name="tax_rate" placeholder="Tax Rate %" step="0.01" min="0" max="100" value="18" required class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <input type="text" name="default_uom" placeholder="UOM" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <input type="text" name="sro_number" placeholder="SRO Number" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <input type="text" name="sro_item_serial_no" placeholder="SRO Serial No" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <input type="text" name="sector_tag" placeholder="Sector Tag" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <input type="number" name="risk_weight" placeholder="Risk Weight" step="0.01" min="0" max="100" value="0" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <select name="mapping_status" required class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                        <option value="Mapped">Mapped</option>
                                        <option value="Partial">Partial</option>
                                        <option value="Unmapped">Unmapped</option>
                                    </select>
                                    <button type="submit" class="bg-emerald-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-emerald-700">Add HS Code</button>
                                </form>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                        <th class="pb-3 pr-4">HS Code</th>
                                        <th class="pb-3 pr-4">Description</th>
                                        <th class="pb-3 pr-4">Schedule</th>
                                        <th class="pb-3 pr-4">Tax Rate</th>
                                        <th class="pb-3 pr-4">SRO</th>
                                        <th class="pb-3 pr-4">Sector</th>
                                        <th class="pb-3 pr-4">Status</th>
                                        <th class="pb-3 pr-4">Risk</th>
                                        <th class="pb-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @forelse($allHs as $hs)
                                        <tr x-data="{ editing: false }" class="text-gray-700 dark:text-gray-300">
                                            <td class="py-3 pr-4 font-mono text-xs">{{ $hs->hs_code }}</td>
                                            <td class="py-3 pr-4 max-w-[200px] truncate">{{ $hs->description ?? 'N/A' }}</td>
                                            <td class="py-3 pr-4">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                                    {{ $hs->schedule_type === 'exempt' ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300' : '' }}
                                                    {{ $hs->schedule_type === '3rd_schedule' ? 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300' : '' }}
                                                    {{ $hs->schedule_type === 'standard' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300' : '' }}
                                                    {{ $hs->schedule_type === 'zero_rated' ? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300' : '' }}
                                                    {{ $hs->schedule_type === 'reduced' ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300' : '' }}
                                                ">{{ ucwords(str_replace('_', ' ', $hs->schedule_type)) }}</span>
                                            </td>
                                            <td class="py-3 pr-4">{{ $hs->tax_rate }}%</td>
                                            <td class="py-3 pr-4 text-xs">{{ $hs->sro_number ?? '-' }}</td>
                                            <td class="py-3 pr-4 text-xs">{{ $hs->sector_tag ?? '-' }}</td>
                                            <td class="py-3 pr-4">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                                    {{ $hs->mapping_status === 'Mapped' ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300' : '' }}
                                                    {{ $hs->mapping_status === 'Partial' ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300' : '' }}
                                                    {{ $hs->mapping_status === 'Unmapped' ? 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300' : '' }}
                                                ">{{ $hs->mapping_status }}</span>
                                            </td>
                                            <td class="py-3 pr-4">
                                                @if($hs->risk_weight > 60)
                                                    <span class="text-red-600 dark:text-red-400 font-medium">{{ $hs->risk_weight }}</span>
                                                @elseif($hs->risk_weight > 30)
                                                    <span class="text-amber-600 dark:text-amber-400">{{ $hs->risk_weight }}</span>
                                                @else
                                                    <span class="text-gray-500">{{ $hs->risk_weight }}</span>
                                                @endif
                                            </td>
                                            <td class="py-3">
                                                <button @click="editing = !editing" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">Edit</button>
                                            </td>
                                        </tr>
                                        <tr x-show="editing" x-cloak>
                                            <td colspan="9" class="py-3">
                                                <form method="POST" action="/admin/hs-master/{{ $hs->id }}" class="grid grid-cols-1 sm:grid-cols-5 gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="text" name="description" value="{{ $hs->description }}" placeholder="Description" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <select name="schedule_type" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                        @foreach($scheduleTypes as $st)
                                                            <option value="{{ $st }}" {{ $hs->schedule_type === $st ? 'selected' : '' }}>{{ ucwords(str_replace('_', ' ', $st)) }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input type="number" name="tax_rate" value="{{ $hs->tax_rate }}" step="0.01" min="0" max="100" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <input type="text" name="sro_number" value="{{ $hs->sro_number }}" placeholder="SRO" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <input type="text" name="sro_item_serial_no" value="{{ $hs->sro_item_serial_no }}" placeholder="Serial" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <input type="text" name="pct_code" value="{{ $hs->pct_code }}" placeholder="PCT" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <input type="text" name="default_uom" value="{{ $hs->default_uom }}" placeholder="UOM" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <input type="text" name="sector_tag" value="{{ $hs->sector_tag }}" placeholder="Sector" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <input type="number" name="risk_weight" value="{{ $hs->risk_weight }}" step="0.01" min="0" max="100" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <select name="mapping_status" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                        <option value="Mapped" {{ $hs->mapping_status === 'Mapped' ? 'selected' : '' }}>Mapped</option>
                                                        <option value="Partial" {{ $hs->mapping_status === 'Partial' ? 'selected' : '' }}>Partial</option>
                                                        <option value="Unmapped" {{ $hs->mapping_status === 'Unmapped' ? 'selected' : '' }}>Unmapped</option>
                                                    </select>
                                                    <button type="submit" class="bg-blue-600 text-white rounded px-3 py-1 text-xs font-medium hover:bg-blue-700">Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="9" class="py-8 text-center text-gray-400 dark:text-gray-500">No HS codes found. Click "Sync From Sources" to populate.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4">{{ $allHs->links() }}</div>

                    @elseif($tab === 'unmapped')
                        <div class="mb-4">
                            <form method="GET" action="/admin/hs-master" class="flex items-center space-x-3">
                                <input type="hidden" name="tab" value="unmapped">
                                <input type="text" name="search" value="{{ $search }}" placeholder="Search unmapped HS code..." class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm flex-1">
                                <button type="submit" class="bg-red-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-red-700">Search</button>
                            </form>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                        <th class="pb-3 pr-4">HS Code</th>
                                        <th class="pb-3 pr-4">Frequency</th>
                                        <th class="pb-3 pr-4">Companies</th>
                                        <th class="pb-3 pr-4">First Seen</th>
                                        <th class="pb-3 pr-4">Last Seen</th>
                                        <th class="pb-3">Quick Map</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @forelse($unmappedHs as $um)
                                        <tr x-data="{ mapping: false }" class="text-gray-700 dark:text-gray-300">
                                            <td class="py-3 pr-4 font-mono text-xs font-medium">{{ $um->hs_code }}</td>
                                            <td class="py-3 pr-4">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300">
                                                    {{ $um->total_frequency }}x
                                                </span>
                                            </td>
                                            <td class="py-3 pr-4">{{ $um->company_count }}</td>
                                            <td class="py-3 pr-4 text-xs">{{ $um->earliest_seen ? \Carbon\Carbon::parse($um->earliest_seen)->format('M d, Y') : '-' }}</td>
                                            <td class="py-3 pr-4 text-xs">{{ $um->latest_seen ? \Carbon\Carbon::parse($um->latest_seen)->format('M d, Y') : '-' }}</td>
                                            <td class="py-3">
                                                <button @click="mapping = !mapping" class="text-xs text-emerald-600 dark:text-emerald-400 hover:underline font-medium">Map Now</button>
                                            </td>
                                        </tr>
                                        <tr x-show="mapping" x-cloak>
                                            <td colspan="6" class="py-3">
                                                <form method="POST" action="/admin/hs-master/map-unmapped" class="grid grid-cols-1 sm:grid-cols-5 gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                                    @csrf
                                                    <input type="hidden" name="hs_code" value="{{ $um->hs_code }}">
                                                    <input type="text" name="description" placeholder="Description" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <select name="schedule_type" required class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                        @foreach($scheduleTypes as $st)
                                                            <option value="{{ $st }}">{{ ucwords(str_replace('_', ' ', $st)) }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input type="number" name="tax_rate" placeholder="Tax Rate %" step="0.01" min="0" max="100" value="18" required class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <input type="text" name="sector_tag" placeholder="Sector Tag" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-xs">
                                                    <button type="submit" class="bg-emerald-600 text-white rounded px-3 py-1 text-xs font-medium hover:bg-emerald-700">Map & Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="py-8 text-center text-gray-400 dark:text-gray-500">No unmapped HS codes detected. All HS codes are mapped.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4">{{ $unmappedHs->links() }}</div>

                    @elseif($tab === 'insights')
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-5">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Schedule Type Distribution</h3>
                                <div class="space-y-3">
                                    @foreach($insights['by_schedule'] as $schedule => $count)
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ ucwords(str_replace('_', ' ', $schedule)) }}</span>
                                            <div class="flex items-center space-x-2">
                                                <div class="w-32 bg-gray-200 dark:bg-gray-600 rounded-full h-2">
                                                    <div class="bg-emerald-500 h-2 rounded-full" style="width: {{ $insights['total_hs'] > 0 ? ($count / $insights['total_hs']) * 100 : 0 }}%"></div>
                                                </div>
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-8 text-right">{{ $count }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-5">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Mapping Status</h3>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600 dark:text-gray-400">Mapped</span>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">{{ $insights['mapped'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600 dark:text-gray-400">Partial</span>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300">{{ $insights['partial'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600 dark:text-gray-400">Unmapped (Master)</span>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300">{{ $insights['unmapped_master'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-gray-600 dark:text-gray-400">Unmapped (Logged)</span>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300">{{ $insights['total_unmapped'] }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-5">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Risk Distribution</h3>
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-green-600 dark:text-green-400">Low Risk (0-30)</span>
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $insights['risk_distribution']['low'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-amber-600 dark:text-amber-400">Medium Risk (30-60)</span>
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $insights['risk_distribution']['medium'] }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-red-600 dark:text-red-400">High Risk (60+)</span>
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $insights['risk_distribution']['high'] }}</span>
                                    </div>
                                </div>
                            </div>

                            @if(!empty($insights['by_sector']))
                            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-5">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Sector Distribution</h3>
                                <div class="space-y-3">
                                    @foreach($insights['by_sector'] as $sector => $count)
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ $sector }}</span>
                                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $count }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @endif

                            @if(!empty($insights['top_unmapped']))
                            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-5 md:col-span-2">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Top Unmapped HS Codes (by frequency)</h3>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                                                <th class="pb-2 pr-4">HS Code</th>
                                                <th class="pb-2 pr-4">Total Usage</th>
                                                <th class="pb-2">Companies Using</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-600">
                                            @foreach($insights['top_unmapped'] as $top)
                                                <tr class="text-gray-700 dark:text-gray-300">
                                                    <td class="py-2 pr-4 font-mono text-xs">{{ $top['hs_code'] }}</td>
                                                    <td class="py-2 pr-4">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300">{{ $top['total_freq'] }}x</span>
                                                    </td>
                                                    <td class="py-2">{{ $top['company_count'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            @endif

                            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-5 md:col-span-2">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Validation Rules Summary</h3>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                                                <th class="pb-2 pr-4">Schedule Type</th>
                                                <th class="pb-2 pr-4">Condition</th>
                                                <th class="pb-2 pr-4">SRO Required</th>
                                                <th class="pb-2 pr-4">Serial Required</th>
                                                <th class="pb-2">MRP Required</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-600 text-gray-700 dark:text-gray-300">
                                            <tr>
                                                <td class="py-2 pr-4">3rd Schedule</td>
                                                <td class="py-2 pr-4 text-xs">Tax Rate &lt; 18%</td>
                                                <td class="py-2 pr-4"><span class="text-red-600 dark:text-red-400">Yes</span></td>
                                                <td class="py-2 pr-4"><span class="text-red-600 dark:text-red-400">Yes</span></td>
                                                <td class="py-2"><span class="text-red-600 dark:text-red-400">Yes</span></td>
                                            </tr>
                                            <tr>
                                                <td class="py-2 pr-4">3rd Schedule</td>
                                                <td class="py-2 pr-4 text-xs">Tax Rate = 18%</td>
                                                <td class="py-2 pr-4"><span class="text-green-600 dark:text-green-400">No</span></td>
                                                <td class="py-2 pr-4"><span class="text-green-600 dark:text-green-400">No</span></td>
                                                <td class="py-2"><span class="text-red-600 dark:text-red-400">Yes</span></td>
                                            </tr>
                                            <tr>
                                                <td class="py-2 pr-4">Exempt</td>
                                                <td class="py-2 pr-4 text-xs">Always</td>
                                                <td class="py-2 pr-4"><span class="text-red-600 dark:text-red-400">Yes</span></td>
                                                <td class="py-2 pr-4"><span class="text-green-600 dark:text-green-400">No</span></td>
                                                <td class="py-2"><span class="text-green-600 dark:text-green-400">No</span></td>
                                            </tr>
                                            <tr>
                                                <td class="py-2 pr-4">Zero Rated</td>
                                                <td class="py-2 pr-4 text-xs">Always</td>
                                                <td class="py-2 pr-4"><span class="text-green-600 dark:text-green-400">No</span></td>
                                                <td class="py-2 pr-4"><span class="text-green-600 dark:text-green-400">No</span></td>
                                                <td class="py-2"><span class="text-green-600 dark:text-green-400">No</span></td>
                                            </tr>
                                            <tr>
                                                <td class="py-2 pr-4">Standard</td>
                                                <td class="py-2 pr-4 text-xs">0-18% dynamic</td>
                                                <td class="py-2 pr-4"><span class="text-green-600 dark:text-green-400">No</span></td>
                                                <td class="py-2 pr-4"><span class="text-green-600 dark:text-green-400">No</span></td>
                                                <td class="py-2"><span class="text-green-600 dark:text-green-400">No</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
