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
    $visible = collect($roomCards ?? [])->filter(function ($card) use ($filter) {
        return $filter === '' || ($card['state'] ?? '') === $filter;
    });
@endphp
<div class="flex flex-wrap gap-2 mb-3 text-xs" data-hotel-room-filters="1">
    @foreach(['' => __('pos.hotel_all_rooms'), 'vacant' => __('pos.hotel_vacant'), 'occupied' => __('pos.hotel_occupied'), 'reserved' => __('pos.hotel_status_reserved'), 'dirty' => __('pos.hotel_hk_dirty'), 'oos' => __('pos.hotel_oos')] as $key => $label)
    <a href="{{ $filterBase }}{{ $key === '' ? '' : '?filter='.$key }}" class="px-2.5 py-1 rounded-full font-semibold {{ $filter === $key ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-900 border border-gray-200 text-gray-700 dark:text-gray-200' }}">{{ $label }}</a>
    @endforeach
</div>
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-6" data-hotel-room-board="1">
    @forelse($visible as $card)
    @php $room = $card['room']; $stay = $card['stay'] ?? null; @endphp
    <div class="rounded-xl border p-3 {{ $toneMap[$card['tone']] ?? 'border-gray-200 bg-white' }}">
        <p class="text-lg font-extrabold text-gray-900 dark:text-white">{{ $room->room_number }}</p>
        <p class="text-[11px] text-gray-600 dark:text-gray-300">{{ $room->room_type }} · {{ $room->capacity }}</p>
        <p class="mt-2 text-[11px] font-bold uppercase tracking-wide">{{ __('pos.hotel_board_'.$card['state']) }}</p>
        @if($stay && \App\Services\HotelAccessService::canFrontDesk(auth('pos')->user()))
        <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="block mt-1 text-xs font-semibold text-teal-800 truncate">{{ $stay->guest_name }}</a>
        @elseif($stay)
        <p class="mt-1 text-xs font-semibold truncate">{{ $stay->guest_name }}</p>
        @endif
        @if($room->housekeeping === 'dirty' && ($card['state'] ?? '') !== 'dirty')
        <p class="text-[10px] text-amber-800 mt-1">{{ __('pos.hotel_hk_dirty') }}</p>
        @endif
        <form method="POST" action="{{ route('pos.hotel.rooms.housekeeping', $room->id) }}" class="mt-2">
            @csrf
            <select name="housekeeping" onchange="this.form.submit()" class="w-full rounded-md border-gray-300 dark:bg-gray-800 text-[11px]">
                <option value="clean" @selected($room->housekeeping==='clean')>{{ __('pos.hotel_hk_clean') }}</option>
                <option value="dirty" @selected($room->housekeeping==='dirty')>{{ __('pos.hotel_hk_dirty') }}</option>
                <option value="inspected" @selected($room->housekeeping==='inspected')>{{ __('pos.hotel_hk_inspected') }}</option>
            </select>
        </form>
    </div>
    @empty
    <p class="col-span-full text-sm text-gray-500">{{ __('pos.hotel_no_rooms') }}</p>
    @endforelse
</div>
