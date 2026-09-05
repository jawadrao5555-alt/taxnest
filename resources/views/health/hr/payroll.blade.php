@php
    use App\Models\HealthAttendanceDay;

    $period = \Illuminate\Support\Carbon::create($year, $month, 1);
@endphp
{{--
    The payroll handoff.

    Locking a month does two things and no more: it freezes every derived day in
    that month, and it snapshots these totals onto the lock row. Payroll is paid
    from the snapshot, so a later policy fix or a re-import can never silently
    change a month that already went out. Raw punch evidence is untouched by
    either action.
--}}
<x-health-layout>
    <div class="max-w-full mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_payroll_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $period->translatedFormat('F Y') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($locked)
                    <a href="{{ route('health.hr.payroll.export', ['year' => $year, 'month' => $month]) }}"
                       class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.hr_export_payroll') }}
                    </a>
                @endif
                <a href="{{ route('health.hr.attendance.reports', ['year' => $year, 'month' => $month]) }}"
                   class="px-4 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700">
                    {{ __('health.hr_open_reports') }}
                </a>
            </div>
        </div>

        <form method="GET" class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_month') }}</label>
                <input type="month" name="month" value="{{ $period->format('Y-m') }}"
                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.hr_apply') }}
            </button>
        </form>

        {{-- ── Lock state ── --}}
        <div class="rounded-2xl border p-5 space-y-3
            {{ $locked ? 'bg-emerald-50 dark:bg-emerald-900/25 border-emerald-300 dark:border-emerald-700' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700' }}">
            @if($locked)
                <div>
                    <p class="text-sm font-black">{{ __('health.hr_month_locked') }}</p>
                    <p class="text-xs text-gray-600 dark:text-gray-300 mt-0.5">
                        {{ __('health.hr_locked_by', [
                            'name' => $lockedBy->name ?? __('health.hr_unknown_staff'),
                            'when' => $lock->locked_at?->translatedFormat('d M Y H:i') ?? '',
                        ]) }}
                        @if($lock->note) — {{ $lock->note }} @endif
                    </p>
                </div>
                @if($canApprove)
                    <form method="POST" action="{{ route('health.hr.payroll.unlock') }}"
                          onsubmit="return confirm('{{ __('health.hr_confirm_unlock') }}')">
                        @csrf
                        <input type="hidden" name="year" value="{{ $year }}">
                        <input type="hidden" name="month" value="{{ $month }}">
                        <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold hover:bg-white/60 dark:hover:bg-gray-700">
                            {{ __('health.hr_unlock_month') }}
                        </button>
                    </form>
                @endif
            @else
                <div>
                    <p class="text-sm font-black">{{ __('health.hr_month_open') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_lock_hint') }}</p>
                </div>

                @if($pending > 0)
                    {{-- A month must not be approved with unresolved corrections
                         sitting behind it; the totals would be stale the moment
                         somebody clears the queue. --}}
                    <p class="text-sm font-bold text-amber-700 dark:text-amber-300">
                        {{ __('health.hr_pending_blocks_lock', ['count' => $pending]) }}
                        <a href="{{ route('health.hr.corrections', ['status' => 'pending']) }}" class="underline">{{ __('health.hr_open') }}</a>
                    </p>
                @endif

                @if($canApprove)
                    <form method="POST" action="{{ route('health.hr.payroll.lock') }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <input type="hidden" name="year" value="{{ $year }}">
                        <input type="hidden" name="month" value="{{ $month }}">
                        <div class="flex-1 min-w-[220px]">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_lock_note') }}</label>
                            <input type="text" name="note" maxlength="255"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <button type="submit" @disabled($pending > 0)
                                class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-black transition">
                            {{ __('health.hr_lock_month') }}
                        </button>
                    </form>
                @endif
            @endif
        </div>

        {{-- ── The handoff sheet ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
            @if(empty($totals))
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_staff_none') }}</p>
            @else
                <table class="min-w-full text-xs">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-900">
                            <th class="px-3 py-2 text-start font-black">{{ __('health.hr_staff') }}</th>
                            <th class="px-2 py-2 text-center font-black">{{ __('health.hr_tile_present') }}</th>
                            <th class="px-2 py-2 text-center font-black">{{ __('health.hr_half_day') }}</th>
                            <th class="px-2 py-2 text-center font-black">{{ __('health.hr_tile_absent') }}</th>
                            <th class="px-2 py-2 text-center font-black">{{ __('health.hr_paid_leave') }}</th>
                            <th class="px-2 py-2 text-center font-black">{{ __('health.hr_unpaid_leave') }}</th>
                            <th class="px-2 py-2 text-center font-black">{{ __('health.hr_tile_late') }}</th>
                            <th class="px-2 py-2 text-center font-black">{{ __('health.hr_payable_days') }}</th>
                            <th class="px-2 py-2 text-center font-black">{{ __('health.hr_worked') }}</th>
                            <th class="px-2 py-2 text-center font-black">{{ __('health.hr_overtime') }}</th>
                            <th class="px-2 py-2 text-end font-black">{{ __('health.hr_basic_earned') }}</th>
                            <th class="px-2 py-2 text-end font-black">{{ __('health.hr_overtime_pay') }}</th>
                            <th class="px-2 py-2 text-end font-black">{{ __('health.hr_gross') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($totals as $row)
                            <tr>
                                <td class="px-3 py-2 font-bold whitespace-nowrap">
                                    {{ $row['name'] }}
                                    @if(!empty($row['designation']))
                                        <span class="block text-[10px] text-gray-500 dark:text-gray-400">{{ $row['designation'] }}</span>
                                    @endif
                                </td>
                                <td class="px-2 py-2 text-center tabular-nums">{{ $row['present_days'] }}</td>
                                <td class="px-2 py-2 text-center tabular-nums">{{ $row['half_days'] }}</td>
                                <td class="px-2 py-2 text-center tabular-nums">{{ $row['absent_days'] }}</td>
                                <td class="px-2 py-2 text-center tabular-nums">{{ $row['paid_leave_days'] }}</td>
                                <td class="px-2 py-2 text-center tabular-nums">{{ $row['unpaid_leave_days'] }}</td>
                                <td class="px-2 py-2 text-center tabular-nums">{{ $row['late_days'] }}</td>
                                <td class="px-2 py-2 text-center tabular-nums font-black">{{ rtrim(rtrim(number_format((float) $row['payable_days'], 1), '0'), '.') }}</td>
                                <td class="px-2 py-2 text-center tabular-nums">{{ HealthAttendanceDay::hoursLabel($row['worked_minutes']) }}</td>
                                <td class="px-2 py-2 text-center tabular-nums">{{ HealthAttendanceDay::hoursLabel($row['overtime_minutes']) }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ $row['basic_earned'] !== null ? number_format((float) $row['basic_earned']) : '—' }}</td>
                                <td class="px-2 py-2 text-end tabular-nums">{{ $row['overtime_pay'] !== null ? number_format((float) $row['overtime_pay']) : '—' }}</td>
                                <td class="px-2 py-2 text-end tabular-nums font-black">{{ $row['gross'] !== null ? number_format((float) $row['gross']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.hr_payroll_footnote') }}</p>
    </div>
</x-health-layout>
