<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">SRO & Serial Number Reference</h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <div class="text-2xl font-bold text-emerald-600">{{ $stats['total_sro_rules'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mt-1">SRO Rules</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <div class="text-2xl font-bold text-blue-600">{{ $stats['total_hs_sro'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mt-1">HS Codes (SRO Required)</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <div class="text-2xl font-bold text-amber-600">{{ $stats['total_hs_serial'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mt-1">HS Codes (Serial Required)</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <div class="text-2xl font-bold text-purple-600">{{ count($stats['schedule_breakdown']) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mt-1">Schedule Types</div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
                <form method="GET" action="/sro-reference" class="p-4">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <div class="flex flex-col md:flex-row gap-3">
                        <div class="flex-1">
                            <input type="text" name="search" value="{{ $search }}" placeholder="Search by SRO number, serial no, HS code, or description..."
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                        </div>
                        <div class="w-full md:w-48">
                            <select name="schedule_type" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                                <option value="">All Schedule Types</option>
                                @foreach($scheduleTypes as $key => $config)
                                <option value="{{ $key }}" {{ $scheduleFilter === $key ? 'selected' : '' }}>{{ $config['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="px-5 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Search</button>
                        @if($search || $scheduleFilter)
                        <a href="/sro-reference?tab={{ $tab }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-500 transition text-center">Clear</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="border-b border-gray-200 dark:border-gray-700">
                    <nav class="flex -mb-px">
                        <a href="/sro-reference?tab=sro&search={{ $search }}&schedule_type={{ $scheduleFilter }}"
                            class="px-6 py-3 text-sm font-medium border-b-2 transition {{ $tab === 'sro' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300' }}">
                            SRO Rules
                            <span class="ml-1 px-2 py-0.5 rounded-full text-xs {{ $tab === 'sro' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' }}">{{ $sroRules->total() }}</span>
                        </a>
                        <a href="/sro-reference?tab=hs&search={{ $search }}&schedule_type={{ $scheduleFilter }}"
                            class="px-6 py-3 text-sm font-medium border-b-2 transition {{ $tab === 'hs' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300' }}">
                            HS Code Requirements
                            <span class="ml-1 px-2 py-0.5 rounded-full text-xs {{ $tab === 'hs' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' }}">{{ $hsWithSro->total() }}</span>
                        </a>
                        <a href="/sro-reference?tab=guide"
                            class="px-6 py-3 text-sm font-medium border-b-2 transition {{ $tab === 'guide' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300' }}">
                            Quick Guide
                        </a>
                    </nav>
                </div>

                @if($tab === 'sro')
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">SRO Number</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Serial No</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">HS Code</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Schedule</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Rate</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Sector</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($sroRules as $rule)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition cursor-pointer" x-data="{ expanded: false }" @click="expanded = !expanded">
                                <td class="px-4 py-3">
                                    <span class="font-mono text-sm font-semibold text-emerald-700 dark:text-emerald-400">{{ $rule->sro_number }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-mono text-sm text-gray-800 dark:text-gray-200">{{ $rule->serial_no ?? '-' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-mono text-sm text-blue-600 dark:text-blue-400">{{ $rule->hs_code }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $colors = [
                                            'exempt' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
                                            'zero_rated' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
                                            '3rd_schedule' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
                                            'reduced' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
                                            'standard' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                        ];
                                    @endphp
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $colors[$rule->schedule_type] ?? $colors['standard'] }}">
                                        {{ ucwords(str_replace('_', ' ', $rule->schedule_type)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                    {{ $rule->concessionary_rate !== null ? $rule->concessionary_rate . '%' : '-' }}
                                </td>
                                <td class="px-4 py-3">
                                    @if($rule->applicable_sector)
                                    <span class="px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400">{{ $rule->applicable_sector }}</span>
                                    @else
                                    <span class="text-gray-400 text-sm">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 max-w-xs truncate">{{ $rule->description }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">
                                    <svg class="mx-auto h-10 w-10 text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                    <p class="font-medium">No SRO rules found</p>
                                    <p class="text-sm mt-1">Try adjusting your search or filters</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $sroRules->links() }}</div>
                @endif

                @if($tab === 'hs')
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">HS Code</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Description</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Schedule</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tax Rate</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">SRO Required</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Default SRO</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Serial Required</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">UOM</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($hsWithSro as $hs)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-4 py-3">
                                    <span class="font-mono text-sm font-semibold text-blue-600 dark:text-blue-400">{{ $hs->hs_code }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 max-w-xs truncate">{{ $hs->description }}</td>
                                <td class="px-4 py-3">
                                    @php
                                        $colors = [
                                            'exempt' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
                                            'zero_rated' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
                                            '3rd_schedule' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
                                            'reduced' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
                                            'standard' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                        ];
                                    @endphp
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $colors[$hs->schedule_type] ?? $colors['standard'] }}">
                                        {{ ucwords(str_replace('_', ' ', $hs->schedule_type ?? 'N/A')) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-200">{{ $hs->default_tax_rate !== null ? $hs->default_tax_rate . '%' : '-' }}</td>
                                <td class="px-4 py-3">
                                    @if($hs->sro_required)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">Required</span>
                                    @else
                                    <span class="text-gray-400 text-sm">Optional</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if($hs->default_sro_number)
                                    <span class="font-mono text-xs text-emerald-700 dark:text-emerald-400">{{ $hs->default_sro_number }}</span>
                                    @else
                                    <span class="text-gray-400 text-sm">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if($hs->serial_required)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">Required</span>
                                    @else
                                    <span class="text-gray-400 text-sm">No</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $hs->default_uom ?? '-' }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">
                                    <svg class="mx-auto h-10 w-10 text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                    <p class="font-medium">No HS codes with SRO/Serial requirements found</p>
                                    <p class="text-sm mt-1">Try adjusting your search or filters</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $hsWithSro->links() }}</div>
                @endif

                @if($tab === 'guide')
                <div class="p-6 space-y-6">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3">Schedule Types & Requirements</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach($scheduleTypes as $key => $config)
                            <div class="rounded-xl border border-gray-200 dark:border-gray-600 p-4 hover:shadow-md transition">
                                @php
                                    $cardColors = [
                                        'standard' => 'border-l-4 border-l-gray-400',
                                        'reduced' => 'border-l-4 border-l-amber-400',
                                        '3rd_schedule' => 'border-l-4 border-l-purple-400',
                                        'exempt' => 'border-l-4 border-l-green-400',
                                        'zero_rated' => 'border-l-4 border-l-blue-400',
                                    ];
                                @endphp
                                <div class="{{ $cardColors[$key] ?? '' }} pl-3">
                                    <h4 class="font-semibold text-gray-800 dark:text-gray-100">{{ $config['label'] }}</h4>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Tax Rate: {{ $config['tax_rate'] !== null ? $config['tax_rate'] . '%' : 'Company Standard' }}</p>
                                    <div class="mt-3 space-y-1.5">
                                        <div class="flex items-center text-sm">
                                            @if($config['requires_sro'])
                                            <svg class="w-4 h-4 text-red-500 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                            <span class="text-red-700 dark:text-red-400 font-medium">SRO Required</span>
                                            @else
                                            <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>
                                            <span class="text-gray-500 dark:text-gray-400">SRO Not Required</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center text-sm">
                                            @if($config['requires_serial'])
                                            <svg class="w-4 h-4 text-red-500 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                            <span class="text-red-700 dark:text-red-400 font-medium">Serial No Required</span>
                                            @else
                                            <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>
                                            <span class="text-gray-500 dark:text-gray-400">Serial No Not Required</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center text-sm">
                                            @if($config['requires_mrp'])
                                            <svg class="w-4 h-4 text-red-500 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                            <span class="text-red-700 dark:text-red-400 font-medium">MRP Required</span>
                                            @else
                                            <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>
                                            <span class="text-gray-500 dark:text-gray-400">MRP Not Required</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3">Common SRO Numbers</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-xl p-4 border border-green-200 dark:border-green-800">
                                <h4 class="font-bold text-green-800 dark:text-green-400">SRO 551(I)/2008</h4>
                                <p class="text-sm text-green-700 dark:text-green-300 mt-1">6th Schedule - Exempt goods including essential food items, medicines, agricultural inputs, educational materials, and energy equipment</p>
                            </div>
                            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4 border border-blue-200 dark:border-blue-800">
                                <h4 class="font-bold text-blue-800 dark:text-blue-400">SRO 1125(I)/2011</h4>
                                <p class="text-sm text-blue-700 dark:text-blue-300 mt-1">5th Schedule - Zero rated goods for export-oriented sectors including textile, leather, carpets, and surgical instruments</p>
                            </div>
                            <div class="bg-purple-50 dark:bg-purple-900/20 rounded-xl p-4 border border-purple-200 dark:border-purple-800">
                                <h4 class="font-bold text-purple-800 dark:text-purple-400">SRO 693(I)/2006</h4>
                                <p class="text-sm text-purple-700 dark:text-purple-300 mt-1">3rd Schedule - Goods taxed at retail price (MRP) including vehicles, electronics, home appliances, and mobile phones</p>
                            </div>
                            <div class="bg-amber-50 dark:bg-amber-900/20 rounded-xl p-4 border border-amber-200 dark:border-amber-800">
                                <h4 class="font-bold text-amber-800 dark:text-amber-400">SRO 648(I)/2013</h4>
                                <p class="text-sm text-amber-700 dark:text-amber-300 mt-1">Reduced rate goods - Petroleum products, LPG, and natural gas at concessionary 10% rate</p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3">How to Use</h3>
                        <div class="space-y-3">
                            <div class="flex items-start space-x-3">
                                <div class="w-7 h-7 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <span class="text-sm font-bold text-emerald-700 dark:text-emerald-400">1</span>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-800 dark:text-gray-100">Invoice banate waqt</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Jab aap invoice mein item add karein aur schedule type select karein, to SRO aur Serial fields automatically show ho jayen gi agar zaroorat ho</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-7 h-7 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <span class="text-sm font-bold text-emerald-700 dark:text-emerald-400">2</span>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-800 dark:text-gray-100">SRO Number dhoondne ke liye</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Invoice form mein "SRO Reference" button pe click karein ya is page pe aayein aur HS code ya product category se search karein</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-7 h-7 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <span class="text-sm font-bold text-emerald-700 dark:text-emerald-400">3</span>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-800 dark:text-gray-100">Copy karein</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">SRO number aur serial number yahan se copy karein aur invoice mein paste kar dein</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
