<x-pos-layout>
<div class="tn-page tn-hotel-dashboard max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')
    @include('pos.hotel._nav', ['showHotelPrimaryActions' => true])
    <div class="tn-page-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div>
            <p class="tn-page-kicker">Hotel operations</p>
            <h1 class="tn-page-title text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.hotel_front_desk') }}</h1>
            <p class="tn-page-subtitle text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.hotel_front_desk_hint_v2') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('pos.hotel.rooms') }}" class="tn-action-secondary px-4 py-2 rounded-lg bg-teal-700 hover:bg-teal-800 text-white text-sm font-semibold">{{ __('pos.hotel_rooms') }}</a>
            <a href="{{ route('pos.hotel.stays.index') }}" class="tn-action-secondary px-4 py-2 rounded-lg bg-teal-700 hover:bg-teal-800 text-white text-sm font-semibold">{{ __('pos.hotel_stays') }}</a>
            <a href="{{ route('pos.hotel.stays.create') }}" class="tn-action-primary px-4 py-2 rounded-lg bg-teal-700 hover:bg-teal-800 text-white text-sm font-semibold">{{ __('pos.hotel_new_stay') }}</a>
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif

    <div class="tn-hotel-occupancy">
        @include('pos.hotel._occupancy-strip', ['occupancy' => $occupancy])
    </div>

    @php $money = $money ?? ['collections' => 0, 'charges' => 0, 'invoiced' => 0]; @endphp
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-3 mb-5">
        <div class="tn-stat-card rounded-xl bg-white dark:bg-gray-900 border border-teal-100 p-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ __('pos.hotel_stat_collections') }}</p>
            <p class="text-xl font-extrabold mt-1">Rs {{ number_format($money['collections'] ?? 0) }}</p>
        </div>
        <div class="tn-stat-card rounded-xl bg-white dark:bg-gray-900 border border-teal-100 p-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ __('pos.hotel_stat_charges_today') }}</p>
            <p class="text-xl font-extrabold mt-1">Rs {{ number_format($money['charges'] ?? 0) }}</p>
        </div>
        <div class="tn-stat-card rounded-xl bg-white dark:bg-gray-900 border border-teal-100 p-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ __('pos.hotel_stat_invoiced_today') }}</p>
            <p class="text-xl font-extrabold mt-1">Rs {{ number_format($money['invoiced'] ?? 0) }}</p>
        </div>
    </div>

    @include('pos.hotel._room-board', ['roomCards' => $roomCards ?? [], 'filter' => '', 'filterBase' => route('pos.hotel.rooms')])

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">{{ __('pos.hotel_arrivals_today') }}</h2>
            @forelse($arrivals as $stay)
            <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $stay->guest_name }}</p>
                    <p class="text-xs text-gray-500">{{ $stay->stay_number }} · {{ __('pos.hotel_room') }} {{ $stay->room?->room_number }}</p>
                </div>
                <span class="text-xs font-medium text-teal-800 dark:text-teal-300">{{ \App\Services\HotelShell::statusLabel($stay->status) }}</span>
            </a>
            @empty
            <p class="text-sm text-gray-500">{{ __('pos.hotel_no_arrivals') }}</p>
            @endforelse
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">{{ __('pos.hotel_departures_today') }}</h2>
            @forelse($departures as $stay)
            <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $stay->guest_name }}</p>
                    <p class="text-xs text-gray-500">{{ $stay->stay_number }} · {{ __('pos.hotel_room') }} {{ $stay->room?->room_number }}</p>
                </div>
            </a>
            @empty
            <p class="text-sm text-gray-500">{{ __('pos.hotel_no_departures') }}</p>
            @endforelse
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">{{ __('pos.hotel_in_house') }}</h2>
            @forelse($inHouse as $stay)
            <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $stay->guest_name }}</p>
                    <p class="text-xs text-gray-500">{{ $stay->stay_number }} · {{ __('pos.hotel_room') }} {{ $stay->room?->room_number }} · {{ __('pos.hotel_until') }} {{ $stay->check_out_date->format('d M') }}</p>
                </div>
                @if(($dues[$stay->id] ?? 0) > 0)
                <span class="text-xs font-semibold text-amber-700">Rs {{ number_format($dues[$stay->id], 0) }}</span>
                @endif
            </a>
            @empty
            <p class="text-sm text-gray-500">{{ __('pos.hotel_no_in_house') }}</p>
            @endforelse
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">{{ __('pos.hotel_pending_balances') }}</h2>
            @forelse($pending as $stay)
            <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $stay->guest_name }}</p>
                    <p class="text-xs text-gray-500">{{ $stay->stay_number }} · {{ __('pos.hotel_room') }} {{ $stay->room?->room_number }}</p>
                </div>
                <span class="text-sm font-bold text-amber-800">Rs {{ number_format($dues[$stay->id] ?? 0) }}</span>
            </a>
            @empty
            <p class="text-sm text-gray-500">{{ __('pos.hotel_no_pending') }}</p>
            @endforelse
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">{{ __('pos.hotel_available_rooms') }}</h2>
            @forelse($available as $room)
            <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $room->room_number }} · {{ $room->room_type }}</p>
                <span class="text-xs text-gray-500">Rs {{ number_format($room->rate_amount) }}/{{ \App\Services\PosUnitCatalog::label($room->rate_unit) }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-500">{{ __('pos.hotel_no_available') }}</p>
            @endforelse
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wide mb-3">{{ __('pos.hotel_dirty_rooms') }}</h2>
            @forelse($dirty as $room)
            <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $room->room_number }} · {{ $room->room_type }}</p>
                <span class="text-xs text-gray-500">{{ __('pos.hotel_hk_dirty') }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-500">{{ __('pos.hotel_no_dirty') }}</p>
            @endforelse
        </div>
    </div>
    <p class="text-xs text-gray-500 mt-4">{{ __('pos.hotel_charging_rule_note') }}</p>
</div>
</x-pos-layout>
