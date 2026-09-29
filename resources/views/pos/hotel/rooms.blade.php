<x-hotel-layout>
<div class="tn-page tn-hotel-page max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-5">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ !empty($housekeepingView) ? __('pos.nav_hotel_housekeeping') : __('pos.hotel_rooms') }}</h1>
    </div>
    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 text-emerald-700 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">{{ session('error') }}</div>
    @endif

    @if(!empty($housekeepingView))
    @include('pos.hotel._room-board')
    @endif

    @if(empty($housekeepingView) && !empty($canManageRooms))
    <details class="mb-6" data-hotel-admin-setup="1" @if($rooms->isEmpty()) open @endif>
        <summary class="cursor-pointer rounded-xl border border-teal-200 bg-white dark:bg-gray-900 px-5 py-3 text-sm font-semibold text-teal-800 dark:text-teal-200">{{ __('pos.hotel_add_room') }} · {{ __('pos.hotel_checkout_policy') }}</summary>
    <div class="tn-panel bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
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
        <p class="text-xs text-gray-500 mt-1">{{ __('hotel_rooms_manage.hotel_room_default_rate_hint') }}</p>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <h3 class="text-sm font-semibold mb-2">{{ __('pos.hotel_checkout_policy') }}</h3>
        <p class="text-xs text-gray-500 mb-3">{{ __('pos.hotel_checkout_policy_hint') }}</p>
        <form method="POST" action="{{ route('pos.hotel.checkout-policy') }}" class="flex flex-col sm:flex-row gap-3 sm:items-end">
            @csrf
            <select name="hotel_checkout_outstanding" class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                <option value="allow" @selected(($checkoutPolicy ?? 'allow') === 'allow')>{{ __('pos.hotel_checkout_policy_allow') }}</option>
                <option value="block" @selected(($checkoutPolicy ?? 'allow') === 'block')>{{ __('pos.hotel_checkout_policy_block') }}</option>
            </select>
            <button class="px-4 py-2 bg-teal-700 hover:bg-teal-800 text-white text-sm rounded-lg font-semibold">{{ __('pos.hotel_checkout_policy_save') }}</button>
        </form>
    </div>
    </details>
    @endif

    @if(empty($housekeepingView))
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="min-w-[48rem] w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 uppercase">
                    <th class="px-4 py-3">{{ __('pos.hotel_room') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_room_type') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_capacity') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_rate') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_occupancy') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_service') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_hk') }}</th>
                    @if(!empty($canManageRooms))<th class="px-4 py-3">{{ __('hotel_rooms_manage.hotel_room_actions') }}</th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse($activeRooms as $room)
                @php $open = $openStayByRoom[$room->id] ?? null; @endphp
                <tr id="room-{{ $room->id }}" class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3 font-semibold">{{ $room->room_number }}</td>
                    <td class="px-4 py-3">{{ $room->room_type }}</td>
                    <td class="px-4 py-3">{{ $room->capacity }}</td>
                    <td class="px-4 py-3">{{ (float) $room->rate_amount > 0 ? 'Rs '.number_format($room->rate_amount).' / '.\App\Services\PosUnitCatalog::label($room->rate_unit) : __('hotel_rooms_manage.hotel_rate_at_checkin') }}</td>
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
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        {{ __('pos.hotel_hk_'.$room->housekeeping) }}
                    </td>
                    @if(!empty($canManageRooms))
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <a data-hotel-edit-room="{{ $room->id }}" class="font-semibold text-teal-700" href="{{ route('pos.hotel.rooms', ['edit_room' => $room->id]) }}#room-edit-{{ $room->id }}">{{ __('hotel_rooms_manage.hotel_edit_room') }}</a>
                            <form method="POST" action="{{ route('pos.hotel.rooms.remove', $room->id) }}" data-confirm="{{ __('hotel_rooms_manage.hotel_room_remove_confirm') }}" onsubmit="return confirm(this.dataset.confirm);">
                                @csrf
                                @method('DELETE')
                                <button data-hotel-remove-room="{{ $room->id }}" type="submit" class="font-semibold text-rose-700">{{ __('hotel_rooms_manage.hotel_remove_room') }}</button>
                            </form>
                        </div>
                    </td>
                    @endif
                </tr>
                @if(!empty($canManageRooms) && $editRoomId === (int) $room->id)
                <tr id="room-edit-{{ $room->id }}" class="bg-teal-50 dark:bg-slate-800">
                    <td colspan="8" class="p-4">
                        <h2 class="font-semibold mb-3">{{ __('hotel_rooms_manage.hotel_edit_room') }} · {{ $room->room_number }}</h2>
                        <form method="POST" action="{{ route('pos.hotel.rooms.update', $room->id) }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            @csrf
                            @method('PUT')
                            <label class="text-xs">{{ __('pos.hotel_room_number') }}<input name="room_number" required maxlength="32" value="{{ old('room_number', $room->room_number) }}" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm"></label>
                            <label class="text-xs">{{ __('pos.hotel_room_type') }}<input name="room_type" maxlength="80" value="{{ old('room_type', $room->room_type) }}" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm"></label>
                            <label class="text-xs">{{ __('pos.hotel_capacity') }}<input type="number" name="capacity" min="1" max="50" required value="{{ old('capacity', $room->capacity) }}" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm"></label>
                            <label class="text-xs">{{ __('pos.hotel_rate') }}<input type="number" name="rate_amount" min="0" max="10000000" step="0.01" required value="{{ old('rate_amount', $room->rate_amount) }}" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm"></label>
                            <label class="text-xs">{{ __('pos.hotel_service') }}<select name="service_state" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm">
                                <option value="in_service" @selected(old('service_state', $room->service_state) === 'in_service')>{{ __('pos.hotel_in_service') }}</option>
                                <option value="out_of_service" @selected(old('service_state', $room->service_state) === 'out_of_service')>{{ __('pos.hotel_oos') }}</option>
                            </select></label>
                            <label class="text-xs">{{ __('pos.hotel_hk') }}<select name="housekeeping" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:text-white text-sm">
                                @foreach(['clean', 'dirty', 'inspected'] as $state)
                                <option value="{{ $state }}" @selected(old('housekeeping', $room->housekeeping) === $state)>{{ __('pos.hotel_hk_'.$state) }}</option>
                                @endforeach
                            </select></label>
                            <div class="sm:col-span-2 flex items-end gap-3">
                                <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">{{ __('pos.hotel_save_room') }}</button>
                                <a href="{{ route('pos.hotel.rooms') }}#room-{{ $room->id }}" class="text-sm text-gray-600">{{ __('hotel_rooms_manage.hotel_cancel_edit') }}</a>
                            </div>
                        </form>
                    </td>
                </tr>
                @endif
                @empty
                <tr><td colspan="{{ !empty($canManageRooms) ? 8 : 7 }}" class="px-4 py-6 text-gray-500">{{ __('pos.hotel_no_rooms') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if(!empty($canManageRooms) && $removedRooms->isNotEmpty())
    <details class="mt-5 rounded-xl border border-gray-200 bg-white dark:bg-gray-900 p-4">
        <summary class="cursor-pointer text-sm font-semibold">{{ __('hotel_rooms_manage.hotel_removed_rooms') }} ({{ $removedRooms->count() }})</summary>
        <p class="mt-2 text-xs text-gray-500">{{ __('hotel_rooms_manage.hotel_removed_rooms_hint') }}</p>
        @foreach($removedRooms as $room)
        <div class="mt-3 flex items-center justify-between gap-3 border-t pt-3 text-sm">
            <span>{{ $room->room_number }} · {{ $room->room_type }}</span>
            <form method="POST" action="{{ route('pos.hotel.rooms.restore', $room->id) }}">
                @csrf
                <button class="font-semibold text-teal-700">{{ __('hotel_rooms_manage.hotel_restore_room') }}</button>
            </form>
        </div>
        @endforeach
    </details>
    @endif
    @endif
</div>
</x-hotel-layout>
