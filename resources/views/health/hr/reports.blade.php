@php
    use App\Models\HealthAttendanceDay;

    $period = \Illuminate\Support\Carbon::create($year, $month, 1);
    $daysInMonth = (int) $period->daysInMonth;

    // One letter per day. A wall sheet has to be readable at arm's length, so
    // the matrix carries a code and the legend below carries the meaning.
    $codes = [
        'present'      => ['P', 'text-emerald-700 dark:text-emerald-300'],
        'half_day'     => ['H', 'text-amber-700 dark:text-amber-300'],
        'absent'       => ['A', 'text-rose-700 dark:text-rose-300'],
        'leave'        => ['L', 'text-sky-700 dark:text-sky-300'],
        'holiday'      => ['G', 'text-indigo-700 dark:text-indigo-300'],
        'weekly_off'   => ['W', 'text-gray-400'],
        'on_call'      => ['C', 'text-teal-700 dark:text-teal-300'],
        'missed_punch' => ['M', 'text-orange-700 dark:text-orange-300'],
        'exempt'       => ['E', 'text-gray-500'],
    ];
@endphp
{{--
    The monthly sheet: attendance, leave, overtime, late and absence, plus the
    department roll-up a matron reads to see which ward is short.
--}}
<x-health-layout>
    <div class="max-w-full mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_reports_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $period->translatedFormat('F Y') }}
                    @if($lock && $lock->isActive())
                        &middot; <span class="font-bold text-emerald-700 dark:text-emerald-300">{{ __('health.hr_month_locked') }}</span>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('health.hr.attendance.reports.export', ['year' => $year, 'month' => $month]) }}"
                   class="px-4 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700">
                    {{ __('health.hr_export_csv') }}
                </a>
                @if($canPayroll)
                    <a href="{{ route('health.hr.payroll', ['year' => $year, 'month' => $month]) }}"
                       class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.hr_open_payroll') }}
                    </a>
                @endif
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

        {{-- ── Department roll-up ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-3">
            <h2 class="text-base font-black">{{ __('health.hr_by_department') }}</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-900">
                            <th class="px-3 py-2 text-start font-black">{{ __('health.hr_department') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_tile_present') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_tile_absent') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_tile_leave') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_tile_late') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_overtime') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($byDepartment as $row)
                            @continue($row['present'] + $row['absent'] + $row['leave'] === 0)
                            <tr>
                                <td class="px-3 py-2 font-bold">{{ $row['name'] }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $row['present'] }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $row['absent'] }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $row['leave'] }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $row['late'] }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ HealthAttendanceDay::hoursLabel($row['overtime_minutes']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Per-day matrix ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
            <table class="min-w-full text-[11px]">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-900">
                        <th class="sticky start-0 z-10 bg-gray-50 dark:bg-gray-900 px-3 py-2 text-start font-black min-w-[160px]">{{ __('health.hr_staff') }}</th>
                        @for($d = 1; $d <= $daysInMonth; $d++)
                            <th class="px-1 py-2 text-center font-bold w-6">{{ $d }}</th>
                        @endfor
                        <th class="px-2 py-2 text-center font-black">{{ __('health.hr_tile_present') }}</th>
                        <th class="px-2 py-2 text-center font-black">{{ __('health.hr_tile_absent') }}</th>
                        <th class="px-2 py-2 text-center font-black">{{ __('health.hr_worked') }}</th>
                        <th class="px-2 py-2 text-center font-black">{{ __('health.hr_overtime') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($staff as $member)
                        @php
                            $userId = (int) $member->id;
                            $row = $totals[$userId] ?? null;
                            $cells = $matrix[$userId] ?? [];
                        @endphp
                        <tr>
                            <td class="sticky start-0 z-10 bg-white dark:bg-gray-800 px-3 py-2 font-bold whitespace-nowrap">{{ $member->name }}</td>
                            @for($d = 1; $d <= $daysInMonth; $d++)
                                @php
                                    $day = $cells[$d] ?? null;
                                    [$code, $tone] = $codes[$day->status ?? ''] ?? ['·', 'text-gray-300 dark:text-gray-600'];
                                @endphp
                                <td class="px-1 py-2 text-center font-black {{ $tone }}"
                                    title="{{ $day ? __(HealthAttendanceDay::statusLabelKey($day->status)) : '' }}">{{ $code }}</td>
                            @endfor
                            <td class="px-2 py-2 text-center tabular-nums font-bold">{{ $row['present_days'] ?? 0 }}</td>
                            <td class="px-2 py-2 text-center tabular-nums">{{ $row['absent_days'] ?? 0 }}</td>
                            <td class="px-2 py-2 text-center tabular-nums">{{ HealthAttendanceDay::hoursLabel($row['worked_minutes'] ?? 0) }}</td>
                            <td class="px-2 py-2 text-center tabular-nums">{{ HealthAttendanceDay::hoursLabel($row['overtime_minutes'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ── Legend ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap gap-3 text-xs">
            @foreach($codes as $statusKey => [$code, $tone])
                <span class="inline-flex items-center gap-1.5">
                    <span class="font-black {{ $tone }}">{{ $code }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ __(HealthAttendanceDay::statusLabelKey($statusKey)) }}</span>
                </span>
            @endforeach
        </div>
    </div>
</x-health-layout>
