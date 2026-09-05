{{--
    HR hub — the duty desk landing.

    Shows the floor as it stands right now, then the two queues that actually
    need a human (leave awaiting a decision, corrections awaiting a decision),
    then the doors to every other HR screen. Cards for screens the viewer cannot
    reach are not rendered: navigation here lists what this person can use, not
    what the product can do.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ __('health.hr_subtitle') }} &middot; {{ $today->translatedFormat('D, d M Y') }}
                </p>
            </div>
            @if($canManage)
                <form method="POST" action="{{ route('health.hr.devices.sync') }}">
                    @csrf
                    <input type="hidden" name="days" value="14">
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.hr_sync_now') }}
                    </button>
                </form>
            @endif
        </div>

        {{-- ── Today at a glance ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @php
                $tiles = [
                    ['label' => __('health.hr_tile_staff'),   'value' => $counts['active'],  'tone' => 'bg-white dark:bg-gray-800'],
                    ['label' => __('health.hr_tile_on_duty'), 'value' => $counts['on_duty'], 'tone' => 'bg-teal-50 dark:bg-teal-900/30'],
                    ['label' => __('health.hr_tile_present'), 'value' => $counts['present'], 'tone' => 'bg-emerald-50 dark:bg-emerald-900/30'],
                    ['label' => __('health.hr_tile_absent'),  'value' => $counts['absent'],  'tone' => 'bg-rose-50 dark:bg-rose-900/30'],
                    ['label' => __('health.hr_tile_leave'),   'value' => $counts['leave'],   'tone' => 'bg-amber-50 dark:bg-amber-900/30'],
                    ['label' => __('health.hr_tile_missed'),  'value' => $counts['missed_punch'], 'tone' => 'bg-orange-50 dark:bg-orange-900/30'],
                ];
            @endphp
            @foreach($tiles as $tile)
                <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4 {{ $tile['tone'] }}">
                    <p class="text-2xl font-black tabular-nums">{{ $tile['value'] }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mt-0.5">{{ $tile['label'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- ── What is waiting for a decision ── --}}
        @if($pendingLeave > 0 || $pendingCorrections > 0 || $unmappedPins > 0)
            <div class="rounded-2xl bg-amber-50 dark:bg-amber-900/25 border border-amber-300 dark:border-amber-700 p-4 space-y-2">
                <p class="text-sm font-black text-amber-900 dark:text-amber-200">{{ __('health.hr_needs_attention') }}</p>
                <div class="flex flex-wrap gap-2 text-sm">
                    @if($pendingLeave > 0 && $canApproveLeave)
                        <a href="{{ route('health.hr.leave', ['status' => 'pending']) }}"
                           class="px-3 py-1.5 rounded-lg bg-white dark:bg-gray-800 border border-amber-300 dark:border-amber-700 font-bold hover:bg-amber-100 dark:hover:bg-amber-900/40">
                            {{ __('health.hr_pending_leave', ['count' => $pendingLeave]) }}
                        </a>
                    @endif
                    @if($pendingCorrections > 0 && $canAttendance)
                        <a href="{{ route('health.hr.corrections', ['status' => 'pending']) }}"
                           class="px-3 py-1.5 rounded-lg bg-white dark:bg-gray-800 border border-amber-300 dark:border-amber-700 font-bold hover:bg-amber-100 dark:hover:bg-amber-900/40">
                            {{ __('health.hr_pending_corrections', ['count' => $pendingCorrections]) }}
                        </a>
                    @endif
                    @if($unmappedPins > 0 && $canManage)
                        {{-- Evidence arriving with nobody attached to it. Until the
                             PIN is mapped those punches count for no one. --}}
                        <a href="{{ route('health.hr.devices') }}"
                           class="px-3 py-1.5 rounded-lg bg-white dark:bg-gray-800 border border-amber-300 dark:border-amber-700 font-bold hover:bg-amber-100 dark:hover:bg-amber-900/40">
                            {{ __('health.hr_unmapped_pins', ['count' => $unmappedPins]) }}
                        </a>
                    @endif
                </div>
            </div>
        @endif

        {{-- ── The desks ── --}}
        @php
            $cards = [];
            $cards[] = ['route' => 'health.hr.staff', 'title' => __('health.hr_card_staff'), 'body' => __('health.hr_card_staff_body'), 'show' => true];
            $cards[] = ['route' => 'health.hr.roster', 'title' => __('health.hr_card_roster'), 'body' => __('health.hr_card_roster_body', ['count' => $rosterToday]), 'show' => true];
            $cards[] = ['route' => 'health.hr.leave', 'title' => __('health.hr_card_leave'), 'body' => __('health.hr_card_leave_body'), 'show' => true];
            $cards[] = ['route' => 'health.hr.attendance', 'title' => __('health.hr_card_attendance'), 'body' => __('health.hr_card_attendance_body'), 'show' => $canAttendance];
            $cards[] = ['route' => 'health.hr.corrections', 'title' => __('health.hr_card_corrections'), 'body' => __('health.hr_card_corrections_body'), 'show' => $canAttendance];
            $cards[] = ['route' => 'health.hr.attendance.reports', 'title' => __('health.hr_card_reports'), 'body' => __('health.hr_card_reports_body'), 'show' => $canAttendance];
            $cards[] = ['route' => 'health.hr.payroll', 'title' => __('health.hr_card_payroll'), 'body' => __('health.hr_card_payroll_body'), 'show' => $canPayroll];
            $cards[] = ['route' => 'health.hr.shifts', 'title' => __('health.hr_card_shifts'), 'body' => __('health.hr_card_shifts_body', ['count' => $shiftCount]), 'show' => true];
            $cards[] = ['route' => 'health.hr.policy', 'title' => __('health.hr_card_policy'), 'body' => __('health.hr_card_policy_body'), 'show' => true];
            $cards[] = ['route' => 'health.hr.devices', 'title' => __('health.hr_card_devices'), 'body' => __('health.hr_card_devices_body'), 'show' => true];
            $cards[] = ['route' => 'health.my.attendance', 'title' => __('health.hr_card_my_duty'), 'body' => __('health.hr_card_my_duty_body'), 'show' => true];
        @endphp

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($cards as $card)
                @continue(!$card['show'] || !Route::has($card['route']))
                <a href="{{ route($card['route']) }}"
                   class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 hover:border-teal-500 dark:hover:border-teal-500 transition block">
                    <p class="text-sm font-black">{{ $card['title'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">{{ $card['body'] }}</p>
                </a>
            @endforeach
        </div>
    </div>
</x-health-layout>
