@php
    use App\Models\HealthShift;

    $dayNames = [
        1 => __('health.hr_day_1'), 2 => __('health.hr_day_2'), 3 => __('health.hr_day_3'),
        4 => __('health.hr_day_4'), 5 => __('health.hr_day_5'), 6 => __('health.hr_day_6'),
        7 => __('health.hr_day_7'),
    ];
@endphp
{{--
    Work patterns: shift templates, the holiday calendar and the leave types.

    They share one screen because they answer one question between them — what
    a given date is supposed to look like for a given person, before anybody
    punches anything.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{ tab: 'shifts', shiftForm: false, editing: null,
                   values: { name: '', code: '', start_time: '08:00', end_time: '16:00',
                             second_start_time: '', second_end_time: '', break_minutes: 0,
                             grace_in_minutes: '', grace_out_minutes: '', is_on_call: false, colour: 'teal' },
                   openNew() { this.editing = null;
                       this.values = { name: '', code: '', start_time: '08:00', end_time: '16:00',
                                       second_start_time: '', second_end_time: '', break_minutes: 0,
                                       grace_in_minutes: '', grace_out_minutes: '', is_on_call: false, colour: 'teal' };
                       this.shiftForm = true; },
                   openEdit(s) { this.editing = s.id; this.values = s; this.shiftForm = true; } }">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_shifts_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_shifts_subtitle') }}</p>
        </div>

        {{-- ── Tabs ── --}}
        <div class="flex flex-wrap gap-2">
            @foreach(['shifts' => __('health.hr_tab_shifts'), 'holidays' => __('health.hr_tab_holidays'), 'types' => __('health.hr_tab_leave_types')] as $key => $label)
                <button type="button" @click="tab = '{{ $key }}'"
                        :class="tab === '{{ $key }}' ? 'bg-teal-700 text-white border-teal-700' : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600'"
                        class="px-4 py-2 rounded-xl border text-sm font-bold transition">{{ $label }}</button>
            @endforeach
        </div>

        {{-- ═══════════ SHIFTS ═══════════ --}}
        <div x-show="tab === 'shifts'" class="space-y-4">
            @if($canManage)
                <div class="flex justify-end">
                    <button type="button" @click="openNew()"
                            class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.hr_shift_add') }}
                    </button>
                </div>

                <div x-show="shiftForm" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                    <form method="POST" :action="editing ? '{{ url('/health/hr/shifts') }}/' + editing : '{{ url('/health/hr/shifts') }}'" class="space-y-4">
                        @csrf
                        <template x-if="editing"><input type="hidden" name="_method" value="PUT"></template>

                        <h2 class="text-base font-black" x-text="editing ? '{{ __('health.hr_shift_edit') }}' : '{{ __('health.hr_shift_add') }}'"></h2>

                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_shift_name') }}</label>
                                <input type="text" name="name" x-model="values.name" required
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_code') }}</label>
                                <input type="text" name="code" x-model="values.code" maxlength="32"
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_break_minutes') }}</label>
                                <input type="number" name="break_minutes" x-model="values.break_minutes" min="0" max="600"
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_start_time') }}</label>
                                <input type="time" name="start_time" x-model="values.start_time" required
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_end_time') }}</label>
                                <input type="time" name="end_time" x-model="values.end_time" required
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                {{-- An end at or before the start is a night duty. No extra
                                     tick-box: the server decides it from the two times. --}}
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_overnight_hint') }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_second_start') }}</label>
                                <input type="time" name="second_start_time" x-model="values.second_start_time"
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_second_end') }}</label>
                                <input type="time" name="second_end_time" x-model="values.second_end_time"
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_split_hint') }}</p>
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_grace_in') }}</label>
                                <input type="number" name="grace_in_minutes" x-model="values.grace_in_minutes" min="0" max="240"
                                       placeholder="{{ __('health.hr_from_policy') }}"
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_grace_out') }}</label>
                                <input type="number" name="grace_out_minutes" x-model="values.grace_out_minutes" min="0" max="240"
                                       placeholder="{{ __('health.hr_from_policy') }}"
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_colour') }}</label>
                                <select name="colour" x-model="values.colour"
                                        class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    @foreach(['teal', 'sky', 'indigo', 'amber', 'rose', 'emerald', 'slate'] as $colour)
                                        <option value="{{ $colour }}">{{ __('health.hr_colour_' . $colour) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex items-end">
                                <label class="inline-flex items-center gap-2 text-xs font-bold pb-2.5">
                                    <input type="hidden" name="is_on_call" value="0">
                                    <input type="checkbox" name="is_on_call" value="1" x-model="values.is_on_call"
                                           class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                    {{ __('health.hr_is_on_call') }}
                                </label>
                            </div>
                        </div>

                        <input type="hidden" name="is_active" value="1">

                        <div class="flex items-center gap-2">
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                                {{ __('health.hr_save') }}
                            </button>
                            <button type="button" @click="shiftForm = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">
                                {{ __('health.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                @if($shifts->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_shift_none') }}</p>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($shifts as $shift)
                            <div class="px-5 py-4 flex flex-wrap items-center gap-3">
                                <div class="flex-1 min-w-[220px]">
                                    <p class="text-sm font-black">
                                        {{ $shift->name }}
                                        @if($shift->code)
                                            <span class="ms-1.5 text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ $shift->code }}</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 tabular-nums">
                                        {{ HealthShift::hhmm($shift->start_time) }}–{{ HealthShift::hhmm($shift->end_time) }}
                                        @if($shift->hasSecondSpan())
                                            + {{ HealthShift::hhmm($shift->second_start_time) }}–{{ HealthShift::hhmm($shift->second_end_time) }}
                                        @endif
                                        &middot; {{ __('health.hr_scheduled_hours', ['hours' => number_format($shift->scheduledMinutes() / 60, 2)]) }}
                                        @if($shift->break_minutes) &middot; {{ __('health.hr_break_of', ['minutes' => $shift->break_minutes]) }} @endif
                                    </p>
                                </div>

                                @if($shift->crosses_midnight)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300">
                                        {{ __('health.hr_overnight_badge') }}
                                    </span>
                                @endif
                                @if($shift->is_on_call)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300">
                                        {{ __('health.hr_on_call_badge') }}
                                    </span>
                                @endif
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide
                                    {{ $shift->is_active ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                                    {{ $shift->is_active ? __('health.dept_active') : __('health.dept_inactive') }}
                                </span>

                                @if($canManage)
                                    @php
                                        // Built above the attribute: a multi-line array inside a
                                        // Blade directive attribute breaks bracket matching, and a
                                        // malformed name would otherwise kill the whole component.
                                        $scrub = fn ($value) => mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8');
                                        $shiftPayload = \Illuminate\Support\Js::from([
                                            'id'                => (int) $shift->id,
                                            'name'              => $scrub($shift->name),
                                            'code'              => $scrub($shift->code),
                                            'start_time'        => HealthShift::hhmm($shift->start_time),
                                            'end_time'          => HealthShift::hhmm($shift->end_time),
                                            'second_start_time' => HealthShift::hhmm($shift->second_start_time),
                                            'second_end_time'   => HealthShift::hhmm($shift->second_end_time),
                                            'break_minutes'     => (int) $shift->break_minutes,
                                            'grace_in_minutes'  => $shift->grace_in_minutes,
                                            'grace_out_minutes' => $shift->grace_out_minutes,
                                            'is_on_call'        => (bool) $shift->is_on_call,
                                            'colour'            => $scrub($shift->colour ?: 'teal'),
                                        ]);
                                    @endphp
                                    <button type="button" @click="openEdit({{ $shiftPayload }})"
                                            class="px-3 py-1.5 rounded-lg text-xs font-bold border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        {{ __('health.hr_edit') }}
                                    </button>
                                    <form method="POST" action="{{ route('health.hr.shifts.toggle', $shift->id) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                            {{ $shift->is_active ? __('health.hr_deactivate') : __('health.hr_activate') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- ═══════════ HOLIDAYS ═══════════ --}}
        <div x-show="tab === 'holidays'" x-cloak class="space-y-4">
            @if($canManage)
                <form method="POST" action="{{ route('health.hr.holidays.store') }}"
                      class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 grid sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                    @csrf
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_holiday_name') }}</label>
                        <input type="text" name="name" required maxlength="120"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_date') }}</label>
                        <input type="date" name="holiday_date" required value="{{ now()->toDateString() }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_branch') }}</label>
                        <select name="branch_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">{{ __('health.hr_all_branches') }}</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="inline-flex items-center gap-2 text-xs font-bold">
                            <input type="hidden" name="is_paid" value="0">
                            <input type="checkbox" name="is_paid" value="1" checked
                                   class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            {{ __('health.hr_paid') }}
                        </label>
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                            {{ __('health.hr_add') }}
                        </button>
                    </div>
                </form>
            @endif

            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                @if($holidays->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_holiday_none') }}</p>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($holidays as $holiday)
                            <div class="px-5 py-3 flex flex-wrap items-center gap-3">
                                <div class="flex-1 min-w-[200px]">
                                    <p class="text-sm font-bold">{{ $holiday->name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ \Illuminate\Support\Carbon::parse($holiday->holiday_date)->translatedFormat('D, d M Y') }}
                                        &middot; {{ $holiday->is_paid ? __('health.hr_paid') : __('health.hr_unpaid') }}
                                        @if($holiday->branch_id)
                                            &middot; {{ optional($branches->firstWhere('id', $holiday->branch_id))->name }}
                                        @else
                                            &middot; {{ __('health.hr_all_branches') }}
                                        @endif
                                    </p>
                                </div>
                                @if($canManage)
                                    <form method="POST" action="{{ route('health.hr.holidays.destroy', $holiday->id) }}"
                                          onsubmit="return confirm('{{ __('health.hr_confirm_delete') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800 hover:bg-rose-50 dark:hover:bg-rose-900/30">
                                            {{ __('health.hr_delete') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- ═══════════ LEAVE TYPES ═══════════ --}}
        <div x-show="tab === 'types'" x-cloak class="space-y-4">
            @if($canManage)
                <form method="POST" action="{{ route('health.hr.leave-types.store') }}"
                      class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 grid sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                    @csrf
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_leave_type_name') }}</label>
                        <input type="text" name="name" required maxlength="120"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_code') }}</label>
                        <input type="text" name="code" maxlength="20"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_annual_quota') }}</label>
                        <input type="number" name="annual_quota_days" step="0.5" min="0" max="365" value="0"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="inline-flex items-center gap-2 text-xs font-bold">
                            <input type="hidden" name="is_paid" value="0">
                            <input type="checkbox" name="is_paid" value="1" checked class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            {{ __('health.hr_paid') }}
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs font-bold">
                            <input type="hidden" name="requires_approval" value="0">
                            <input type="checkbox" name="requires_approval" value="1" checked class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            {{ __('health.hr_requires_approval') }}
                        </label>
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                            {{ __('health.hr_add') }}
                        </button>
                    </div>
                </form>
            @endif

            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($leaveTypes as $type)
                        <form method="POST" action="{{ route('health.hr.leave-types.update', $type->id) }}"
                              class="px-5 py-3 flex flex-wrap items-center gap-3">
                            @csrf
                            @method('PUT')
                            <input type="text" name="name" value="{{ $type->name }}" required @disabled(!$canManage)
                                   class="flex-1 min-w-[160px] rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <input type="text" name="code" value="{{ $type->code }}" maxlength="20" @disabled(!$canManage)
                                   class="w-24 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <div class="flex items-center gap-1.5">
                                <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400">{{ __('health.hr_annual_quota') }}</span>
                                <input type="number" name="annual_quota_days" value="{{ (float) $type->annual_quota_days }}" step="0.5" min="0" max="365" @disabled(!$canManage)
                                       class="w-20 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                            <label class="inline-flex items-center gap-2 text-xs font-bold">
                                <input type="hidden" name="is_paid" value="0">
                                <input type="checkbox" name="is_paid" value="1" @checked($type->is_paid) @disabled(!$canManage)
                                       class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                {{ __('health.hr_paid') }}
                            </label>
                            <label class="inline-flex items-center gap-2 text-xs font-bold">
                                <input type="hidden" name="requires_approval" value="0">
                                <input type="checkbox" name="requires_approval" value="1" @checked($type->requires_approval) @disabled(!$canManage)
                                       class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                {{ __('health.hr_requires_approval') }}
                            </label>
                            <label class="inline-flex items-center gap-2 text-xs font-bold">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" @checked($type->is_active) @disabled(!$canManage)
                                       class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                {{ __('health.dept_active') }}
                            </label>
                            @if($canManage)
                                <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                    {{ __('health.hr_save') }}
                                </button>
                            @endif
                        </form>
                    @endforeach
                </div>
            </div>

            {{-- Paid vs unpaid is what payroll reads; it is a property of the
                 TYPE, so changing it here changes every future approval. --}}
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.hr_leave_type_hint') }}</p>
        </div>
    </div>
</x-health-layout>
