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

            <div class="space-y-6">
                @forelse($records as $r)
                    @php
                        $suggestion = $suggestions[$r->id] ?? null;
                        $rejection = $rejections[$r->id] ?? null;
                        $riskLevel = $suggestion ? \App\Services\HsIntelligenceService::getRiskLevel(is_object($suggestion) ? $suggestion->confidence_score : ($suggestion['confidence_score'] ?? 0)) : null;
                        $riskColor = $riskLevel ? \App\Services\HsIntelligenceService::getRiskColor($riskLevel) : 'gray';
                        $confScore = $suggestion ? (is_object($suggestion) ? $suggestion->confidence_score : ($suggestion['confidence_score'] ?? 0)) : 0;
                        $breakdown = $suggestion ? (is_object($suggestion) ? ($suggestion->weight_breakdown ?? []) : ($suggestion['weight_breakdown'] ?? [])) : [];
                        if (is_string($breakdown)) $breakdown = json_decode($breakdown, true) ?? [];
                    @endphp
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden" x-data="{ expanded: false, mapOpen: false, rejectOpen: false }">
                        <div class="p-5">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="flex items-center space-x-4">
                                    <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center">
                                        <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                                    </div>
                                    <div>
                                        <p class="font-mono text-lg font-bold text-amber-700 dark:text-amber-400">{{ $r->hs_code }}</p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $r->company->business_name ?? 'Company #' . $r->company_id }} &middot; Used {{ $r->usage_count }}x &middot; First seen {{ $r->first_seen_at?->format('M d, Y') ?? '—' }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2">
                                    @if($suggestion)
                                        <span class="px-3 py-1.5 rounded-full text-xs font-semibold
                                            @if($riskColor === 'green') bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300
                                            @elseif($riskColor === 'amber') bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300
                                            @elseif($riskColor === 'orange') bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300
                                            @else bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300
                                            @endif">
                                            Risk: {{ ucfirst($riskLevel) }} ({{ $confScore }}%)
                                        </span>
                                    @else
                                        <span class="px-3 py-1.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400">No Suggestion</span>
                                    @endif
                                    @if($rejection && $rejection->rejection_count > 0)
                                        <span class="px-3 py-1.5 rounded-full text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ $rejection->rejection_count }} Rejection(s)</span>
                                    @endif
                                </div>
                            </div>

                            @if($suggestion)
                                <div class="mt-4 p-4 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 rounded-lg">
                                    <div class="flex items-center justify-between mb-3">
                                        <h4 class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 flex items-center">
                                            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                                            Intelligence Suggestion
                                        </h4>
                                        <button @click="expanded = !expanded" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                            <span x-text="expanded ? 'Hide Breakdown' : 'Show Breakdown'"></span>
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-xs">
                                        <div>
                                            <span class="block text-gray-500 dark:text-gray-400">Schedule</span>
                                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ ucwords(str_replace('_', ' ', is_object($suggestion) ? $suggestion->suggested_schedule_type : ($suggestion['suggested_schedule_type'] ?? '—'))) }}</span>
                                        </div>
                                        <div>
                                            <span class="block text-gray-500 dark:text-gray-400">Tax Rate</span>
                                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ is_object($suggestion) ? $suggestion->suggested_tax_rate : ($suggestion['suggested_tax_rate'] ?? '—') }}%</span>
                                        </div>
                                        <div>
                                            <span class="block text-gray-500 dark:text-gray-400">SRO</span>
                                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ (is_object($suggestion) ? $suggestion->suggested_sro_required : ($suggestion['suggested_sro_required'] ?? false)) ? 'Required' : 'Not Required' }}</span>
                                        </div>
                                        <div>
                                            <span class="block text-gray-500 dark:text-gray-400">Serial</span>
                                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ (is_object($suggestion) ? $suggestion->suggested_serial_required : ($suggestion['suggested_serial_required'] ?? false)) ? 'Required' : 'Not Required' }}</span>
                                        </div>
                                        <div>
                                            <span class="block text-gray-500 dark:text-gray-400">MRP</span>
                                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ (is_object($suggestion) ? $suggestion->suggested_mrp_required : ($suggestion['suggested_mrp_required'] ?? false)) ? 'Required' : 'Not Required' }}</span>
                                        </div>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                        Based on {{ is_object($suggestion) ? $suggestion->based_on_records_count : ($suggestion['based_on_records_count'] ?? 0) }} records &middot; Confidence {{ $confScore }}%
                                    </div>

                                    <div x-show="expanded" x-cloak class="mt-3 pt-3 border-t border-indigo-200 dark:border-indigo-700">
                                        <h5 class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-2">Weight Breakdown</h5>
                                        <div class="space-y-2">
                                            @foreach($breakdown as $key => $detail)
                                                <div class="flex items-center justify-between text-xs">
                                                    <span class="text-gray-600 dark:text-gray-400">{{ ucwords(str_replace('_', ' ', $key)) }}</span>
                                                    <div class="flex items-center space-x-3">
                                                        <span class="text-gray-500 dark:text-gray-400">Weight: {{ $detail['weight'] ?? 0 }}%</span>
                                                        @if(isset($detail['records']))
                                                            <span class="text-gray-500 dark:text-gray-400">{{ $detail['records'] }} records</span>
                                                        @endif
                                                        @if(isset($detail['penalty']))
                                                            <span class="text-red-600 dark:text-red-400">-{{ $detail['penalty'] }} penalty</span>
                                                        @endif
                                                        @if(isset($detail['factor']))
                                                            <span class="text-green-600 dark:text-green-400">+{{ $detail['factor'] }} bonus</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                            @if(empty($breakdown))
                                                <p class="text-xs text-gray-400 dark:text-gray-500 italic">No breakdown data available.</p>
                                            @endif
                                        </div>

                                        @if($rejection && $rejection->rejection_count > 0)
                                            <div class="mt-3 pt-3 border-t border-indigo-200 dark:border-indigo-700">
                                                <h5 class="text-xs font-semibold text-red-600 dark:text-red-400 mb-1">Rejection History</h5>
                                                <p class="text-xs text-gray-600 dark:text-gray-400">{{ $rejection->rejection_count }} rejection(s) &middot; Last: {{ $rejection->last_rejection_reason ?? '—' }}</p>
                                                <p class="text-xs text-gray-400 dark:text-gray-500">Last seen: {{ $rejection->last_seen_at?->format('M d, Y H:i') ?? '—' }}</p>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button @click="mapOpen = !mapOpen" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                    Map to Master
                                </button>
                                @if($suggestion)
                                    <button @click="rejectOpen = !rejectOpen" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition">
                                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                        Reject Suggestion
                                    </button>
                                    <form method="POST" action="{{ route('admin.hs-master-global.regenerate', $r->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition">
                                            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                            Regenerate
                                        </button>
                                    </form>
                                @endif
                            </div>

                            <div x-show="rejectOpen" x-cloak class="mt-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg">
                                <form method="POST" action="{{ route('admin.hs-master-global.reject', $r->id) }}" class="space-y-3">
                                    @csrf
                                    <label class="block text-sm font-medium text-red-700 dark:text-red-300">Rejection Reason</label>
                                    <input type="text" name="rejection_reason" placeholder="Reason for rejecting this suggestion..." class="w-full rounded-lg border-red-300 dark:border-red-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition">Confirm Rejection</button>
                                </form>
                            </div>

                            <div x-show="mapOpen" x-cloak class="mt-4 p-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-lg">
                                <form method="POST" action="{{ route('admin.hs-master-global.map', $r->id) }}" class="space-y-4">
                                    @csrf
                                    <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">Map {{ $r->hs_code }} to HS Master</p>

                                    @if($suggestion)
                                        <div class="flex items-center space-x-3 mb-3">
                                            <label class="inline-flex items-center space-x-2">
                                                <input type="radio" name="decision_type" value="suggestion_accepted" checked class="text-emerald-600 border-gray-300 dark:border-gray-600">
                                                <span class="text-sm text-gray-700 dark:text-gray-300">Accept Suggestion</span>
                                            </label>
                                            <label class="inline-flex items-center space-x-2">
                                                <input type="radio" name="decision_type" value="manual_override" class="text-emerald-600 border-gray-300 dark:border-gray-600">
                                                <span class="text-sm text-gray-700 dark:text-gray-300">Manual Override</span>
                                            </label>
                                        </div>
                                    @else
                                        <input type="hidden" name="decision_type" value="manual">
                                    @endif

                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Description</label>
                                        <input type="text" name="description" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                    </div>
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Schedule</label>
                                            <select name="schedule_type" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                                <option value="">None</option>
                                                <option value="standard" {{ ($suggestion && (is_object($suggestion) ? $suggestion->suggested_schedule_type : ($suggestion['suggested_schedule_type'] ?? '')) === 'standard') ? 'selected' : '' }}>Standard</option>
                                                <option value="3rd_schedule" {{ ($suggestion && (is_object($suggestion) ? $suggestion->suggested_schedule_type : ($suggestion['suggested_schedule_type'] ?? '')) === '3rd_schedule') ? 'selected' : '' }}>3rd Schedule</option>
                                                <option value="exempt" {{ ($suggestion && (is_object($suggestion) ? $suggestion->suggested_schedule_type : ($suggestion['suggested_schedule_type'] ?? '')) === 'exempt') ? 'selected' : '' }}>Exempt</option>
                                                <option value="zero_rated" {{ ($suggestion && (is_object($suggestion) ? $suggestion->suggested_schedule_type : ($suggestion['suggested_schedule_type'] ?? '')) === 'zero_rated') ? 'selected' : '' }}>Zero Rated</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tax Rate (%)</label>
                                            <input type="number" step="0.01" min="0" max="99.99" name="default_tax_rate" value="{{ $suggestion ? (is_object($suggestion) ? $suggestion->suggested_tax_rate : ($suggestion['suggested_tax_rate'] ?? '')) : '' }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">UOM</label>
                                            <select name="default_uom" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                                <option value="">Select</option>
                                                <option value="NOS">NOS</option>
                                                <option value="KGS">KGS</option>
                                                <option value="LTR">LTR</option>
                                                <option value="MTR">MTR</option>
                                                <option value="PCS">PCS</option>
                                                <option value="PKT">PKT</option>
                                                <option value="SET">SET</option>
                                                <option value="SQM">SQM</option>
                                                <option value="TON">TON</option>
                                                <option value="OTH">OTH</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Confidence</label>
                                            <input type="number" min="0" max="100" name="confidence_score" value="{{ $confScore ?: 50 }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-4 text-sm">
                                        <label class="flex items-center space-x-2">
                                            <input type="checkbox" name="sro_required" value="1" {{ ($suggestion && (is_object($suggestion) ? $suggestion->suggested_sro_required : ($suggestion['suggested_sro_required'] ?? false))) ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                            <span class="text-gray-600 dark:text-gray-400">SRO Required</span>
                                        </label>
                                        <label class="flex items-center space-x-2">
                                            <input type="checkbox" name="serial_required" value="1" {{ ($suggestion && (is_object($suggestion) ? $suggestion->suggested_serial_required : ($suggestion['suggested_serial_required'] ?? false))) ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                            <span class="text-gray-600 dark:text-gray-400">Serial Required</span>
                                        </label>
                                        <label class="flex items-center space-x-2">
                                            <input type="checkbox" name="mrp_required" value="1" {{ ($suggestion && (is_object($suggestion) ? $suggestion->suggested_mrp_required : ($suggestion['suggested_mrp_required'] ?? false))) ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                            <span class="text-gray-600 dark:text-gray-400">MRP Required</span>
                                        </label>
                                        <label class="flex items-center space-x-2">
                                            <input type="checkbox" name="st_withheld_applicable" value="1" class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                            <span class="text-gray-600 dark:text-gray-400">ST Withheld</span>
                                        </label>
                                        <label class="flex items-center space-x-2">
                                            <input type="checkbox" name="petroleum_levy_applicable" value="1" class="rounded border-gray-300 dark:border-gray-600 text-emerald-600">
                                            <span class="text-gray-600 dark:text-gray-400">Petroleum Levy</span>
                                        </label>
                                    </div>
                                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Create & Remove from Queue</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-12 text-center">
                        <svg class="w-12 h-12 text-green-400 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <p class="text-lg font-medium text-gray-700 dark:text-gray-300">No unmapped HS codes</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">All HS codes are currently mapped in the master.</p>
                    </div>
                @endforelse
            </div>

            <div class="mt-6">
                {{ $records->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
