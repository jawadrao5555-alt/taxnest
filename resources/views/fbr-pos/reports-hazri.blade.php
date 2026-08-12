<x-fbr-pos-layout>
<div class="max-w-5xl mx-auto">
    @include('fbr-pos.partials.back-link')

    {{-- ═══ Staff Hazri — FBR mirror (Task #560) — ADMIN/MANAGER-ONLY ═══
         One row per staff member per business day (6 AM → 6 AM window):
         first login, last logout (ya last-seen jab logout dabaya hi nahi),
         duty hours, login count, FBR bills + first/last sale.
         Data = pos_user_sessions (fbrpos-guard logins, Task 558). --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.staff_hazri') }}</h1>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.hazri_subtitle', ['date' => \Carbon\Carbon::parse($date)->format('d M Y')]) }}</p>
        </div>
        <form method="GET" action="{{ route('fbrpos.reports.hazri') }}" class="flex items-center gap-2">
            <input type="date" name="date" value="{{ $date }}" max="{{ now()->toDateString() }}" onchange="this.form.submit()" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500 transition">
        </form>
    </div>

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
                    <td class="px-3 py-3 text-center font-semibold text-blue-700 dark:text-blue-300 tabular-nums">
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
</div>
</x-fbr-pos-layout>
