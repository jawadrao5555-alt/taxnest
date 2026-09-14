<x-pos-layout>
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.hotel.dashboard') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-teal-700 mb-3">{{ __('pos.hotel_back_desk') }}</a>
    <div class="flex items-center justify-between mb-5">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.hotel_rooms') }}</h1>
    </div>
    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 text-emerald-700 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">{{ session('error') }}</div>
    @endif

    @unless(auth('pos')->user()?->posCashierBlocked())
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <h3 class="text-sm font-semibold mb-4">{{ __('pos.hotel_add_room') }}</h3>
        <form method="POST" action="{{ route('pos.hotel.rooms.store') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            @csrf
            <input name="room_number" required placeholder="{{ __('pos.hotel_room_number') }}" class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
            <input name="room_type" placeholder="{{ __('pos.hotel_room_type') }}" class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
            <input type="number" name="capacity" value="2" min="1" required class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
            <input type="number" name="rate_amount" value="0" min="0" step="0.01" required class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
            <select name="rate_unit" class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                @include('partials.pos-uom-options', ['uomGroups' => $uomGroups, 'uomSelected' => 'NGT'])
            </select>
            <select name="service_state" class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                <option value="in_service">{{ __('pos.hotel_in_service') }}</option>
                <option value="out_of_service">{{ __('pos.hotel_oos') }}</option>
            </select>
            <button class="px-4 py-2 bg-teal-700 hover:bg-teal-800 text-white text-sm rounded-lg font-semibold">{{ __('pos.hotel_save_room') }}</button>
        </form>
        <p class="text-xs text-gray-500 mt-2">{{ __('pos.hotel_charging_rule_note') }}</p>
    </div>
    @endunless

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 uppercase">
                    <th class="px-4 py-3">{{ __('pos.hotel_room') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_room_type') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_capacity') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_rate') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_occupancy') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_service') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_hk') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rooms as $room)
                @php $open = $openStayByRoom[$room->id] ?? null; @endphp
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3 font-semibold">{{ $room->room_number }}</td>
                    <td class="px-4 py-3">{{ $room->room_type }}</td>
                    <td class="px-4 py-3">{{ $room->capacity }}</td>
                    <td class="px-4 py-3">Rs {{ number_format($room->rate_amount) }} / {{ \App\Services\PosUnitCatalog::label($room->rate_unit) }}</td>
                    <td class="px-4 py-3">
                        @if(!empty($canFrontDesk) && $open)
                        <a class="text-teal-800 font-semibold" href="{{ route('pos.hotel.stays.show', $open->id) }}">{{ $open->stay_number }}</a>
                        @elseif($open)
                        {{ $open->stay_number }}
                        @else
                        <span class="text-gray-500">{{ __('pos.hotel_vacant') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if(!empty($canManageRooms))
                        <form method="POST" action="{{ route('pos.hotel.rooms.service', $room->id) }}">
                            @csrf
                            <select name="service_state" class="text-xs rounded border-gray-300 dark:bg-gray-800" onchange="this.form.submit()">
                                <option value="in_service" @selected($room->service_state==='in_service')>{{ __('pos.hotel_in_service') }}</option>
                                <option value="out_of_service" @selected($room->service_state==='out_of_service')>{{ __('pos.hotel_oos') }}</option>
                            </select>
                        </form>
                        @else
                        {{ $room->service_state === 'out_of_service' ? __('pos.hotel_oos') : __('pos.hotel_in_service') }}
                        @endunless
                    </td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('pos.hotel.rooms.housekeeping', $room->id) }}" class="flex gap-1">
                            @csrf
                            <select name="housekeeping" class="text-xs rounded border-gray-300 dark:bg-gray-800" onchange="this.form.submit()">
                                <option value="clean" @selected($room->housekeeping==='clean')>{{ __('pos.hotel_hk_clean') }}</option>
                                <option value="dirty" @selected($room->housekeeping==='dirty')>{{ __('pos.hotel_hk_dirty') }}</option>
                                <option value="inspected" @selected($room->housekeeping==='inspected')>{{ __('pos.hotel_hk_inspected') }}</option>
                            </select>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-4 py-6 text-gray-500">{{ __('pos.hotel_no_rooms') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-pos-layout>
