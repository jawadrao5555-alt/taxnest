@php
    $toneMap = [
        'emerald' => 'border-emerald-300 bg-emerald-50 dark:bg-emerald-950/30',
        'rose' => 'border-rose-300 bg-rose-50 dark:bg-rose-950/30',
        'indigo' => 'border-indigo-300 bg-indigo-50 dark:bg-indigo-950/30',
        'amber' => 'border-amber-300 bg-amber-50 dark:bg-amber-950/30',
        'slate' => 'border-slate-400 bg-slate-100 dark:bg-slate-900',
    ];
    $filter = $filter ?? '';
    $filterBase = $filterBase ?? request()->url();
    $showReceptionActions = $showReceptionActions ?? false;
    $visible = collect($roomCards ?? [])->filter(function ($card) use ($filter) {
        return $filter === '' || ($card['state'] ?? '') === $filter;
    });
    $roomGroups = $showReceptionActions && $filter === ''
        ? ['vacant' => __('pos.hotel_available_rooms'), 'occupied' => __('pos.hotel_in_house'), 'reserved' => __('pos.hotel_status_reserved'), 'dirty' => __('pos.hotel_dirty_rooms'), 'oos' => __('pos.hotel_oos')]
        : ['' => null];
@endphp
@unless($showReceptionActions)
<div class="flex flex-wrap gap-2 mb-3 text-xs" data-hotel-room-filters="1">
    @foreach(['' => __('pos.hotel_all_rooms'), 'vacant' => __('pos.hotel_vacant'), 'occupied' => __('pos.hotel_occupied'), 'reserved' => __('pos.hotel_status_reserved'), 'dirty' => __('pos.hotel_hk_dirty'), 'oos' => __('pos.hotel_oos')] as $key => $label)
    <a href="{{ $filterBase }}{{ $key === '' ? '' : '?filter='.$key }}" class="px-2.5 py-1 rounded-full font-semibold {{ $filter === $key ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-900 border border-gray-200 text-gray-700 dark:text-gray-200' }}">{{ $label }}</a>
    @endforeach
</div>
@endunless
<div data-hotel-room-board="1">
@if(!$showReceptionActions || $visible->isNotEmpty())
@foreach($roomGroups as $state => $heading)
    @php $groupCards = $state === '' ? $visible : $visible->where('state', $state); @endphp
    @if($groupCards->isNotEmpty() || $state === '')
    @if($heading)<h2 class="flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wide mt-7 mb-3"><span class="h-2 w-2 rounded-full {{ $state === 'vacant' ? 'bg-emerald-500' : ($state === 'occupied' ? 'bg-rose-500' : 'bg-amber-500') }}"></span>{{ $heading }} <span class="text-gray-500">({{ $groupCards->count() }})</span></h2>@endif
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-5" data-hotel-room-group="{{ $state }}">
    @forelse($groupCards as $card)
    @php $room = $card['room']; $stay = $card['stay'] ?? null; @endphp
    <div class="rounded-2xl border p-4 {{ $toneMap[$card['tone']] ?? 'border-gray-200 bg-white' }} {{ $showReceptionActions ? 'shadow-sm min-h-[12rem] flex flex-col' : '' }}" data-hotel-room-state="{{ $card['state'] }}">
        <div class="flex items-start justify-between gap-2">
            <p class="text-lg font-extrabold text-gray-900 dark:text-white">{{ __('pos.hotel_room') }} {{ $room->room_number }}</p>
            <span class="rounded-full bg-white/80 dark:bg-gray-800 px-2 py-0.5 text-[10px] font-bold text-slate-700 dark:text-slate-200">{{ __('pos.hotel_board_'.$card['state']) }}</span>
        </div>
        <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">{{ $room->room_type }} · {{ $room->capacity }}</p>
        <p class="text-xs text-gray-700 dark:text-gray-200 mt-1">Rs {{ number_format($room->rate_amount) }}/{{ \App\Services\PosUnitCatalog::label($room->rate_unit) }}</p>
        @if($stay && \App\Services\HotelAccessService::canFrontDesk(auth('pos')->user()))
        <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="block mt-1 text-xs font-semibold text-teal-800 truncate">{{ $stay->guest_name }}</a>
        @elseif($stay)
        <p class="mt-1 text-xs font-semibold truncate">{{ $stay->guest_name }}</p>
        @endif
        @if($stay)
        <p class="mt-1 text-[11px] text-gray-600 dark:text-gray-300">{{ __('pos.hotel_check_in') }}: {{ $stay->check_in_date?->format('d M Y') }}</p>
        <p class="text-[11px] text-gray-600 dark:text-gray-300">{{ __('pos.hotel_check_out') }}: {{ $stay->check_out_date?->format('d M Y') }}</p>
        @endif
        @if($showReceptionActions && \App\Services\HotelAccessService::canFrontDesk(auth('pos')->user()))
            @if($card['state'] === 'vacant')
                <a data-hotel-room-check-in="{{ $room->id }}" href="{{ route('pos.hotel.stays.create', ['walk_in' => 1, 'room_id' => $room->id]) }}" class="block mt-auto rounded-lg bg-teal-700 px-3 py-2 text-center text-xs font-semibold text-white hover:bg-teal-800">{{ __('pos.hotel_check_in_btn') }}</a>
            @elseif($stay)
                <a data-hotel-room-stay="{{ $room->id }}" href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="block mt-auto rounded-lg {{ $card['state'] === 'occupied' ? 'bg-rose-700 hover:bg-rose-800' : 'bg-teal-700 hover:bg-teal-800' }} px-3 py-2 text-center text-xs font-semibold text-white">{{ __('pos.hotel_stays') }}</a>
            @endif
        @endif
        @if($room->housekeeping === 'dirty' && ($card['state'] ?? '') !== 'dirty')
        <p class="text-[10px] text-amber-800 mt-1">{{ __('pos.hotel_hk_dirty') }}</p>
        @endif
        @if(!$showReceptionActions && \App\Services\HotelAccessService::canHousekeeping(auth('pos')->user()))
        <form method="POST" action="{{ route('pos.hotel.rooms.housekeeping', $room->id) }}" class="mt-2">
            @csrf
            <select name="housekeeping" onchange="this.form.submit()" class="w-full rounded-md border-gray-300 dark:bg-gray-800 text-[11px]">
                <option value="clean" @selected($room->housekeeping==='clean')>{{ __('pos.hotel_hk_clean') }}</option>
                <option value="dirty" @selected($room->housekeeping==='dirty')>{{ __('pos.hotel_hk_dirty') }}</option>
                <option value="inspected" @selected($room->housekeeping==='inspected')>{{ __('pos.hotel_hk_inspected') }}</option>
            </select>
        </form>
        @endif
    </div>
    @empty
    <p class="col-span-full text-sm text-gray-500">{{ __('pos.hotel_no_rooms') }}</p>
    @endforelse
    </div>
    @endif
@endforeach
@endif
</div>
