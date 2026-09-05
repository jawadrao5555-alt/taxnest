{{--
    Biometric devices — the healthcare view of the SHARED clock integration.

    A hospital that already owns a ZKTeco unit does not buy a second one, and we
    do not maintain a second ADMS implementation. The device list, the PIN map
    and the push endpoint are the same plumbing the retail panels use; what this
    screen adds is the mirror onto the healthcare attendance timeline.
--}}
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_devices_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_devices_subtitle') }}</p>
            </div>
            @if($canManage)
                <form method="POST" action="{{ route('health.hr.devices.sync') }}" class="flex items-end gap-2">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_sync_days') }}</label>
                        <input type="number" name="days" value="14" min="1" max="90"
                               class="w-24 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.hr_sync_now') }}
                    </button>
                </form>
            @endif
        </div>

        @unless($policy->biometric_enabled)
            {{-- The switch is off, so mirrored punches will not be counted. Say
                 so here rather than letting HR wonder why the clock is silent. --}}
            <div class="rounded-2xl bg-amber-50 dark:bg-amber-900/25 border border-amber-300 dark:border-amber-700 p-4">
                <p class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ __('health.hr_biometric_off_notice') }}</p>
                <a href="{{ route('health.hr.policy') }}" class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.hr_open_policy') }}</a>
            </div>
        @endunless

        {{-- ── Devices ── --}}
        @if($canManage)
            <form method="POST" action="{{ route('health.hr.devices.store') }}"
                  class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 grid sm:grid-cols-3 gap-4 items-end">
                @csrf
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_device_label') }}</label>
                    <input type="text" name="label" required maxlength="120" placeholder="{{ __('health.hr_device_label_hint') }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_device_sn') }}</label>
                    <input type="text" name="device_sn" maxlength="64"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                </div>
                <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                    {{ __('health.hr_device_add') }}
                </button>
            </form>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($devices->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_device_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($devices as $device)
                        <div class="px-5 py-4 space-y-2">
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="flex-1 min-w-[200px]">
                                    <p class="text-sm font-black">{{ $device->label }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ $device->device_sn ? __('health.hr_device_sn') . ': ' . $device->device_sn : __('health.hr_device_no_sn') }}
                                        @if($device->last_push_at)
                                            &middot; {{ __('health.hr_device_last_push', ['when' => \Illuminate\Support\Carbon::parse($device->last_push_at)->diffForHumans()]) }}
                                        @else
                                            &middot; {{ __('health.hr_device_never_pushed') }}
                                        @endif
                                    </p>
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide
                                    {{ $device->is_active ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                                    {{ $device->is_active ? __('health.dept_active') : __('health.dept_inactive') }}
                                </span>
                                @if($canManage)
                                    <form method="POST" action="{{ route('health.hr.devices.toggle', $device->id) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                            {{ $device->is_active ? __('health.hr_deactivate') : __('health.hr_activate') }}
                                        </button>
                                    </form>
                                @endif
                            </div>

                            @if($canManage && $device->push_token)
                                {{-- The clock is configured with this URL once, on the
                                     device itself. It is scoped to this token and this
                                     serial, which is what keeps one hospital's clock from
                                     writing into another's timeline. --}}
                                <p class="text-[11px] font-mono break-all text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900 rounded-lg px-3 py-2">
                                    {{ url('/bio-sync/' . $device->push_token . '/iclock/cdata') }}
                                </p>
                            @endif

                            @php $deviceMaps = $maps[$device->id] ?? collect(); @endphp
                            @if(count($deviceMaps))
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('health.hr_mapped_pins', ['count' => count($deviceMaps)]) }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── PINs nobody owns yet ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-3">
            <div>
                <h2 class="text-base font-black">{{ __('health.hr_unmapped_title') }}</h2>
                {{-- Until a PIN is attached to a person, those punches sit in the
                     evidence table counting for nobody. --}}
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_unmapped_hint') }}</p>
            </div>

            @if(count($unmappedPins) === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_unmapped_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($unmappedPins as $row)
                        <form method="POST" action="{{ route('health.hr.devices.map') }}" class="py-3 flex flex-wrap items-center gap-3">
                            @csrf
                            <input type="hidden" name="device_pin" value="{{ $row->device_pin }}">
                            <div class="flex-1 min-w-[160px]">
                                <p class="text-sm font-black tabular-nums">{{ __('health.hr_pin') }} {{ $row->device_pin }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ __('health.hr_pin_hits', ['count' => $row->hits]) }}
                                    @if($row->last_seen)
                                        &middot; {{ \Illuminate\Support\Carbon::parse($row->last_seen)->translatedFormat('d M Y H:i') }}
                                    @endif
                                </p>
                            </div>
                            <select name="user_id" required @disabled(!$canManage)
                                    class="min-w-[200px] rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">{{ __('health.hr_pick_staff') }}</option>
                                @foreach($staff as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                @endforeach
                            </select>
                            @if($canManage)
                                <button type="submit" class="px-4 py-2 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                                    {{ __('health.hr_map') }}
                                </button>
                            @endif
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
