@php
    use App\Models\HealthHrPolicy;

    $dayNames = [
        1 => __('health.hr_day_1'), 2 => __('health.hr_day_2'), 3 => __('health.hr_day_3'),
        4 => __('health.hr_day_4'), 5 => __('health.hr_day_5'), 6 => __('health.hr_day_6'),
        7 => __('health.hr_day_7'),
    ];
    $offDays = $policy->offDays();
@endphp
{{--
    Attendance policy — the organisation-level answers the calculation needs.

    Two hospitals on the same deploy genuinely disagree about all of these, so
    they are stored per company rather than assumed in code.
--}}
<x-health-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_policy_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_policy_subtitle') }}</p>
        </div>

        <form method="POST" action="{{ route('health.hr.policy.update') }}" class="space-y-5">
            @csrf

            {{-- ── The day itself ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                <h2 class="text-base font-black">{{ __('health.hr_policy_day') }}</h2>

                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_business_day_start') }}</label>
                        <input type="time" name="business_day_start" required
                               value="{{ substr($policy->business_day_start, 0, 5) }}" @disabled(!$canManage)
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        {{-- A night nurse who punches out at 04:00 belongs to
                             YESTERDAY's duty. This clock time is where one
                             attendance day ends and the next begins. --}}
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_business_day_start_hint') }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_grace_in') }}</label>
                        <input type="number" name="grace_in_minutes" min="0" max="240" required
                               value="{{ (int) $policy->grace_in_minutes }}" @disabled(!$canManage)
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_grace_out') }}</label>
                        <input type="number" name="grace_out_minutes" min="0" max="240" required
                               value="{{ (int) $policy->grace_out_minutes }}" @disabled(!$canManage)
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_half_day_minutes') }}</label>
                        <input type="number" name="half_day_minutes" min="30" max="1440" required
                               value="{{ (int) $policy->half_day_minutes }}" @disabled(!$canManage)
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_full_day_minutes') }}</label>
                        <input type="number" name="full_day_minutes" min="60" max="1440" required
                               value="{{ (int) $policy->full_day_minutes }}" @disabled(!$canManage)
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_day_minutes_hint') }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_missed_punch_status') }}</label>
                        <select name="missed_punch_status" @disabled(!$canManage)
                                class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            @foreach($statuses as $option)
                                <option value="{{ $option }}" @selected($policy->missed_punch_status === $option)>{{ __('health.hr_status_' . $option) }}</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_missed_punch_hint') }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_weekly_off') }}</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach($dayNames as $iso => $label)
                            <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-xs font-bold cursor-pointer">
                                <input type="checkbox" name="weekly_off_days[]" value="{{ $iso }}" @checked(in_array($iso, $offDays, true)) @disabled(!$canManage)
                                       class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_weekly_off_policy_hint') }}</p>
                </div>
            </div>

            {{-- ── Overtime ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                <h2 class="text-base font-black">{{ __('health.hr_policy_overtime') }}</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <label class="inline-flex items-center gap-2 text-sm font-bold">
                        <input type="hidden" name="overtime_enabled" value="0">
                        <input type="checkbox" name="overtime_enabled" value="1" @checked($policy->overtime_enabled) @disabled(!$canManage)
                               class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                        {{ __('health.hr_overtime_enabled') }}
                    </label>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_min_overtime') }}</label>
                        <input type="number" name="min_overtime_minutes" min="0" max="480" required
                               value="{{ (int) $policy->min_overtime_minutes }}" @disabled(!$canManage)
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.hr_min_overtime_hint') }}</p>
                    </div>
                </div>
            </div>

            {{-- ── How a punch may arrive ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                <h2 class="text-base font-black">{{ __('health.hr_policy_capture') }}</h2>

                <div class="grid sm:grid-cols-2 gap-3">
                    @php
                        $switches = [
                            'biometric_enabled'      => [__('health.hr_capture_biometric'), __('health.hr_capture_biometric_hint')],
                            'web_checkin_enabled'    => [__('health.hr_capture_web'), __('health.hr_capture_web_hint')],
                            'mobile_checkin_enabled' => [__('health.hr_capture_mobile'), __('health.hr_capture_mobile_hint')],
                            'session_punch_enabled'  => [__('health.hr_capture_session'), __('health.hr_capture_session_hint')],
                        ];
                    @endphp
                    @foreach($switches as $field => $copy)
                        <label class="flex items-start gap-2.5 p-3 rounded-xl border border-gray-200 dark:border-gray-700 cursor-pointer">
                            <input type="hidden" name="{{ $field }}" value="0">
                            <input type="checkbox" name="{{ $field }}" value="1" @checked($policy->{$field}) @disabled(!$canManage)
                                   class="mt-0.5 rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            <span>
                                <span class="block text-sm font-bold">{{ $copy[0] }}</span>
                                <span class="block text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ $copy[1] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="grid sm:grid-cols-3 gap-4">
                    <label class="inline-flex items-center gap-2 text-sm font-bold">
                        <input type="hidden" name="geo_required" value="0">
                        <input type="checkbox" name="geo_required" value="1" @checked($policy->geo_required) @disabled(!$canManage)
                               class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                        {{ __('health.hr_geo_required') }}
                    </label>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_geo_radius') }}</label>
                        <input type="number" name="geo_radius_m" min="20" max="20000"
                               value="{{ (int) $policy->geo_radius_m }}" @disabled(!$canManage)
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-bold">
                        <input type="hidden" name="cross_branch_allowed" value="0">
                        <input type="checkbox" name="cross_branch_allowed" value="1" @checked($policy->cross_branch_allowed) @disabled(!$canManage)
                               class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                        {{ __('health.hr_cross_branch_allowed') }}
                    </label>
                </div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.hr_cross_branch_hint') }}</p>

                {{-- The geofence has to measure from somewhere. Without a site
                     "location required" only checks that the phone SENT a
                     coordinate, which any phone can do. --}}
                @if($geoReady)
                    <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                        <div>
                            <p class="text-sm font-black">{{ __('health.hr_sites_title') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_sites_hint') }}</p>
                        </div>

                        <div class="grid sm:grid-cols-3 gap-3 items-end">
                            <div class="sm:col-span-1">
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_site_org') }}</label>
                                <input type="text" readonly value="{{ $company->company_name ?? '' }}"
                                       class="w-full rounded-xl border-gray-200 dark:border-gray-700 dark:bg-gray-900 text-sm bg-gray-50 dark:bg-gray-800 text-gray-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_geo_latitude') }}</label>
                                <input type="text" inputmode="decimal" name="geo_latitude" value="{{ old('geo_latitude', $policy->geo_latitude) }}" @disabled(!$canManage)
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_geo_longitude') }}</label>
                                <input type="text" inputmode="decimal" name="geo_longitude" value="{{ old('geo_longitude', $policy->geo_longitude) }}" @disabled(!$canManage)
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                        </div>

                        @foreach($branches as $branch)
                            <div class="grid sm:grid-cols-4 gap-3 items-end">
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ $branch->name }}</label>
                                    <input type="text" readonly value="{{ $branch->city ?? '' }}"
                                           class="w-full rounded-xl border-gray-200 dark:border-gray-700 text-sm bg-gray-50 dark:bg-gray-800 text-gray-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_geo_latitude') }}</label>
                                    <input type="text" inputmode="decimal" name="sites[{{ $branch->id }}][latitude]" value="{{ $branch->latitude }}" @disabled(!$canManage)
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_geo_longitude') }}</label>
                                    <input type="text" inputmode="decimal" name="sites[{{ $branch->id }}][longitude]" value="{{ $branch->longitude }}" @disabled(!$canManage)
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_site_radius_override') }}</label>
                                    <input type="number" min="25" max="5000" name="sites[{{ $branch->id }}][geo_radius_m]" value="{{ $branch->geo_radius_m }}" @disabled(!$canManage)
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @if($canManage)
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                    {{ __('health.hr_save') }}
                </button>
            @endif
        </form>
    </div>
</x-health-layout>
