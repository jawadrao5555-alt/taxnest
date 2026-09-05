@php
    use App\Models\HealthAttendanceCorrection;
    use App\Models\HealthAttendanceDay;
    use App\Models\HealthAttendancePunch;
    use App\Models\HealthShift;
@endphp
{{--
    One person, one day — the evidence, and what was derived from it.

    Disregarded punches stay on this page, struck through, with the reason and
    the correction that set them aside. Nothing is deleted from an attendance
    record; that is the difference between evidence and an opinion.
--}}
<x-health-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5" x-data="{ correcting: false, type: 'add_punch' }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <a href="{{ route('health.hr.attendance', ['date' => $date->toDateString()]) }}"
                   class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">&larr; {{ __('health.hr_back_to_day') }}</a>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight mt-1">{{ $member->name }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $date->translatedFormat('l, d F Y') }}
                    @if($profile?->designation) &middot; {{ $profile->designation }} @endif
                </p>
            </div>
            @if($canCorrect && !$monthLocked)
                <button type="button" @click="correcting = !correcting"
                        class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                    {{ __('health.hr_raise_correction') }}
                </button>
            @endif
        </div>

        @if($monthLocked)
            <div class="rounded-2xl bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 p-4">
                <p class="text-sm font-bold">{{ __('health.hr_month_locked_notice') }}</p>
            </div>
        @endif

        {{-- ── What was derived ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <span class="px-2.5 py-1 rounded-full text-[11px] font-black uppercase tracking-wide
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
                    {{-- An approved override froze this row: recomputation will not
                         quietly undo a decision somebody signed for. --}}
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300">
                        {{ __('health.hr_overridden') }}
                    </span>
                @endif
                @foreach($day?->flags() ?? [] as $flag)
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 dark:bg-gray-700">{{ __(HealthAttendanceDay::flagLabelKey($flag)) }}</span>
                @endforeach
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-sm">
                @php
                    $stats = [
                        __('health.hr_shift')     => $shift ? $shift->name . ' ' . HealthShift::hhmm($shift->start_time) . '–' . HealthShift::hhmm($shift->end_time) : '—',
                        __('health.hr_in')        => $day?->first_in?->format('H:i') ?? '—',
                        __('health.hr_out')       => $day?->is_open ? __('health.hr_on_duty_now') : ($day?->last_out?->format('H:i') ?? '—'),
                        __('health.hr_worked')    => $day ? HealthAttendanceDay::hoursLabel($day->worked_minutes) : '—',
                        __('health.hr_scheduled') => $day ? HealthAttendanceDay::hoursLabel($day->scheduled_minutes) : '—',
                        __('health.hr_overtime')  => $day ? HealthAttendanceDay::hoursLabel($day->overtime_minutes) : '—',
                    ];
                @endphp
                @foreach($stats as $label => $value)
                    <div class="rounded-xl bg-gray-50 dark:bg-gray-900 p-3">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="text-sm font-black mt-0.5">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            @if(($day->late_minutes ?? 0) > 0 || ($day->early_leave_minutes ?? 0) > 0)
                <p class="text-xs text-amber-700 dark:text-amber-300 font-bold">
                    @if(($day->late_minutes ?? 0) > 0)
                        {{ __('health.hr_late_by', ['minutes' => $day->late_minutes]) }}
                    @endif
                    @if(($day->early_leave_minutes ?? 0) > 0)
                        {{ __('health.hr_early_by', ['minutes' => $day->early_leave_minutes]) }}
                    @endif
                </p>
            @endif
        </div>

        {{-- ── Raise a correction ── --}}
        @if($canCorrect && !$monthLocked)
            <div x-show="correcting" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.hr.corrections.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="user_id" value="{{ $member->id }}">
                    <input type="hidden" name="attendance_date" value="{{ $date->toDateString() }}">

                    <h2 class="text-base font-black">{{ __('health.hr_raise_correction') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.hr_correction_hint') }}</p>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_correction_type') }}</label>
                            <select name="type" x-model="type" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                @foreach(HealthAttendanceCorrection::TYPES as $option)
                                    <option value="{{ $option }}">{{ __(HealthAttendanceCorrection::typeLabelKey($option)) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <template x-if="type === 'add_punch'">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_punch_time') }}</label>
                                    <input type="datetime-local" name="punch_at" value="{{ $date->format('Y-m-d\TH:i') }}"
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_direction') }}</label>
                                    <select name="direction" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                        <option value="in">{{ __('health.hr_in') }}</option>
                                        <option value="out">{{ __('health.hr_out') }}</option>
                                    </select>
                                </div>
                            </div>
                        </template>

                        <template x-if="type === 'disregard_punch'">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_target_punch') }}</label>
                                <select name="target_punch_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    @foreach($punches as $punch)
                                        @continue($punch->disregarded_at)
                                        <option value="{{ $punch->id }}">
                                            {{ $punch->punched_at->format('d M H:i') }} — {{ __('health.hr_dir_' . ($punch->direction ?: 'unknown')) }} ({{ __(HealthAttendancePunch::sourceLabelKey($punch->source)) }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </template>

                        <template x-if="type === 'set_status'">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_requested_status') }}</label>
                                <select name="requested_status" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    @foreach(HealthAttendanceDay::STATUSES as $option)
                                        <option value="{{ $option }}">{{ __(HealthAttendanceDay::statusLabelKey($option)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </template>

                        <template x-if="type === 'set_hours'">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_requested_minutes') }}</label>
                                <input type="number" name="requested_minutes" min="0" max="1440" value="{{ (int) ($day->worked_minutes ?? 0) }}"
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                        </template>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_reason') }}</label>
                            <input type="text" name="reason" required minlength="3" maxlength="500"
                                   placeholder="{{ __('health.hr_reason_hint') }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>

                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                        {{ __('health.hr_submit') }}
                    </button>
                </form>
            </div>
        @endif

        {{-- ── The evidence ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.hr_evidence_title') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_evidence_hint') }}</p>
            </div>

            @if($punches->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_no_punches') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($punches as $punch)
                        <div class="px-5 py-3 flex flex-wrap items-center gap-3 {{ $punch->disregarded_at ? 'opacity-60' : '' }}">
                            <span class="text-sm font-black tabular-nums {{ $punch->disregarded_at ? 'line-through' : '' }}">
                                {{ $punch->punched_at->format('d M H:i') }}
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase
                                {{ $punch->direction === 'in' ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : ($punch->direction === 'out' ? 'bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300' : 'bg-gray-200 dark:bg-gray-700') }}">
                                {{ __('health.hr_dir_' . ($punch->direction ?: 'unknown')) }}
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __(HealthAttendancePunch::sourceLabelKey($punch->source)) }}
                                @if($punch->device_pin) &middot; {{ __('health.hr_pin') }} {{ $punch->device_pin }} @endif
                                @if($punch->latitude && $punch->longitude) &middot; {{ __('health.hr_has_location') }} @endif
                                @if($punch->note) &middot; {{ $punch->note }} @endif
                            </span>
                            @if($punch->disregarded_at)
                                <span class="text-[11px] font-bold text-rose-600 dark:text-rose-400">
                                    {{ __('health.hr_disregarded') }}@if($punch->disregard_reason) — {{ $punch->disregard_reason }} @endif
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── The correction trail ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.hr_correction_trail') }}</h2>
            </div>

            @if($corrections->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_no_corrections') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($corrections as $correction)
                        <div class="px-5 py-3 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-bold">{{ __(HealthAttendanceCorrection::typeLabelKey($correction->type)) }}</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase
                                    @switch($correction->status)
                                        @case('approved') bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 @break
                                        @case('rejected') bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 @break
                                        @default bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300
                                    @endswitch">
                                    {{ __(HealthAttendanceCorrection::statusLabelKey($correction->status)) }}
                                </span>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-300">{{ $correction->reason }}</p>
                            @if($correction->reviewed_at)
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ $correction->reviewed_at->translatedFormat('d M Y H:i') }}
                                    @if($correction->review_note) — {{ $correction->review_note }} @endif
                                </p>
                            @endif
                            @if($correction->status === 'pending' && $canApprove && !$monthLocked)
                                <form method="POST" action="{{ route('health.hr.corrections.review', $correction->id) }}"
                                      class="flex flex-wrap items-end gap-2 pt-2">
                                    @csrf
                                    <input type="text" name="review_note" maxlength="500" placeholder="{{ __('health.hr_review_note') }}"
                                           class="flex-1 min-w-[180px] rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    <button type="submit" name="decision" value="approved"
                                            class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition">{{ __('health.hr_approve') }}</button>
                                    <button type="submit" name="decision" value="rejected"
                                            class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition">{{ __('health.hr_reject') }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
