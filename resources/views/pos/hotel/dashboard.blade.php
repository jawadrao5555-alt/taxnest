<x-pos-layout>
@php
    $roomStateCounts = $roomStateCounts ?? array_count_values(array_column($roomCards ?? [], 'state'));
    $totalRooms = count($roomCards ?? []);
    $occupiedRooms = $roomStateCounts['occupied'] ?? 0;
    $availableRooms = $roomStateCounts['vacant'] ?? 0;
    $occupancyRate = $totalRooms ? round($occupiedRooms * 100 / $totalRooms) : 0;
    $deskLinks = [
        [route('pos.hotel.dashboard'), __('pos.nav_hotel_front_desk'), 'dashboard'],
        [route('pos.hotel.stays.create', ['walk_in' => 1]), __('pos.hotel_action_walkin'), 'checkin'],
        [route('pos.hotel.guests'), __('pos.nav_hotel_guests'), 'guests'],
        [route('pos.hotel.rooms'), __('pos.nav_hotel_rooms'), 'rooms'],
        [route('pos.hotel.stays.index', ['status' => 'checked_in']), __('pos.hotel_in_house'), 'stays'],
        [route('pos.hotel.stays.index', ['status' => 'reserved']), __('pos.nav_hotel_reservations'), 'reservations'],
        [route('pos.hotel.stays.index', ['status' => 'checked_in']), __('pos.hotel_check_out_btn'), 'checkout'],
        [route('pos.hotel.folios'), __('pos.nav_hotel_folios'), 'folios'],
        [route('pos.hotel.housekeeping'), __('pos.nav_hotel_housekeeping'), 'housekeeping'],
        [route('pos.hotel.reports'), __('pos.nav_hotel_reports'), 'reports'],
    ];
@endphp
<div class="tn-page tn-hotel-dashboard max-w-[90rem] mx-auto px-4 sm:px-6 lg:px-8 py-5" data-hotel-reception="1">
    @include('pos.partials.back-link')
    <div class="grid grid-cols-1 lg:grid-cols-[14rem_minmax(0,1fr)] gap-5 items-start">
        <aside class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-gray-900 p-4 lg:sticky lg:top-5" aria-label="{{ __('pos.nav_hotel_front_desk') }}" data-hotel-desk-menu="1">
            <p class="text-xs font-bold uppercase tracking-widest text-teal-700 dark:text-teal-300">{{ __('pos.nav_hotel_front_desk') }}</p>
            <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ auth('pos')->user()?->company?->name }}</p>
            <nav class="mt-5 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-1 gap-1.5">
                @foreach($deskLinks as [$url, $label, $key])
                <a href="{{ $url }}" data-hotel-desk-link="{{ $key }}" @if($key === 'dashboard') aria-current="page" @endif class="rounded-lg px-3 py-2.5 text-sm font-medium {{ $key === 'dashboard' ? 'bg-teal-700 text-white' : 'text-slate-700 hover:bg-teal-50 dark:text-slate-200 dark:hover:bg-slate-800' }}">{{ $label }}</a>
                @endforeach
            </nav>
            @if(\App\Services\HotelShell::restaurantOutletOn(auth('pos')->user()?->company)
                && \App\Services\HotelShell::canOpenRestaurantOutlet(auth('pos')->user(), auth('pos')->user()?->company))
            <a href="{{ route('pos.hotel.restaurant-outlet') }}" data-hotel-restaurant-outlet="1" class="block mt-4 rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('pos.nav_hotel_restaurant_outlet') }}</a>
            @endif
            <details class="mt-5 border-t border-slate-200 dark:border-slate-700 pt-3 text-xs text-slate-600 dark:text-slate-300">
                <summary class="cursor-pointer font-semibold">{{ __('pos.hotel_action_booking') }} · {{ __('pos.hotel_action_payment') }}</summary>
                <div class="mt-3">@include('pos.hotel._nav', ['showHotelPrimaryActions' => true])</div>
            </details>
        </aside>

        <main class="min-w-0">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
                <div>
                    <p class="text-xs uppercase font-bold tracking-widest text-teal-700 dark:text-teal-300">{{ __('pos.nav_hotel_front_desk') }}</p>
                    <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">{{ __('pos.hotel_front_desk') }}</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">{{ __('pos.hotel_front_desk_hint_v2') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('pos.hotel.dashboard') }}" class="rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm font-semibold text-slate-700 dark:text-white">{{ __('pos.hotel_all_rooms') }}</a>
                    <a href="{{ route('pos.hotel.stays.create') }}" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800">{{ __('pos.hotel_action_booking') }}</a>
                </div>
            </div>
            @if(session('success'))<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
            @if(session('error'))<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>@endif

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3" data-hotel-reception-summary="1">
                @foreach([
                    [__('pos.hotel_stat_rooms'), $totalRooms, 'border-slate-200'],
                    [__('pos.hotel_stat_in_house'), $occupiedRooms, 'border-rose-200'],
                    [__('pos.hotel_stat_available'), $availableRooms, 'border-emerald-200'],
                    [__('pos.hotel_arrivals_today'), $occupancy['arrivals'] ?? 0, 'border-sky-200'],
                    [__('pos.hotel_departures_today'), $occupancy['departures'] ?? 0, 'border-amber-200'],
                ] as [$label, $value, $border])
                <div class="rounded-2xl border {{ $border }} bg-white dark:bg-gray-900 dark:border-slate-700 p-4 shadow-sm">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-300">{{ $label }}</p>
                    <p class="mt-2 text-3xl font-extrabold text-slate-900 dark:text-white">{{ $value }}</p>
                </div>
                @endforeach
            </div>

            <div class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-gray-900 p-4" data-hotel-occupancy-rate="{{ $occupancyRate }}">
                <div class="flex justify-between text-sm font-semibold text-slate-700 dark:text-slate-200"><span>{{ __('pos.hotel_occupancy') }}</span><span>{{ $occupancyRate }}%</span></div>
                <div class="mt-2 h-2.5 rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden" role="progressbar" aria-label="{{ __('pos.hotel_occupancy') }}" aria-valuenow="{{ $occupancyRate }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-teal-600" style="width: {{ $occupancyRate }}%"></div></div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-3" data-hotel-operational-counts="1">
                @foreach([
                    ['hotel_stat_reserved', $roomStateCounts['reserved'] ?? 0],
                    ['hotel_stat_dirty', $roomStateCounts['dirty'] ?? 0],
                    ['hotel_stat_oos', $roomStateCounts['oos'] ?? 0],
                    ['hotel_stat_due', $occupancy['pending_due_count'] ?? 0],
                ] as [$label, $value])
                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-gray-900 px-3 py-2">
                    <p class="text-xs font-semibold text-slate-500">{{ __('pos.'.$label) }}</p>
                    <p class="text-xl font-extrabold text-slate-900 dark:text-white">{{ $value }}</p>
                </div>
                @endforeach
            </div>

            @if($totalRooms === 0)
            <div class="mt-5 rounded-2xl border border-dashed border-teal-300 bg-teal-50 dark:bg-teal-950/20 p-6 text-center" data-hotel-empty-rooms="1">
                <p class="font-semibold text-slate-900 dark:text-white">{{ __('pos.hotel_no_rooms') }}</p>
                <a href="{{ route('pos.hotel.rooms') }}" class="inline-block mt-3 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">{{ __('pos.hotel_rooms') }}</a>
            </div>
            @endif
            @include('pos.hotel._room-board', ['roomCards' => $roomCards, 'filter' => '', 'filterBase' => route('pos.hotel.rooms'), 'showReceptionActions' => true])

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-6" data-hotel-desk-queues="1">
                @foreach([
                    [__('pos.hotel_arrivals_today'), $arrivals, 'arrivals'],
                    [__('pos.hotel_departures_today'), $departures, 'departures'],
                    [__('pos.hotel_pending_balances'), $pending, 'pending'],
                ] as [$title, $stays, $queue])
                <section class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-gray-900 p-4" data-hotel-queue="{{ $queue }}">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">{{ $title }} <span class="text-slate-500">({{ $stays->count() }})</span></h2>
                    @foreach($stays as $stay)
                    <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="flex justify-between gap-2 border-b border-slate-100 dark:border-slate-700 py-2.5 last:border-0 text-sm hover:text-teal-700">
                        <span><strong>{{ $stay->guest_name }}</strong><span class="block text-xs text-slate-500">{{ $stay->stay_number }} · {{ __('pos.hotel_room') }} {{ $stay->room?->room_number }}</span></span>
                        @if($queue === 'pending')<span class="font-bold text-amber-700">Rs {{ number_format($dues[$stay->id] ?? 0) }}</span>@endif
                    </a>
                    @endforeach
                </section>
                @endforeach
                @php $money = $money ?? ['collections' => 0, 'charges' => 0, 'invoiced' => 0]; @endphp
                <section class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-gray-900 p-4">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">{{ __('pos.nav_hotel_folios') }}</h2>
                    <div class="grid grid-cols-3 gap-2 mt-3">
                        @foreach([['hotel_stat_collections', 'collections'], ['hotel_stat_charges_today', 'charges'], ['hotel_stat_invoiced_today', 'invoiced']] as [$label, $key])
                        <div><p class="text-xs text-slate-500">{{ __('pos.'.$label) }}</p><p class="mt-1 font-bold text-slate-900 dark:text-white">Rs {{ number_format($money[$key] ?? 0) }}</p></div>
                        @endforeach
                    </div>
                    <a href="{{ route('pos.hotel.folios') }}" class="inline-block mt-4 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('pos.nav_hotel_folios') }} →</a>
                </section>
            </div>
            <p class="text-xs text-slate-500 mt-4">{{ __('pos.hotel_charging_rule_note') }}</p>
        </main>
    </div>
</div>
</x-pos-layout>
