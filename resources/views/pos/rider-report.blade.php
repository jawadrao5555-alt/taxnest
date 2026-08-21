<x-pos-layout>
{{-- Rider Performance Report (Task #1103) — Unlimited exclusive.
     Per-rider, per-day km / duty / deliveries / avg delivery time, plus a
     7/30-day ranking (best rider on top). Server-rendered — no polling.
     Locked plans see the same upsell card treatment as the tracking page. --}}

@if($locked)
    <div class="max-w-xl mx-auto mt-10 px-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 text-center">
            <div class="text-4xl mb-3">📊</div>
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-2">{{ __('pos.rr_locked_title') }}</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('pos.rr_locked_body') }}</p>
            <a href="{{ route('pos.billing') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold">
                {{ __('pos.rt_upgrade_btn') }}
            </a>
        </div>
    </div>
@else
    <div class="max-w-6xl mx-auto px-3 sm:px-4 py-4">

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">
            <div class="flex items-center gap-3">
                <button type="button"
                        onclick="if (history.length > 1) { history.back(); } else { window.location = '{{ route('pos.riders.tracking') }}'; }"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition"
                        title="{{ __('pos.ti_go_back') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    {{ __('pos.back_word') }}
                </button>
                <div>
                    <h1 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.rr_title') }}</h1>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">
                        @if($range === 'day'){{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}@else{{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}@endif
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('pos.riders.tracking') }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                    📍 {{ __('pos.nav_rider_tracking') }}
                </a>
                <a href="{{ route('pos.deliveries') }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                    🛵 {{ __('pos.deliveries_board') }}
                </a>
            </div>
        </div>

        {{-- Mode tabs + day picker --}}
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <a href="{{ route('pos.riders.report') }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $range === 'day' ? 'bg-indigo-600 text-white' : 'bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200' }}">
                {{ __('pos.rr_day_tab') }}
            </a>
            <a href="{{ route('pos.riders.report', ['range' => 7]) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $range === '7' ? 'bg-indigo-600 text-white' : 'bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200' }}">
                {{ __('pos.rr_7_tab') }}
            </a>
            <a href="{{ route('pos.riders.report', ['range' => 30]) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $range === '30' ? 'bg-indigo-600 text-white' : 'bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200' }}">
                {{ __('pos.rr_30_tab') }}
            </a>
            @if($range === 'day')
            <form method="GET" action="{{ route('pos.riders.report') }}" class="flex items-center gap-2 ml-auto">
                <input type="date" name="date" value="{{ $date }}" max="{{ now()->format('Y-m-d') }}"
                       onchange="this.form.submit()"
                       class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
            </form>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/60">
                        <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            @if($range !== 'day')<th class="px-4 py-3">{{ __('pos.rr_rank') }}</th>@endif
                            <th class="px-4 py-3">{{ __('pos.rider_label') }}</th>
                            <th class="px-4 py-3">{{ __('pos.rr_col_delivered') }}</th>
                            @if($hasDeliveryStamps)<th class="px-4 py-3">{{ __('pos.rr_col_avg_min') }}</th>@endif
                            <th class="px-4 py-3">{{ __('pos.rr_col_km') }}</th>
                            <th class="px-4 py-3">{{ __('pos.rr_col_duty') }}</th>
                            @if($range !== 'day')<th class="px-4 py-3">{{ __('pos.rr_days_active') }}</th>@endif
                            {{-- Task #1402: how the route reached us, and the refused uploads --}}
                            <th class="px-4 py-3">{{ __('pos.rr_col_route_arrival') }}</th>
                            @if($hasRejectCols)<th class="px-4 py-3">{{ __('pos.rr_col_refused') }}</th>@endif
                            <th class="px-4 py-3 text-right">{{ __('pos.cash_khata') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($rows as $i => $row)
                        @php $r = $row['rider']; @endphp
                        <tr class="{{ $range !== 'day' && $i === 0 && $row['delivered'] > 0 ? 'bg-emerald-50/60 dark:bg-emerald-900/10' : '' }}">
                            @if($range !== 'day')
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($row['delivered'] > 0 && $i < 3)
                                    <span class="text-base">{{ ['🥇', '🥈', '🥉'][$i] }}</span>
                                @else
                                    <span class="text-gray-400 font-semibold">{{ $i + 1 }}</span>
                                @endif
                            </td>
                            @endif
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ $r->name }}
                                    @if($range !== 'day' && $i === 0 && $row['delivered'] > 0)
                                    <span class="ml-1.5 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400 align-middle">{{ __('pos.rr_best') }}</span>
                                    @endif
                                </div>
                                @if(!$r->is_active)<div class="text-[10px] text-gray-400">{{ __('pos.inactive_word') }}</div>@endif
                            </td>
                            <td class="px-4 py-3 font-semibold text-gray-900 dark:text-white">{{ $row['delivered'] }}</td>
                            @if($hasDeliveryStamps)
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                @if($row['avg_minutes'] !== null){{ $row['avg_minutes'] }} {{ __('pos.rr_min') }}@else<span class="text-gray-400">—</span>@endif
                            </td>
                            @endif
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                @if($row['km'] > 0){{ number_format($row['km'], 1) }} km @else<span class="text-gray-400">—</span>@endif
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                @if($row['duty_minutes'] > 0){{ intdiv($row['duty_minutes'], 60) }}:{{ str_pad((string) ($row['duty_minutes'] % 60), 2, '0', STR_PAD_LEFT) }} {{ __('pos.rr_hrs') }}@else<span class="text-gray-400">—</span>@endif
                            </td>
                            @if($range !== 'day')
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $row['days_active'] ?: '—' }}</td>
                            @endif
                            {{-- Task #1402: "route live aaya ya shaam ko ek saath?" — share of the
                                 route that came out of the phone's offline buffer, plus the worst
                                 fix→arrival delay behind it. Row counts stay out of the owner's view. --}}
                            @php
                                $sync = $row['sync'];
                                $syncPill = [
                                    'all_live'    => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
                                    'mostly_live' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
                                    'part_late'   => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
                                    'mostly_late' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300',
                                ][$sync['state']] ?? '';
                            @endphp
                            <td class="px-4 py-3">
                                @if($sync['state'] === null)
                                    <span class="text-gray-400">—</span>
                                @else
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold {{ $syncPill }}">{{ __('pos.rr_sync_' . $sync['state']) }}</span>
                                    @if($sync['late_pct'] > 0)
                                    <div class="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                                        {{ __('pos.rr_sync_share', ['pct' => $sync['late_pct']]) }}@if($sync['lag_unit']) · {{ __($sync['lag_unit'] === 'h' ? 'pos.rr_sync_lag_hrs' : 'pos.rr_sync_lag_min', ['n' => $sync['lag_value']]) }}@endif
                                    </div>
                                    @endif
                                @endif
                            </td>
                            @if($hasRejectCols)
                            <td class="px-4 py-3">
                                @if($row['reject_reason'])
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-300">⛔ {{ __('pos.rr_refused_' . $row['reject_reason']) }}</span>
                                    <div class="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                                        {{ $range === 'day' ? $row['reject_at']->format('h:i A') : $row['reject_at']->format('d/m h:i A') }}
                                    </div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            @endif
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('pos.deliveries') }}" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline whitespace-nowrap">{{ __('pos.cash_khata') }} →</a>
                            </td>
                        </tr>
                        @empty
                        @php
                            // rider + deliveries + distance + duty + route arrival + khata,
                            // plus the columns that only render in some modes.
                            $cols = 6 + ($range !== 'day' ? 2 : 0) + ($hasDeliveryStamps ? 1 : 0) + ($hasRejectCols ? 1 : 0);
                        @endphp
                        <tr><td colspan="{{ $cols }}" class="px-4 py-10 text-center text-sm text-gray-400">{{ __('pos.rr_no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Approximation disclaimer — duty has no session log; km is GPS-derived. --}}
        <p class="mt-3 text-[11px] text-gray-400 dark:text-gray-500">ℹ️ {{ __('pos.rr_approx_note') }}</p>
        {{-- Task #1402: what "late" means, and why an old day can show no refusal
             (pos_riders keeps only each rider's newest refusal). --}}
        <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">ℹ️ {{ __('pos.rr_sync_note') }}</p>
        @if($hasRejectCols)
        <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">ℹ️ {{ __('pos.rr_refused_note') }}</p>
        @endif
    </div>
@endif
</x-pos-layout>
