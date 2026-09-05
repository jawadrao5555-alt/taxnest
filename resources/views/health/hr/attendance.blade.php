@php
    use App\Models\HealthAttendanceDay;
    use App\Models\HealthShift;
@endphp
{{--
    The attendance floor for one day.

    Every person on the establishment appears, whether or not the clock has
    anything on them — a nurse who never punched is the row HR most needs to
    see, and dropping her because there is no punch row would hide exactly the
    problem this screen exists for.
--}}
<x-health-layout>
    <div class="max-w-full mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_attendance_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $date->translatedFormat('l, d F Y') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($pendingCount > 0)
                    <a href="{{ route('health.hr.corrections', ['status' => 'pending']) }}"
                       class="px-3 py-2 rounded-xl bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 text-xs font-black">
                        {{ __('health.hr_pending_corrections', ['count' => $pendingCount]) }}
                    </a>
                @endif
                @if($canCorrect && !$monthLocked)
                    {{-- Re-derives this one day from the evidence on file. Safe to
                         press twice: the day is computed, not accumulated. --}}
                    <form method="POST" action="{{ route('health.hr.attendance.recompute') }}">
                        @csrf
                        <input type="hidden" name="from" value="{{ $date->toDateString() }}">
                        <input type="hidden" name="to" value="{{ $date->toDateString() }}">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                            {{ __('health.hr_recompute') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @if($monthLocked)
            <div class="rounded-2xl bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 p-4">
                <p class="text-sm font-bold">{{ __('health.hr_month_locked_notice') }}</p>
            </div>
        @endif

        {{-- ── Tally ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @php
                $tiles = [
                    ['label' => __('health.hr_tile_present'), 'value' => $tally['present'], 'tone' => 'bg-emerald-50 dark:bg-emerald-900/30'],
                    ['label' => __('health.hr_tile_on_duty'), 'value' => $tally['on_duty'], 'tone' => 'bg-teal-50 dark:bg-teal-900/30'],
                    ['label' => __('health.hr_tile_late'),    'value' => $tally['late'],    'tone' => 'bg-amber-50 dark:bg-amber-900/30'],
                    ['label' => __('health.hr_tile_absent'),  'value' => $tally['absent'],  'tone' => 'bg-rose-50 dark:bg-rose-900/30'],
                    ['label' => __('health.hr_tile_leave'),   'value' => $tally['leave'],   'tone' => 'bg-sky-50 dark:bg-sky-900/30'],
                    ['label' => __('health.hr_tile_missed'),  'value' => $tally['missed_punch'], 'tone' => 'bg-orange-50 dark:bg-orange-900/30'],
                ];
            @endphp
            @foreach($tiles as $tile)
                <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4 {{ $tile['tone'] }}">
                    <p class="text-2xl font-black tabular-nums">{{ $tile['value'] }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mt-0.5">{{ $tile['label'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- ── Date & status ── --}}
        <form method="GET" class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_date') }}</label>
                <input type="date" name="date" value="{{ $date->toDateString() }}"
                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_status') }}</label>
                <select name="status" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="all">{{ __('health.hr_all') }}</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option }}" @selected($status === $option)>{{ __(HealthAttendanceDay::statusLabelKey($option)) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.hr_apply') }}
            </button>
            <div class="flex gap-2">
                <a href="{{ route('health.hr.attendance', ['date' => $date->copy()->subDay()->toDateString(), 'status' => $status]) }}"
                   class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold">&larr;</a>
                <a href="{{ route('health.hr.attendance', ['date' => $date->copy()->addDay()->toDateString(), 'status' => $status]) }}"
                   class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold">&rarr;</a>
            </div>
        </form>

        {{-- ── The floor ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
            @if(empty($rows))
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_staff_none') }}</p>
            @else
                <table class="min-w-full text-xs">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-900">
                            <th class="px-3 py-2 text-start font-black">{{ __('health.hr_staff') }}</th>
                            <th class="px-3 py-2 text-start font-black">{{ __('health.hr_shift') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_in') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_out') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_worked') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_late') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_early') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_overtime') }}</th>
                            <th class="px-3 py-2 text-start font-black">{{ __('health.hr_status') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($rows as $row)
                            @php
                                $day = $row['day'];
                                $shift = $row['shift'];
                                $flags = $day ? $day->flags() : [];
                            @endphp
                            <tr class="{{ $day && $day->status === 'absent' ? 'bg-rose-50/60 dark:bg-rose-900/10' : '' }}">
                                <td class="px-3 py-2 font-bold whitespace-nowrap">
                                    {{ $row['user']->name }}
                                    @if($row['profile']?->employee_code)
                                        <span class="ms-1 text-[10px] text-gray-500 dark:text-gray-400">{{ $row['profile']->employee_code }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    @if($shift)
                                        {{ $shift->name }}
                                        <span class="block text-[10px] text-gray-500 dark:text-gray-400 tabular-nums">
                                            {{ HealthShift::hhmm($shift->start_time) }}–{{ HealthShift::hhmm($shift->end_time) }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $day?->first_in?->format('H:i') ?? '—' }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">
                                    @if($day?->is_open)
                                        {{-- Still on duty: an open span is not a missing punch
                                             until the duty window has actually closed. --}}
                                        <span class="px-1.5 py-0.5 rounded bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300 font-black">{{ __('health.hr_on_duty_now') }}</span>
                                    @else
                                        {{ $day?->last_out?->format('H:i') ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center tabular-nums font-bold">{{ $day ? HealthAttendanceDay::hoursLabel($day->worked_minutes) : '—' }}</td>
                                <td class="px-3 py-2 text-center tabular-nums {{ ($day->late_minutes ?? 0) > 0 ? 'text-amber-700 dark:text-amber-300 font-bold' : '' }}">
                                    {{ ($day->late_minutes ?? 0) > 0 ? $day->late_minutes . 'm' : '—' }}
                                </td>
                                <td class="px-3 py-2 text-center tabular-nums {{ ($day->early_leave_minutes ?? 0) > 0 ? 'text-amber-700 dark:text-amber-300 font-bold' : '' }}">
                                    {{ ($day->early_leave_minutes ?? 0) > 0 ? $day->early_leave_minutes . 'm' : '—' }}
                                </td>
                                <td class="px-3 py-2 text-center tabular-nums {{ ($day->overtime_minutes ?? 0) > 0 ? 'text-emerald-700 dark:text-emerald-300 font-bold' : '' }}">
                                    {{ ($day->overtime_minutes ?? 0) > 0 ? HealthAttendanceDay::hoursLabel($day->overtime_minutes) : '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide
                                        @switch($day->status ?? 'absent')
                                            @case('present') @case('on_call') bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 @break
                                            @case('leave') @case('holiday') @case('weekly_off') @case('exempt') bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 @break
                                            @case('half_day') bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 @break
                                            @case('missed_punch') bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300 @break
                                            @default bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300
                                        @endswitch">
                                        {{ __(HealthAttendanceDay::statusLabelKey($day->status ?? 'absent')) }}
                                    </span>
                                    @if($day?->is_manual)
                                        <span class="ms-1 px-1.5 py-0.5 rounded text-[10px] font-black bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300">{{ __('health.hr_overridden') }}</span>
                                    @endif
                                    @if($flags)
                                        <span class="block mt-1 flex flex-wrap gap-1">
                                            @foreach($flags as $flag)
                                                <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-[9px] font-bold">{{ __(HealthAttendanceDay::flagLabelKey($flag)) }}</span>
                                            @endforeach
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-end whitespace-nowrap">
                                    <a href="{{ route('health.hr.attendance.day', ['userId' => $row['user']->id, 'date' => $date->toDateString()]) }}"
                                       class="text-teal-700 dark:text-teal-300 font-bold hover:underline">{{ __('health.hr_open') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-health-layout>
