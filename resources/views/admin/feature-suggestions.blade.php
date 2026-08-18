<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Feature Suggestions (POS)</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>
            @endif

            {{-- Task 1202: PRA provisional-billing elaan — raay tally (source='pra_elaan') --}}
            @if(!empty($praElaanTally))
                @php
                    $peChoiceMeta = [
                        'band' => ['Yes — turn it off', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
                        'jari' => ['No — keep it', 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'],
                        'aur'  => ['Other suggestion', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
                    ];
                @endphp
                <div class="mb-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-700 bg-blue-50 dark:bg-blue-900/10">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">📢 PRA Elaan — "Should provisional billing be turned off?"</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $praElaanTally['total'] }} {{ Str::plural('response', $praElaanTally['total']) }} from {{ $praElaanTally['companies'] }} {{ Str::plural('company', $praElaanTally['companies']) }} (one answer per user).</p>
                    </div>
                    <div class="px-5 py-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/15 px-4 py-3">
                            <div class="text-2xl font-bold text-green-700 dark:text-green-300">{{ $praElaanTally['counts']['band'] ?? 0 }}</div>
                            <div class="text-xs font-medium text-green-800 dark:text-green-300 mt-0.5">Yes, turn it off</div>
                        </div>
                        <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/15 px-4 py-3">
                            <div class="text-2xl font-bold text-red-700 dark:text-red-300">{{ $praElaanTally['counts']['jari'] ?? 0 }}</div>
                            <div class="text-xs font-medium text-red-800 dark:text-red-300 mt-0.5">No, keep it running</div>
                        </div>
                        <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/15 px-4 py-3">
                            <div class="text-2xl font-bold text-amber-700 dark:text-amber-300">{{ $praElaanTally['counts']['aur'] ?? 0 }}</div>
                            <div class="text-xs font-medium text-amber-800 dark:text-amber-300 mt-0.5">Other suggestion</div>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700 border-t border-gray-100 dark:border-gray-700 overflow-y-auto" style="max-height: 320px;">
                        @foreach($praElaanTally['rows'] as $peRow)
                            @php $peKey = array_search($peRow->title, \App\Models\FeatureSuggestion::PRA_ELAAN_CHOICES, true); @endphp
                            <div class="px-5 py-2.5 flex items-start justify-between gap-3 flex-wrap">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-[13px] font-semibold text-gray-800 dark:text-gray-100">{{ $peRow->company->name ?? 'Company #' . $peRow->company_id }}</span>
                                        <span class="text-[11px] text-gray-400">{{ $peRow->user->name ?? 'User #' . $peRow->user_id }}</span>
                                        @if($peKey !== false)
                                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold {{ $peChoiceMeta[$peKey][1] }}">{{ $peChoiceMeta[$peKey][0] }}</span>
                                        @endif
                                    </div>
                                    @if($peRow->details)
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-2xl whitespace-pre-line">💬 {{ $peRow->details }}</p>
                                    @endif
                                </div>
                                <div class="text-[11px] text-gray-400 flex-shrink-0 whitespace-nowrap">{{ $peRow->created_at->format('d M Y') }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- High-demand detector: similar open suggestions from 2+ companies --}}
            @if(!empty($hotGroups))
                <div class="mb-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-700 bg-amber-50 dark:bg-amber-900/10">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">🔥 High-Demand Features</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Similar requests from 2 or more companies — 3+ companies means it's time to build ("3 customer rule").</p>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($hotGroups as $grp)
                            <div class="px-5 py-3.5 flex items-start justify-between gap-4 flex-wrap">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100 capitalize">{{ $grp['label'] }}</span>
                                        @if($grp['companies'] >= 3)
                                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-600 text-white">BUILD NOW — {{ $grp['companies'] }} companies!</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Trending — {{ $grp['companies'] }} companies</span>
                                        @endif
                                    </div>
                                    <ul class="mt-1.5 space-y-0.5">
                                        @foreach($grp['titles'] as $gt)
                                            <li class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-xl">• {{ $gt }}</li>
                                        @endforeach
                                        @if($grp['requests'] > count($grp['titles']))
                                            <li class="text-xs text-gray-400">+{{ $grp['requests'] - count($grp['titles']) }} more…</li>
                                        @endif
                                    </ul>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <div class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ $grp['requests'] }}</div>
                                    <div class="text-[11px] text-gray-400">requests</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Status filter tabs with counts --}}
            @php
                $tabs = [
                    null => 'All (' . ($counts->sum()) . ')',
                    'pending' => 'Pending (' . ($counts['pending'] ?? 0) . ')',
                    'planned' => 'Planned (' . ($counts['planned'] ?? 0) . ')',
                    'completed' => 'Completed (' . ($counts['completed'] ?? 0) . ')',
                    'rejected' => 'Rejected (' . ($counts['rejected'] ?? 0) . ')',
                ];
            @endphp
            <div class="mb-5 flex items-center gap-2 flex-wrap">
                @foreach($tabs as $key => $label)
                    <a href="/admin/feature-suggestions{{ $key ? '?status=' . $key : '' }}"
                       class="px-3.5 py-2 rounded-lg text-sm font-medium transition {{ $status === $key || (!$status && $key === null) ? 'bg-emerald-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left table-cards">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Company / User</th>
                                <th class="px-4 py-3">Suggestion</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 min-w-[280px]">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($suggestions as $sugg)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30 align-top">
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $sugg->created_at->format('d M Y') }}<br><span class="text-xs">{{ $sugg->created_at->format('h:i A') }}</span></td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-800 dark:text-gray-100">{{ $sugg->company->name ?? 'Company #' . $sugg->company_id }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $sugg->user->name ?? 'User #' . $sugg->user_id }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-medium text-gray-800 dark:text-gray-100">{{ $sugg->title }}</span>
                                            @if(($sugg->source ?? 'user') === 'madadgar')
                                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">🤖 Madadgar Bot</span>
                                            @elseif(($sugg->source ?? 'user') === \App\Models\FeatureSuggestion::PRA_ELAAN_SOURCE)
                                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">📢 PRA Elaan</span>
                                            @endif
                                        </div>
                                        @if($sugg->details)
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-md whitespace-pre-line">{{ $sugg->details }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @php
                                            $badgeCls = match($sugg->status) {
                                                'planned' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300',
                                                'completed' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                                'rejected' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                                                default => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                            };
                                        @endphp
                                        <span class="px-2 py-1 rounded-full text-xs font-medium {{ $badgeCls }}">{{ ucfirst($sugg->status) }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <form method="POST" action="/admin/feature-suggestions/{{ $sugg->id }}/status" class="flex items-center gap-2 flex-wrap">
                                            @csrf
                                            <select name="status" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-xs py-1.5">
                                                @foreach(['pending' => 'Pending', 'planned' => 'Planned', 'completed' => 'Completed', 'rejected' => 'Rejected'] as $sv => $sl)
                                                    <option value="{{ $sv }}" {{ $sugg->status === $sv ? 'selected' : '' }}>{{ $sl }}</option>
                                                @endforeach
                                            </select>
                                            <input type="text" name="admin_note" maxlength="1000" value="{{ $sugg->admin_note }}" placeholder="Note to customer (optional)"
                                                   class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-xs py-1.5 w-44">
                                            <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700">Save</button>
                                        </form>
                                        <form method="POST" action="/admin/feature-suggestions/{{ $sugg->id }}/delete" class="mt-1.5" onsubmit="return confirm('Delete this suggestion permanently?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-medium bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400 hover:bg-red-100">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">No suggestions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($suggestions->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $suggestions->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
