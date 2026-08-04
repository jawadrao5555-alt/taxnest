<x-pos-layout>
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')

    {{-- ═══ Staff Hazri (owner batch, 26 Jul 2026) — ADMIN/MANAGER-ONLY ═══
         One row per staff member per business day (6 AM → 6 AM window):
         first login, last logout (ya last-seen jab logout dabaya hi nahi),
         login count, bills + first/last sale. Data = pos_user_sessions.
         Biometric punches section added 4 Aug 2026.
         Duty Hours column + Payroll Range Summary added Task #280. --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.staff_hazri') }}</h1>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.hazri_subtitle', ['date' => \Carbon\Carbon::parse($date)->format('d M Y')]) }}</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            {{-- Biometric Setup link --}}
            @if(auth('pos')->user()?->isPosAdmin())
            <a href="{{ route('pos.bio-sync.setup') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                {{ __('pos.bio_nav') }}
            </a>
            @endif
            <form method="GET" action="{{ route('pos.reports.hazri') }}" class="flex items-center gap-2">
                {{-- Preserve any active range params when changing the single-day date --}}
                @if($dateFrom)<input type="hidden" name="date_from" value="{{ $dateFrom }}">@endif
                @if($dateTo)<input type="hidden" name="date_to" value="{{ $dateTo }}">@endif
                <input type="date" name="date" value="{{ $date }}" max="{{ now()->toDateString() }}" onchange="this.form.submit()" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
            </form>
        </div>
    </div>

    @if($opening)
    <div class="mb-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
        {{ __('pos.opening_cash_rs', ['amount' => number_format($opening->opening_cash, 0)]) }}
        <span class="text-emerald-500 font-normal">({{ $opening->enteredBy?->name ?? '—' }})</span>
    </div>
    @endif

    {{-- ── POS Login Sessions ─────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden mb-6">
        @if(empty($rows))
        <div class="p-10 text-center">
            <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('pos.hazri_none_this_day') }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('pos.hazri_only_after_feature') }}</p>
        </div>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('pos.th_staff') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_first_in') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_last_out') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.bio_duty_hours') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_logins') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_bills') }}</th>
                    <th class="px-3 py-3 text-right">{{ __('pos.th_sales_rs') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_first_sale') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_last_sale') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @php
                    $roleLabels = [
                        'pos_admin' => __('pos.role_admin'), 'pos_manager' => __('pos.role_manager'), 'pos_cashier' => __('pos.role_cashier'),
                        'pos_waiter' => __('pos.role_waiter'), 'pos_kitchen' => __('pos.role_kitchen'), 'pos_delivery' => __('pos.role_delivery_mgr'),
                        'pos_rider' => __('pos.role_rider'), 'archive_viewer' => __('pos.role_viewer'), 'local_viewer' => __('pos.role_viewer'),
                    ];
                    $fmt = fn ($t) => $t ? \Carbon\Carbon::parse($t)->format('h:i A') : null;
                @endphp
                @foreach($rows as $h)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $h->name }}</span>
                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">{{ $roleLabels[$h->pos_role] ?? '—' }}</span>
                            @if($h->still_open && $h->last_seen && \Carbon\Carbon::parse($h->last_seen)->gt(now()->subMinutes(10)))
                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300">{{ __('pos.on_duty') }}</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-3 text-center font-medium text-gray-700 dark:text-gray-200">{{ $fmt($h->first_in) ?? '—' }}</td>
                    <td class="px-3 py-3 text-center font-medium text-gray-700 dark:text-gray-200">
                        @if($h->last_out)
                            {{ $fmt($h->last_out) }}
                        @elseif($h->last_seen)
                            {{ $fmt($h->last_seen) }}<span class="text-amber-500 font-bold" title="{{ __('pos.ti_no_logout_last_activity') }}">*</span>
                        @else
                            —
                        @endif
                    </td>
                    {{-- Duty Hours column (data already computed in buildHazriRows) --}}
                    <td class="px-3 py-3 text-center font-semibold text-purple-700 dark:text-purple-300 tabular-nums">
                        @if($h->duty_minutes > 0 || $h->first_in)
                            {{ \App\Support\PosHazriDutyHours::format($h->duty_minutes) }}<span class="text-amber-500 font-bold" title="{{ __('pos.payroll_open_footnote') }}">{{ $h->duty_open ? '*' : '' }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-3 text-center text-gray-600 dark:text-gray-300">{{ $h->session_count ?: '—' }}</td>
                    <td class="px-3 py-3 text-center text-gray-600 dark:text-gray-300">{{ $h->bill_count ?: '—' }}</td>
                    <td class="px-3 py-3 text-right font-semibold text-gray-800 dark:text-gray-100">{{ $h->revenue > 0 ? number_format($h->revenue, 0) : '—' }}</td>
                    <td class="px-3 py-3 text-center text-gray-600 dark:text-gray-300">{{ $fmt($h->first_sale) ?? '—' }}</td>
                    <td class="px-3 py-3 text-center text-gray-600 dark:text-gray-300">{{ $fmt($h->last_sale) ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div class="px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border-t border-gray-100 dark:border-gray-700">
            <p class="text-[11px] text-gray-400">{{ __('pos.hazri_footnote') }}</p>
        </div>
        @endif
    </div>

    {{-- ── Biometric Punches (4 Aug 2026) ─────────────────────────────── --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden mb-6">
        <div class="px-5 py-3.5 bg-gray-50 dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                <span class="text-sm font-bold text-gray-800 dark:text-white">{{ __('pos.bio_hazri_section') }}</span>
                @if(!empty($unmappedPinCount) && auth('pos')->user()?->isPosAdmin())
                <a href="{{ route('pos.bio-sync.setup') }}" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-[10px] font-bold hover:bg-amber-200 dark:hover:bg-amber-800/60 transition" title="{{ __('pos.bio_unmapped_badge_title') }}">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01"/></svg>
                    {{ $unmappedPinCount }} {{ __('pos.bio_unmapped_badge') }}
                </a>
                @endif
            </div>
            @if(auth('pos')->user()?->isPosAdmin())
            <a href="{{ route('pos.bio-sync.setup') }}" class="text-xs text-purple-600 dark:text-purple-400 hover:underline">{{ __('pos.bio_hazri_setup_link') }} →</a>
            @endif
        </div>

        @if(empty($bioPunches))
        <div class="p-8 text-center">
            @if(!$hasBioDevices)
            {{-- No device registered yet --}}
            <svg class="w-9 h-9 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('pos.bio_no_devices') }}</p>
            @if(auth('pos')->user()?->isPosAdmin())
            <a href="{{ route('pos.bio-sync.setup') }}" class="mt-2 inline-flex items-center gap-1 text-xs text-purple-600 dark:text-purple-400 hover:underline">
                {{ __('pos.bio_hazri_setup_link') }} →
            </a>
            @endif
            @else
            {{-- Device exists but no punches this day --}}
            <svg class="w-9 h-9 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('pos.bio_hazri_none') }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('pos.bio_hazri_hint') }}</p>
            @endif
        </div>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('pos.th_staff') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_first_in') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_last_out') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.bio_duty_hours') }}</th>
                    <th class="px-3 py-3 text-center">In</th>
                    <th class="px-3 py-3 text-center">Out</th>
                    <th class="px-3 py-3 text-center">Source</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($bioPunches as $bp)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                    <td class="px-4 py-3">
                        @if($bp->name)
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $bp->name }}</span>
                        @else
                            <span class="font-semibold text-gray-400 dark:text-gray-500 italic">{{ __('pos.bio_unmapped_pin', ['pin' => $bp->device_pin ?? '?']) }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 text-center font-medium text-gray-700 dark:text-gray-200">
                        {{ $bp->first_in ? \Carbon\Carbon::parse($bp->first_in)->format('h:i A') : '—' }}
                    </td>
                    <td class="px-3 py-3 text-center font-medium text-gray-700 dark:text-gray-200">
                        {{ $bp->last_out ? \Carbon\Carbon::parse($bp->last_out)->format('h:i A') : '—' }}
                    </td>
                    <td class="px-3 py-3 text-center font-semibold text-purple-700 dark:text-purple-300 tabular-nums">
                        {{ \App\Support\PosHazriDutyHours::format($bp->duty_minutes ?? 0) }}<span class="text-amber-500 font-bold">{{ !empty($bp->duty_open) ? '*' : '' }}</span>
                    </td>
                    <td class="px-3 py-3 text-center text-gray-600 dark:text-gray-300">{{ $bp->in_count ?: '—' }}</td>
                    <td class="px-3 py-3 text-center text-gray-600 dark:text-gray-300">{{ $bp->out_count ?: '—' }}</td>
                    <td class="px-3 py-3 text-center">
                        @foreach($bp->sources as $src)
                        <span class="inline-block px-1.5 py-0.5 rounded-full text-[10px] font-bold
                            {{ $src === 'adms' ? 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300' : 'bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300' }}">
                            {{ $src === 'adms' ? __('pos.bio_source_adms') : __('pos.bio_source_import') }}
                        </span>
                        @endforeach
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div class="px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border-t border-gray-100 dark:border-gray-700">
            <p class="text-[11px] text-gray-400">{{ __('pos.bio_hazri_hint') }}</p>
        </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════
         ── Payroll Summary Section (Task #280) ───────────────────────────
         Date-range form + aggregated per-staff total duty hours table.
         ══════════════════════════════════════════════════════════════════════ --}}
    <div id="payroll-summary" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden print:shadow-none">

        {{-- Section header --}}
        <div class="px-5 py-3.5 bg-indigo-50 dark:bg-indigo-950/30 border-b border-indigo-100 dark:border-indigo-900/50 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span class="text-sm font-bold text-gray-800 dark:text-white">{{ __('pos.payroll_summary') }}</span>
                @if($dateFrom && $dateTo)
                <span class="text-xs text-indigo-600 dark:text-indigo-300 font-medium">
                    {{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }} — {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}
                </span>
                @endif
            </div>
            {{-- Action buttons: print + PDF (only when data is loaded).
                 payroll-screen-only = hidden by our @media print CSS below. --}}
            @if($rangeRows !== null)
            <div class="payroll-screen-only flex items-center gap-2">
                <button onclick="window.print()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    {{ __('pos.payroll_print') }}
                </button>
                <a href="{{ route('pos.reports.hazri.payroll-pdf', ['date_from' => $dateFrom, 'date_to' => $dateTo]) }}"
                   target="_blank"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    {{ __('pos.payroll_pdf_download') }}
                </a>
            </div>
            @endif
        </div>

        {{-- Range filter form — payroll-screen-only: hidden by @media print CSS below --}}
        <div class="payroll-screen-only px-5 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/20">
            <form method="GET" action="{{ route('pos.reports.hazri') }}#payroll-summary" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="date" value="{{ $date }}">
                <div class="flex flex-col gap-1">
                    <label class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('pos.payroll_from') }}</label>
                    <input type="date" name="date_from" value="{{ $dateFrom ?? \Carbon\Carbon::now()->startOfMonth()->toDateString() }}" max="{{ now()->toDateString() }}"
                           class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 transition">
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('pos.payroll_to') }}</label>
                    <input type="date" name="date_to" value="{{ $dateTo ?? now()->toDateString() }}" max="{{ now()->toDateString() }}"
                           class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 transition">
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition">
                    {{ __('pos.payroll_load') }}
                </button>
            </form>
            @if($rangeError)
            <p class="mt-2 text-xs font-semibold text-red-600 dark:text-red-400">{{ $rangeError }}</p>
            @endif
        </div>

        {{-- Results --}}
        @if($rangeRows === null)
        {{-- Not yet requested --}}
        <div class="p-8 text-center">
            <svg class="w-9 h-9 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <p class="text-sm text-gray-400 dark:text-gray-500">{{ __('pos.payroll_load') }} — {{ __('pos.payroll_from') }} / {{ __('pos.payroll_to') }}</p>
        </div>
        @elseif(empty($rangeRows) && empty($rangeBioRows))
        <div class="p-8 text-center">
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('pos.payroll_no_data') }}</p>
        </div>
        @else

        {{-- ── Payroll print header (hidden on screen) ──────────────────── --}}
        <div class="hidden print:block px-5 pt-5 pb-2 border-b border-gray-200">
            <h2 class="text-base font-bold text-gray-900">{{ $company->name }} — {{ __('pos.payroll_pdf_title') }}</h2>
            <p class="text-xs text-gray-500">
                {{ __('pos.payroll_from') }}: {{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }} &nbsp;|&nbsp;
                {{ __('pos.payroll_to') }}: {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }} &nbsp;|&nbsp;
                {{ __('pos.payroll_generated_at') }}: {{ now()->format('d M Y h:i A') }}
            </p>
        </div>

        {{-- ── POS Session Summary ───────────────────────────────────────── --}}
        @if(!empty($rangeRows))
        <div class="px-5 py-3 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-700">
            <span class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wide">{{ __('pos.payroll_session_summary') }}</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('pos.th_staff') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_days_present') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_total_duty') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_bills') }}</th>
                    <th class="px-3 py-3 text-right">{{ __('pos.th_sales_rs') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @php
                    $roleLabels = $roleLabels ?? [
                        'pos_admin' => __('pos.role_admin'), 'pos_manager' => __('pos.role_manager'), 'pos_cashier' => __('pos.role_cashier'),
                        'pos_waiter' => __('pos.role_waiter'), 'pos_kitchen' => __('pos.role_kitchen'), 'pos_delivery' => __('pos.role_delivery_mgr'),
                        'pos_rider' => __('pos.role_rider'), 'archive_viewer' => __('pos.role_viewer'), 'local_viewer' => __('pos.role_viewer'),
                    ];
                    $totalDutyMin = 0; $totalBills = 0; $totalRevenue = 0;
                @endphp
                @foreach($rangeRows as $r)
                @php $totalDutyMin += $r->total_minutes; $totalBills += $r->total_bills; $totalRevenue += $r->total_revenue; @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                    <td class="px-4 py-3">
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $r->name }}</span>
                        @if($r->pos_role)
                        <span class="ml-1.5 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">{{ $roleLabels[$r->pos_role] ?? $r->pos_role }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 text-center text-gray-700 dark:text-gray-200">{{ $r->days_present }}</td>
                    <td class="px-3 py-3 text-center font-bold text-indigo-700 dark:text-indigo-300 tabular-nums">
                        {{ \App\Support\PosHazriDutyHours::format($r->total_minutes) }}<span class="text-amber-500" title="{{ __('pos.payroll_open_footnote') }}">{{ $r->any_open ? '*' : '' }}</span>
                    </td>
                    <td class="px-3 py-3 text-center text-gray-600 dark:text-gray-300">{{ $r->total_bills ?: '—' }}</td>
                    <td class="px-3 py-3 text-right font-semibold text-gray-800 dark:text-gray-100">{{ $r->total_revenue > 0 ? number_format($r->total_revenue, 0) : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-100 dark:bg-gray-800 text-xs font-bold">
                <tr>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ __('pos.th_total') ?? 'Total' }}</td>
                    <td class="px-3 py-3 text-center text-gray-700 dark:text-gray-200">—</td>
                    <td class="px-3 py-3 text-center text-indigo-700 dark:text-indigo-300 tabular-nums">{{ \App\Support\PosHazriDutyHours::format($totalDutyMin) }}</td>
                    <td class="px-3 py-3 text-center text-gray-700 dark:text-gray-200">{{ $totalBills ?: '—' }}</td>
                    <td class="px-3 py-3 text-right text-gray-800 dark:text-gray-100">{{ $totalRevenue > 0 ? number_format($totalRevenue, 0) : '—' }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
        @endif

        {{-- ── Biometric Payroll Summary ─────────────────────────────────── --}}
        @if(!empty($rangeBioRows))
        <div class="px-5 py-3 bg-gray-50 dark:bg-gray-800/50 border-y border-gray-100 dark:border-gray-700 mt-1">
            <span class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wide">{{ __('pos.payroll_bio_summary') }}</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('pos.th_staff') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_days_present') }}</th>
                    <th class="px-3 py-3 text-center">{{ __('pos.th_total_duty') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @php $bioTotalMin = 0; @endphp
                @foreach($rangeBioRows as $b)
                @php $bioTotalMin += $b->total_minutes; @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                    <td class="px-4 py-3">
                        @if($b->name)
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $b->name }}</span>
                        @else
                            <span class="font-semibold text-gray-400 dark:text-gray-500 italic">{{ __('pos.bio_unmapped_pin', ['pin' => $b->device_pin ?? '?']) }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 text-center text-gray-700 dark:text-gray-200">{{ $b->days_present }}</td>
                    <td class="px-3 py-3 text-center font-bold text-indigo-700 dark:text-indigo-300 tabular-nums">
                        {{ \App\Support\PosHazriDutyHours::format($b->total_minutes) }}<span class="text-amber-500" title="{{ __('pos.payroll_open_footnote') }}">{{ $b->any_open ? '*' : '' }}</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-100 dark:bg-gray-800 text-xs font-bold">
                <tr>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ __('pos.th_total') ?? 'Total' }}</td>
                    <td class="px-3 py-3 text-center text-gray-700 dark:text-gray-200">—</td>
                    <td class="px-3 py-3 text-center text-indigo-700 dark:text-indigo-300 tabular-nums">{{ \App\Support\PosHazriDutyHours::format($bioTotalMin) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>
        @endif

        {{-- Footnote --}}
        <div class="px-5 py-3 bg-gray-50 dark:bg-gray-800 border-t border-gray-100 dark:border-gray-700">
            <p class="text-[11px] text-gray-400">{{ __('pos.payroll_open_footnote') }}</p>
        </div>
        @endif {{-- end rangeRows data --}}
    </div>

</div>

{{-- ── Print styles: only the payroll section ──────────────────────────────
     Strategy: visibility:hidden on body * (not display:none) so every
     ancestor stays in the layout flow — a display:none ancestor would
     prevent any descendant from being shown again, blanking the page.
     Then visibility:visible on #payroll-summary and its descendants re-
     shows exactly what we want; position:absolute moves it to page origin.

     Real ancestor chain (from rendered DOM):
       body.pos-layout-root
         └─ main.flex-1.overflow-y-auto.main-scroll
              └─ div.p-4.sm:p-6
                   └─ div.max-w-5xl
                        └─ div#payroll-summary
──────────────────────────────────────────────────────────────────────── --}}
<style>
@media print {
    /* Step 1 — hide everything through visibility, NOT display, so the
       ancestor chain remains alive for descendants to override. */
    body * {
        visibility: hidden !important;
    }

    /* Step 2 — re-show the payroll section and every element inside it.
       Specificity: #payroll-summary * beats body * (id > type). */
    #payroll-summary,
    #payroll-summary * {
        visibility: visible !important;
    }

    /* Step 3 — lift #payroll-summary to page origin so it prints from
       the top-left corner, filling the full page width. */
    #payroll-summary {
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        box-shadow: none !important;
        border: 1px solid #e5e7eb !important;
        border-radius: 0 !important;
    }

    /* Step 4 — hide screen-only controls (date-range form, Print/PDF
       buttons).  .payroll-screen-only has a class selector which
       beats the #payroll-summary * rule (class > universal). */
    #payroll-summary .payroll-screen-only {
        display: none !important;
    }
}
</style>
</x-pos-layout>
