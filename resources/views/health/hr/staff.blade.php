@php
    use App\Models\HealthStaffProfile;

    $dayNames = [
        1 => __('health.hr_day_1'), 2 => __('health.hr_day_2'), 3 => __('health.hr_day_3'),
        4 => __('health.hr_day_4'), 5 => __('health.hr_day_5'), 6 => __('health.hr_day_6'),
        7 => __('health.hr_day_7'),
    ];
@endphp
{{--
    Employment records.

    There is deliberately no "add staff" button: an employment record attaches
    to an account that already exists on the team screen. Two identities for one
    person is how HR and the login list start disagreeing about who works here.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_staff_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_staff_subtitle') }}</p>
            </div>
            @if(Route::has('health.team'))
                <a href="{{ route('health.team') }}" class="text-sm font-bold text-teal-700 dark:text-teal-300 hover:underline">
                    {{ __('health.hr_staff_add_hint') }}
                </a>
            @endif
        </div>

        {{-- ── Filters ── --}}
        <form method="GET" class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[180px]">
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_search') }}</label>
                <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('health.hr_search_hint') }}"
                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            </div>
            <div class="min-w-[160px]">
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_status') }}</label>
                <select name="status" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="">{{ __('health.hr_all') }}</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option }}" @selected($filterStatus === $option)>{{ __(HealthStaffProfile::statusLabelKey($option)) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.hr_apply') }}
            </button>
        </form>

        {{-- ── The list ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($staff->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_staff_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($staff as $member)
                        @php
                            $profile = $profiles[(int) $member->id] ?? null;
                            $status = $profile->employment_status ?? 'active';
                            $offDays = is_array($profile->weekly_off_days ?? null) ? $profile->weekly_off_days : [];
                        @endphp
                        <div x-data="{ open: false }" class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="flex-1 min-w-[200px]">
                                    <p class="text-sm font-black">
                                        {{ $member->name }}
                                        @if($profile?->employee_code)
                                            <span class="ms-1.5 text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ $profile->employee_code }}</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ $profile?->designation ?: __('health.hr_no_designation') }}
                                        &middot; {{ __(HealthStaffProfile::typeLabelKey($profile->employment_type ?? 'permanent')) }}
                                        @if($profile?->default_shift_id && $shifts->firstWhere('id', $profile->default_shift_id))
                                            &middot; {{ $shifts->firstWhere('id', $profile->default_shift_id)->name }}
                                        @endif
                                    </p>
                                </div>

                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide
                                    {{ in_array($status, ['active', 'probation'], true)
                                        ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300'
                                        : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                                    {{ __(HealthStaffProfile::statusLabelKey($status)) }}
                                </span>

                                @if($profile?->attendance_exempt)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300">
                                        {{ __('health.hr_exempt_badge') }}
                                    </span>
                                @endif

                                @if($canManage)
                                    <button type="button" @click="open = !open"
                                            class="px-3 py-1.5 rounded-lg text-xs font-bold border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <span x-show="!open">{{ __('health.hr_edit') }}</span>
                                        <span x-show="open" x-cloak>{{ __('health.cancel') }}</span>
                                    </button>
                                @endif
                            </div>

                            @if($canManage)
                                <form x-show="open" x-cloak method="POST" action="{{ route('health.hr.staff.update', $member->id) }}"
                                      class="mt-4 grid sm:grid-cols-2 lg:grid-cols-3 gap-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                                    @csrf
                                    @method('PUT')

                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_employee_code') }}</label>
                                        <input type="text" name="employee_code" value="{{ $profile?->employee_code }}" maxlength="32"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_designation') }}</label>
                                        <input type="text" name="designation" value="{{ $profile?->designation }}" maxlength="120"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_employment_type') }}</label>
                                        <select name="employment_type" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                            @foreach($types as $type)
                                                <option value="{{ $type }}" @selected(($profile->employment_type ?? 'permanent') === $type)>{{ __(HealthStaffProfile::typeLabelKey($type)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_status') }}</label>
                                        <select name="employment_status" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                            @foreach($statuses as $option)
                                                <option value="{{ $option }}" @selected($status === $option)>{{ __(HealthStaffProfile::statusLabelKey($option)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_joined_on') }}</label>
                                        <input type="date" name="joined_on" value="{{ $profile?->joined_on?->format('Y-m-d') }}"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_left_on') }}</label>
                                        <input type="date" name="left_on" value="{{ $profile?->left_on?->format('Y-m-d') }}"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_branch') }}</label>
                                        <select name="branch_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                            <option value="">{{ __('health.hr_none') }}</option>
                                            @foreach($branches as $branch)
                                                <option value="{{ $branch->id }}" @selected((int) ($profile->branch_id ?? 0) === (int) $branch->id)>{{ $branch->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_supervisor') }}</label>
                                        <select name="supervisor_user_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                            <option value="">{{ __('health.hr_none') }}</option>
                                            @foreach($allStaff as $candidate)
                                                @continue((int) $candidate->id === (int) $member->id)
                                                <option value="{{ $candidate->id }}" @selected((int) ($profile->supervisor_user_id ?? 0) === (int) $candidate->id)>{{ $candidate->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_default_shift') }}</label>
                                        <select name="default_shift_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                            <option value="">{{ __('health.hr_none') }}</option>
                                            @foreach($shifts as $shift)
                                                <option value="{{ $shift->id }}" @selected((int) ($profile->default_shift_id ?? 0) === (int) $shift->id)>{{ $shift->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    {{-- Weekly off: empty means "follow the organisation policy",
                                         which is why there is no "same as policy" tick. --}}
                                    <div class="sm:col-span-2 lg:col-span-3">
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_weekly_off') }}</label>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($dayNames as $iso => $label)
                                                <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-xs font-bold cursor-pointer">
                                                    <input type="checkbox" name="weekly_off_days[]" value="{{ $iso }}" @checked(in_array($iso, $offDays, true))
                                                           class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                                    {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_weekly_off_hint') }}</p>
                                    </div>

                                    <div class="flex items-center gap-4 sm:col-span-2 lg:col-span-3">
                                        <label class="inline-flex items-center gap-2 text-xs font-bold">
                                            <input type="hidden" name="attendance_exempt" value="0">
                                            <input type="checkbox" name="attendance_exempt" value="1" @checked($profile?->attendance_exempt)
                                                   class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                            {{ __('health.hr_attendance_exempt') }}
                                        </label>
                                        <label class="inline-flex items-center gap-2 text-xs font-bold">
                                            <input type="hidden" name="overtime_eligible" value="0">
                                            <input type="checkbox" name="overtime_eligible" value="1" @checked($profile === null || $profile->overtime_eligible)
                                                   class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                            {{ __('health.hr_overtime_eligible') }}
                                        </label>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_qualification') }}</label>
                                        <input type="text" name="qualification" value="{{ $profile?->qualification }}" maxlength="190"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_license_no') }}</label>
                                        <input type="text" name="license_no" value="{{ $profile?->license_no }}" maxlength="64"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_cnic') }}</label>
                                        <input type="text" name="cnic" value="{{ $profile?->cnic }}" maxlength="20"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_emergency_contact') }}</label>
                                        <input type="text" name="emergency_contact" value="{{ $profile?->emergency_contact }}" maxlength="120"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>

                                    {{-- Salary inputs are only rendered for somebody who may read
                                         the payroll handoff; the controller drops them otherwise. --}}
                                    @if($canPay)
                                        <div>
                                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_basic_salary') }}</label>
                                            <input type="number" step="0.01" min="0" name="basic_salary" value="{{ $profile?->basic_salary }}"
                                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_overtime_rate') }}</label>
                                            <input type="number" step="0.01" min="0" name="overtime_hourly_rate" value="{{ $profile?->overtime_hourly_rate }}"
                                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                        </div>
                                    @endif

                                    <div class="sm:col-span-2 lg:col-span-3">
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_notes') }}</label>
                                        <input type="text" name="notes" value="{{ $profile?->notes }}" maxlength="500"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>

                                    <div class="sm:col-span-2 lg:col-span-3">
                                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                                            {{ __('health.hr_save') }}
                                        </button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
