@php
    use App\Models\HealthRosterEntry;
    use App\Models\HealthShift;

    $tone = [
        'teal'    => 'bg-teal-100 dark:bg-teal-900/40 text-teal-800 dark:text-teal-200',
        'sky'     => 'bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-200',
        'indigo'  => 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-800 dark:text-indigo-200',
        'amber'   => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
        'rose'    => 'bg-rose-100 dark:bg-rose-900/40 text-rose-800 dark:text-rose-200',
        'emerald' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200',
        'slate'   => 'bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200',
    ];
    $dayNames = [
        1 => __('health.hr_day_1'), 2 => __('health.hr_day_2'), 3 => __('health.hr_day_3'),
        4 => __('health.hr_day_4'), 5 => __('health.hr_day_5'), 6 => __('health.hr_day_6'),
        7 => __('health.hr_day_7'),
    ];
@endphp
{{--
    The duty roster.

    One row per person, one cell per date — because a hospital reads coverage
    down a column ("who is on nights in ICU on Thursday"), not across a list of
    bookings. Coverage per department sits under the grid for the same reason.
--}}
<x-health-layout>
    <div class="max-w-full mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{ cell: null, bulk: false,
                   openCell(userId, name, date, entry) {
                       this.cell = { userId, name, date,
                                     entry_type: entry?.entry_type || 'shift',
                                     health_shift_id: entry?.health_shift_id || '',
                                     health_department_id: entry?.health_department_id || '',
                                     branch_id: entry?.branch_id || '',
                                     notes: entry?.notes || '' };
                   } }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_roster_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $start->translatedFormat('d M Y') }} – {{ $end->translatedFormat('d M Y') }}
                </p>
            </div>
            @if($canManage)
                <button type="button" @click="bulk = !bulk"
                        class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                    {{ __('health.hr_roster_publish') }}
                </button>
            @endif
        </div>

        {{-- ── Window & filters ── --}}
        <form method="GET" class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_from') }}</label>
                <input type="date" name="from" value="{{ $start->toDateString() }}"
                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_days') }}</label>
                <select name="days" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    @foreach([7, 14, 28, 31] as $option)
                        <option value="{{ $option }}" @selected($span === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_department') }}</label>
                <select name="department_id" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="0">{{ __('health.hr_all') }}</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected($departmentId === (int) $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_branch') }}</label>
                <select name="branch_id" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="0">{{ __('health.hr_all') }}</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($branchId === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.hr_apply') }}
            </button>
            <div class="flex gap-2">
                <a href="{{ route('health.hr.roster', ['from' => $start->copy()->subDays($span)->toDateString(), 'days' => $span, 'department_id' => $departmentId, 'branch_id' => $branchId]) }}"
                   class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold">&larr;</a>
                <a href="{{ route('health.hr.roster', ['from' => $start->copy()->addDays($span)->toDateString(), 'days' => $span, 'department_id' => $departmentId, 'branch_id' => $branchId]) }}"
                   class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold">&rarr;</a>
            </div>
        </form>

        {{-- ── Bulk publish ── --}}
        @if($canManage)
            <div x-show="bulk" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.hr.roster.bulk') }}" class="space-y-4">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.hr_roster_publish') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.hr_roster_publish_hint') }}</p>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_from') }}</label>
                            <input type="date" name="from" required value="{{ $start->toDateString() }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_to') }}</label>
                            <input type="date" name="to" required value="{{ $end->toDateString() }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_entry_type') }}</label>
                            <select name="entry_type" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                @foreach($types as $type)
                                    <option value="{{ $type }}">{{ __(HealthRosterEntry::typeLabelKey($type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_shift') }}</label>
                            <select name="health_shift_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">{{ __('health.hr_none') }}</option>
                                @foreach($shifts as $shift)
                                    @continue(!$shift->is_active)
                                    <option value="{{ $shift->id }}">{{ $shift->name }} ({{ HealthShift::hhmm($shift->start_time) }}–{{ HealthShift::hhmm($shift->end_time) }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_department') }}</label>
                            <select name="health_department_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">{{ __('health.hr_none') }}</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}" @selected($departmentId === (int) $department->id)>{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_branch') }}</label>
                            <select name="branch_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">{{ __('health.hr_none') }}</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected($branchId === (int) $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_notes') }}</label>
                            <input type="text" name="notes" maxlength="255"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_weekdays') }}</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach($dayNames as $iso => $label)
                                <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-xs font-bold cursor-pointer">
                                    <input type="checkbox" name="weekdays[]" value="{{ $iso }}" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_weekdays_hint') }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_staff') }}</label>
                        <div class="max-h-56 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-700 p-3 grid sm:grid-cols-2 lg:grid-cols-3 gap-1.5">
                            @foreach($staff as $member)
                                <label class="inline-flex items-center gap-2 text-xs font-bold">
                                    <input type="checkbox" name="user_ids[]" value="{{ $member->id }}" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                    {{ $member->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <label class="inline-flex items-center gap-2 text-xs font-bold">
                            <input type="hidden" name="skip_off_days" value="0">
                            <input type="checkbox" name="skip_off_days" value="1" checked class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            {{ __('health.hr_skip_off_days') }}
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs font-bold">
                            <input type="hidden" name="overwrite" value="0">
                            <input type="checkbox" name="overwrite" value="1" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            {{ __('health.hr_overwrite_existing') }}
                        </label>
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                            {{ __('health.hr_publish') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── The grid ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
            @if($staff->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_staff_none') }}</p>
            @else
                <table class="min-w-full text-xs">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-900">
                            <th class="sticky start-0 z-10 bg-gray-50 dark:bg-gray-900 px-3 py-2 text-start font-black min-w-[160px]">{{ __('health.hr_staff') }}</th>
                            @foreach($dates as $date)
                                <th class="px-1.5 py-2 text-center font-bold min-w-[74px] {{ $date->isToday() ? 'bg-teal-50 dark:bg-teal-900/30' : '' }}">
                                    <span class="block">{{ $date->translatedFormat('D') }}</span>
                                    <span class="block text-[10px] text-gray-500 dark:text-gray-400">{{ $date->format('d/m') }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($staff as $member)
                            @php $memberEntries = $entries[(int) $member->id] ?? []; @endphp
                            <tr>
                                <td class="sticky start-0 z-10 bg-white dark:bg-gray-800 px-3 py-2 font-bold whitespace-nowrap">
                                    {{ $member->name }}
                                </td>
                                @foreach($dates as $date)
                                    @php
                                        $key = $date->toDateString();
                                        $entry = $memberEntries[$key] ?? null;
                                        $shift = $entry && $entry->health_shift_id ? $shifts->firstWhere('id', $entry->health_shift_id) : null;
                                        $cellTone = $entry
                                            ? ($entry->entry_type === 'off'
                                                ? 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400'
                                                : ($tone[$shift->colour ?? 'teal'] ?? $tone['teal']))
                                            : '';
                                        $scrub = fn ($value) => mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8');
                                        $cellPayload = \Illuminate\Support\Js::from($entry ? [
                                            'entry_type'           => $entry->entry_type,
                                            'health_shift_id'      => $entry->health_shift_id,
                                            'health_department_id' => $entry->health_department_id,
                                            'branch_id'            => $entry->branch_id,
                                            'notes'                => $scrub($entry->notes),
                                        ] : null);
                                        $namePayload = \Illuminate\Support\Js::from($scrub($member->name));
                                    @endphp
                                    <td class="px-1 py-1 text-center align-middle">
                                        <button type="button"
                                                @if($canManage) @click="openCell({{ (int) $member->id }}, {{ $namePayload }}, '{{ $key }}', {{ $cellPayload }})" @else disabled @endif
                                                class="w-full rounded-lg px-1 py-1.5 text-[10px] font-bold leading-tight {{ $cellTone ?: 'text-gray-300 dark:text-gray-600' }} {{ $canManage ? 'hover:ring-2 hover:ring-teal-400' : '' }}">
                                            @if($entry)
                                                <span class="block truncate">{{ $shift?->name ?? __(HealthRosterEntry::typeLabelKey($entry->entry_type)) }}</span>
                                                @if($shift)
                                                    <span class="block text-[9px] opacity-80 tabular-nums">{{ HealthShift::hhmm($shift->start_time) }}</span>
                                                @endif
                                                @if($entry->entry_type === 'on_call')
                                                    <span class="block text-[9px] font-black">{{ __('health.hr_on_call_short') }}</span>
                                                @endif
                                            @else
                                                &middot;
                                            @endif
                                        </button>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Coverage ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-3">
            <h2 class="text-base font-black">{{ __('health.hr_coverage_title') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.hr_coverage_hint') }}</p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-900">
                            <th class="px-3 py-2 text-start font-black">{{ __('health.hr_date') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_covering') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_on_call_badge') }}</th>
                            <th class="px-3 py-2 text-start font-black">{{ __('health.hr_by_department') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($dates as $date)
                            @php $day = $coverage[$date->toDateString()] ?? ['total' => 0, 'on_call' => 0, 'departments' => []]; @endphp
                            <tr class="{{ $day['total'] === 0 ? 'bg-rose-50 dark:bg-rose-900/20' : '' }}">
                                <td class="px-3 py-2 font-bold whitespace-nowrap">{{ $date->translatedFormat('D, d M') }}</td>
                                <td class="px-3 py-2 text-center font-black tabular-nums">{{ $day['total'] }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $day['on_call'] }}</td>
                                <td class="px-3 py-2">
                                    @if(empty($day['departments']))
                                        <span class="text-rose-600 dark:text-rose-400 font-bold">{{ __('health.hr_no_coverage') }}</span>
                                    @else
                                        <span class="flex flex-wrap gap-1.5">
                                            @foreach($day['departments'] as $label => $count)
                                                <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 font-bold">{{ $label }} {{ $count }}</span>
                                            @endforeach
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── One cell ── --}}
        @if($canManage)
            <div x-show="cell" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="cell = null">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-gray-800 p-5 space-y-4 max-h-[90vh] overflow-y-auto">
                    <div>
                        <h2 class="text-base font-black" x-text="cell?.name"></h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400" x-text="cell?.date"></p>
                    </div>

                    <form method="POST" action="{{ route('health.hr.roster.store') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="user_id" :value="cell?.userId">
                        <input type="hidden" name="duty_date" :value="cell?.date">

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_entry_type') }}</label>
                            <select name="entry_type" x-model="cell.entry_type"
                                    class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                @foreach($types as $type)
                                    <option value="{{ $type }}">{{ __(HealthRosterEntry::typeLabelKey($type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_shift') }}</label>
                            <select name="health_shift_id" x-model="cell.health_shift_id"
                                    class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">{{ __('health.hr_none') }}</option>
                                @foreach($shifts as $shift)
                                    @continue(!$shift->is_active)
                                    <option value="{{ $shift->id }}">{{ $shift->name }} ({{ HealthShift::hhmm($shift->start_time) }}–{{ HealthShift::hhmm($shift->end_time) }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_department') }}</label>
                            <select name="health_department_id" x-model="cell.health_department_id"
                                    class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">{{ __('health.hr_none') }}</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_branch') }}</label>
                            <select name="branch_id" x-model="cell.branch_id"
                                    class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">{{ __('health.hr_none') }}</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_notes') }}</label>
                            <input type="text" name="notes" x-model="cell.notes" maxlength="255"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                                {{ __('health.hr_save') }}
                            </button>
                            <button type="button" @click="cell = null" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">
                                {{ __('health.cancel') }}
                            </button>
                        </div>
                    </form>

                    {{-- Clearing one cell reuses the range endpoint with a
                         one-day range — one rule about locked months, not two. --}}
                    <form method="POST" action="{{ route('health.hr.roster.clear') }}" class="border-t border-gray-200 dark:border-gray-700 pt-3">
                        @csrf
                        <input type="hidden" name="user_ids[]" :value="cell?.userId">
                        <input type="hidden" name="from" :value="cell?.date">
                        <input type="hidden" name="to" :value="cell?.date">
                        <button type="submit" class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline">
                            {{ __('health.hr_roster_clear_cell') }}
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</x-health-layout>
