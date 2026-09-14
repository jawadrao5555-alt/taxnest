<div class="rounded-xl border border-teal-200 dark:border-teal-800 bg-teal-50/40 dark:bg-teal-900/10 p-3">
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2">
        @foreach([
            ['hotel_stat_rooms', $occupancy['rooms'] ?? 0],
            ['hotel_stat_in_house', $occupancy['in_house'] ?? 0],
            ['hotel_stat_arrivals', $occupancy['arrivals'] ?? 0],
            ['hotel_stat_departures', $occupancy['departures'] ?? 0],
            ['hotel_stat_reserved', $occupancy['reserved'] ?? 0],
            ['hotel_stat_available', $occupancy['available'] ?? 0],
            ['hotel_stat_oos', $occupancy['out_of_service'] ?? 0],
            ['hotel_stat_dirty', $occupancy['dirty'] ?? 0],
            ['hotel_stat_due', $occupancy['pending_due_count'] ?? 0],
        ] as $tile)
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-teal-100 dark:border-teal-900 p-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ __("pos.{$tile[0]}") }}</p>
            <p class="text-xl font-extrabold text-gray-900 dark:text-white mt-1">{{ $tile[1] }}</p>
        </div>
        @endforeach
    </div>
    @isset($hotelDeskUrl)
    <p class="mt-2"><a href="{{ $hotelDeskUrl }}" class="text-xs font-semibold text-teal-800 hover:underline">{{ __('pos.hotel_front_desk') }}</a></p>
    @endisset
</div>
