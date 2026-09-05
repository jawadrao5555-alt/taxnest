@php
    use App\Models\HealthAttendanceCorrection;
    use App\Models\HealthAttendanceDay;
    use App\Models\HealthAttendancePunch;
    use App\Models\HealthLeaveRequest;
    use App\Models\HealthRosterEntry;
    use App\Models\HealthShift;
@endphp
{{--
    My duty — the only HR screen a nurse or a technician needs.

    It is deliberately outside /health/hr and gated by the module alone: an
    auditor who may not touch anybody else's record is still an employee who has
    to punch in. Nothing on this page takes a user id; every action is wired to
    the signed-in person on the server side.
--}}
<x-health-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{ tab: 'today', leaveForm: false, fixForm: false,
                   sending: false,
                   punch() {
                       if (this.sending) return;
                       this.sending = true;
                       const submit = (lat, lng) => {
                           document.getElementById('punch-lat').value = lat ?? '';
                           document.getElementById('punch-lng').value = lng ?? '';
                           document.getElementById('punch-form').submit();
                       };
                       {{-- Location is attached when the browser offers it, and the
                            server decides whether it was required. --}}
                       if (navigator.geolocation) {
                           navigator.geolocation.getCurrentPosition(
                               p => submit(p.coords.latitude, p.coords.longitude),
                               () => submit(null, null),
                               { timeout: 6000, maximumAge: 60000 }
                           );
                       } else {
                           submit(null, null);
                       }
                   } }">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_my_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                {{ $today->translatedFormat('l, d F Y') }}
                @if($profile?->designation) &middot; {{ $profile->designation }} @endif
            </p>
        </div>

        {{-- ── The punch ── --}}
        @php
            $todayDay = $days->first(fn ($day) => \Illuminate\Support\Carbon::parse($day->attendance_date)->isSameDay($today));
            $todayRoster = $roster->first(fn ($entry) => \Illuminate\Support\Carbon::parse($entry->duty_date)->isSameDay($today));
            $todayShift = $todayRoster && $todayRoster->health_shift_id ? $shifts->get($todayRoster->health_shift_id) : null;
            $canPunch = ($policy->web_checkin_enabled || $policy->mobile_checkin_enabled)
                && !($profile?->attendance_exempt)
                && !$monthLocked;
        @endphp

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.hr_today_duty') }}</p>
                    <p class="text-lg font-black mt-0.5">
                        @if($todayShift)
                            {{ $todayShift->name }}
                            <span class="text-sm font-bold text-gray-500 dark:text-gray-400 tabular-nums">
                                {{ HealthShift::hhmm($todayShift->start_time) }}–{{ HealthShift::hhmm($todayShift->end_time) }}
                            </span>
                        @elseif($todayRoster)
                            {{ __(HealthRosterEntry::typeLabelKey($todayRoster->entry_type)) }}
                        @else
                            {{ __('health.hr_no_duty_today') }}
                        @endif
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        {{ __('health.hr_in') }}: <span class="font-bold tabular-nums">{{ $todayDay?->first_in?->format('H:i') ?? '—' }}</span>
                        &middot;
                        {{ __('health.hr_out') }}: <span class="font-bold tabular-nums">{{ $todayDay?->is_open ? __('health.hr_on_duty_now') : ($todayDay?->last_out?->format('H:i') ?? '—') }}</span>
                        &middot;
                        {{ __('health.hr_worked') }}: <span class="font-bold tabular-nums">{{ HealthAttendanceDay::hoursLabel($todayDay->worked_minutes ?? 0) }}</span>
                    </p>
                </div>

                @if($canPunch)
                    <form id="punch-form" method="POST" action="{{ route('health.my.punch') }}">
                        @csrf
                        <input type="hidden" name="channel" value="web">
                        <input type="hidden" name="latitude" id="punch-lat">
                        <input type="hidden" name="longitude" id="punch-lng">
                        <button type="button" @click="punch()" :disabled="sending"
                                class="px-8 py-4 rounded-2xl text-white text-base font-black transition disabled:opacity-60
                                    {{ $nextDirection === 'in' ? 'bg-teal-700 hover:bg-teal-800' : 'bg-rose-600 hover:bg-rose-700' }}">
                            {{ $nextDirection === 'in' ? __('health.hr_check_in') : __('health.hr_check_out') }}
                        </button>
                    </form>
                @else
                    <p class="text-xs font-bold text-gray-500 dark:text-gray-400 max-w-[220px]">
                        @if($profile?->attendance_exempt)
                            {{ __('health.hr_checkin_exempt') }}
                        @elseif($monthLocked)
                            {{ __('health.hr_month_locked') }}
                        @else
                            {{ __('health.hr_checkin_web_off') }}
                        @endif
                    </p>
                @endif
            </div>

            @if($punches->isNotEmpty())
                <div class="flex flex-wrap gap-2 border-t border-gray-200 dark:border-gray-700 pt-3">
                    @foreach($punches as $punch)
                        <span class="px-2 py-1 rounded-lg text-[11px] font-bold tabular-nums
                            {{ $punch->disregarded_at ? 'line-through opacity-60 bg-gray-100 dark:bg-gray-700' : ($punch->direction === 'in' ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300') }}">
                            {{ $punch->punched_at->format('H:i') }} {{ __('health.hr_dir_' . ($punch->direction ?: 'unknown')) }}
                            <span class="opacity-70">{{ __(HealthAttendancePunch::sourceLabelKey($punch->source)) }}</span>
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── This month ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @php
                $tiles = [
                    ['label' => __('health.hr_tile_present'), 'value' => $monthTotals['present']],
                    ['label' => __('health.hr_tile_absent'),  'value' => $monthTotals['absent']],
                    ['label' => __('health.hr_tile_leave'),   'value' => $monthTotals['leave']],
                    ['label' => __('health.hr_tile_late'),    'value' => $monthTotals['late']],
                    ['label' => __('health.hr_worked'),       'value' => HealthAttendanceDay::hoursLabel($monthTotals['worked'])],
                    ['label' => __('health.hr_overtime'),     'value' => HealthAttendanceDay::hoursLabel($monthTotals['overtime'])],
                ];
            @endphp
            @foreach($tiles as $tile)
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-lg font-black tabular-nums">{{ $tile['value'] }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mt-0.5">{{ $tile['label'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- ── Tabs ── --}}
        <div class="flex flex-wrap gap-2">
            @foreach(['today' => __('health.hr_my_history'), 'roster' => __('health.hr_my_roster'), 'leave' => __('health.hr_my_leave'), 'fixes' => __('health.hr_my_fixes')] as $key => $label)
                <button type="button" @click="tab = '{{ $key }}'"
                        :class="tab === '{{ $key }}' ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700'"
                        class="px-4 py-2 rounded-xl text-sm font-bold transition">{{ $label }}</button>
            @endforeach
        </div>

        {{-- History --}}
        <div x-show="tab === 'today'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
            @if($days->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_no_history') }}</p>
            @else
                <table class="min-w-full text-xs">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-900">
                            <th class="px-3 py-2 text-start font-black">{{ __('health.hr_date') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_in') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_out') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_worked') }}</th>
                            <th class="px-3 py-2 text-center font-black">{{ __('health.hr_late') }}</th>
                            <th class="px-3 py-2 text-start font-black">{{ __('health.hr_status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($days as $day)
                            <tr>
                                <td class="px-3 py-2 font-bold whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($day->attendance_date)->translatedFormat('D, d M') }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $day->first_in?->format('H:i') ?? '—' }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $day->is_open ? __('health.hr_on_duty_now') : ($day->last_out?->format('H:i') ?? '—') }}</td>
                                <td class="px-3 py-2 text-center tabular-nums font-bold">{{ HealthAttendanceDay::hoursLabel($day->worked_minutes) }}</td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ (int) $day->late_minutes > 0 ? $day->late_minutes . 'm' : '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase
                                        @switch($day->status)
                                            @case('present') @case('on_call') bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 @break
                                            @case('leave') @case('holiday') @case('weekly_off') @case('exempt') bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 @break
                                            @case('half_day') @case('missed_punch') bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 @break
                                            @default bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300
                                        @endswitch">
                                        {{ __(HealthAttendanceDay::statusLabelKey($day->status)) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Roster --}}
        <div x-show="tab === 'roster'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($roster->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_no_roster') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($roster as $entry)
                        @php $shift = $entry->health_shift_id ? $shifts->get($entry->health_shift_id) : null; @endphp
                        <div class="px-5 py-3 flex flex-wrap items-center gap-3">
                            <span class="text-sm font-black min-w-[110px]">{{ \Illuminate\Support\Carbon::parse($entry->duty_date)->translatedFormat('D, d M') }}</span>
                            <span class="text-sm font-bold">
                                {{ $shift?->name ?? __(HealthRosterEntry::typeLabelKey($entry->entry_type)) }}
                                @if($shift)
                                    <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ HealthShift::hhmm($shift->start_time) }}–{{ HealthShift::hhmm($shift->end_time) }}</span>
                                @endif
                            </span>
                            @if($entry->entry_type === 'on_call')
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">{{ __('health.hr_on_call_badge') }}</span>
                            @endif
                            @if($entry->notes)
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $entry->notes }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Leave --}}
        <div x-show="tab === 'leave'" x-cloak class="space-y-4">
            <button type="button" @click="leaveForm = !leaveForm"
                    class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.hr_apply_leave') }}
            </button>

            <div x-show="leaveForm" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.my.leave') }}" class="grid sm:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_leave_type') }}</label>
                        <select name="health_leave_type_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            @foreach($leaveTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}{{ $type->is_paid ? '' : ' — ' . __('health.hr_unpaid') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex items-center gap-2 text-xs font-bold pb-2.5">
                            <input type="hidden" name="is_half_day" value="0">
                            <input type="checkbox" name="is_half_day" value="1" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            {{ __('health.hr_half_day') }}
                        </label>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_from') }}</label>
                        <input type="date" name="start_date" required value="{{ $today->toDateString() }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_to') }}</label>
                        <input type="date" name="end_date" required value="{{ $today->toDateString() }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_reason') }}</label>
                        <input type="text" name="reason" required maxlength="500"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                            {{ __('health.hr_submit') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                @if($myLeave->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_leave_none') }}</p>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($myLeave as $leave)
                            <div class="px-5 py-3 flex flex-wrap items-center gap-3">
                                <div class="flex-1 min-w-[200px]">
                                    <p class="text-sm font-bold">
                                        {{ $leaveTypes->firstWhere('id', (int) $leave->health_leave_type_id)->name ?? '—' }}
                                        <span class="text-xs font-normal text-gray-500 dark:text-gray-400">
                                            {{ $leave->start_date->translatedFormat('d M') }}
                                            @if(!$leave->start_date->isSameDay($leave->end_date)) – {{ $leave->end_date->translatedFormat('d M') }} @endif
                                        </span>
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $leave->reason }}</p>
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase
                                    @switch($leave->status)
                                        @case('approved') bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 @break
                                        @case('rejected') bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 @break
                                        @case('cancelled') bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300 @break
                                        @default bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300
                                    @endswitch">
                                    {{ __(HealthLeaveRequest::statusLabelKey($leave->status)) }}
                                </span>
                                @if($leave->status === 'pending')
                                    <form method="POST" action="{{ route('health.my.leave.cancel', $leave->id) }}">
                                        @csrf
                                        <button type="submit" class="text-xs font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.hr_cancel_request') }}</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Corrections --}}
        <div x-show="tab === 'fixes'" x-cloak class="space-y-4">
            <button type="button" @click="fixForm = !fixForm"
                    class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.hr_ask_fix') }}
            </button>

            <div x-show="fixForm" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                {{-- Only the two evidence-shaped fixes. A status or hours override
                     is HR's to raise, never the person being measured. --}}
                <form method="POST" action="{{ route('health.my.correction') }}" class="grid sm:grid-cols-2 gap-4"
                      x-data="{ fixType: 'add_punch' }">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_correction_type') }}</label>
                        <select name="type" x-model="fixType" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="add_punch">{{ __(HealthAttendanceCorrection::typeLabelKey('add_punch')) }}</option>
                            <option value="disregard_punch">{{ __(HealthAttendanceCorrection::typeLabelKey('disregard_punch')) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_date') }}</label>
                        <input type="date" name="attendance_date" required value="{{ $today->toDateString() }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <template x-if="fixType === 'add_punch'">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_punch_time') }}</label>
                                <input type="datetime-local" name="punch_at" value="{{ $today->format('Y-m-d\TH:i') }}"
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
                    <template x-if="fixType === 'disregard_punch'">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_target_punch') }}</label>
                            <select name="target_punch_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                @foreach($punches as $punch)
                                    @continue($punch->disregarded_at)
                                    <option value="{{ $punch->id }}">{{ $punch->punched_at->format('d M H:i') }} — {{ __('health.hr_dir_' . ($punch->direction ?: 'unknown')) }}</option>
                                @endforeach
                            </select>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_target_punch_today_only') }}</p>
                        </div>
                    </template>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_reason') }}</label>
                        <input type="text" name="reason" required minlength="3" maxlength="500" placeholder="{{ __('health.hr_reason_hint') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                            {{ __('health.hr_submit') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                @if($myCorrections->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_no_corrections') }}</p>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($myCorrections as $correction)
                            <div class="px-5 py-3 flex flex-wrap items-center gap-3">
                                <div class="flex-1 min-w-[200px]">
                                    <p class="text-sm font-bold">
                                        {{ __(HealthAttendanceCorrection::typeLabelKey($correction->type)) }}
                                        <span class="text-xs font-normal text-gray-500 dark:text-gray-400">
                                            {{ \Illuminate\Support\Carbon::parse($correction->attendance_date)->translatedFormat('d M Y') }}
                                        </span>
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $correction->reason }}</p>
                                    @if($correction->review_note)
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $correction->review_note }}</p>
                                    @endif
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase
                                    @switch($correction->status)
                                        @case('approved') bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 @break
                                        @case('rejected') bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 @break
                                        @default bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300
                                    @endswitch">
                                    {{ __(HealthAttendanceCorrection::statusLabelKey($correction->status)) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-health-layout>
