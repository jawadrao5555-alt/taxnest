@php
    $hotelUser = auth('pos')->user();
    $hotelDesk = \App\Services\HotelAccessService::canFrontDesk($hotelUser);
    $hotelHk = \App\Services\HotelAccessService::canHousekeeping($hotelUser);
@endphp
<nav class="flex flex-wrap gap-1.5 mb-5" data-hotel-native-nav="1">
    @if($hotelDesk)
    <a href="{{ route('pos.hotel.dashboard') }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('pos.hotel.dashboard') ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 dark:text-teal-200' }}">{{ __('pos.nav_hotel_front_desk') }}</a>
    <a href="{{ route('pos.hotel.reservations') }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('pos.hotel.reservations') ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 dark:text-teal-200' }}">{{ __('pos.nav_hotel_reservations') }}</a>
    @endif
    @if($hotelHk)
    <a href="{{ route('pos.hotel.rooms') }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('pos.hotel.rooms') ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 dark:text-teal-200' }}">{{ __('pos.nav_hotel_rooms') }}</a>
    <a href="{{ route('pos.hotel.housekeeping') }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('pos.hotel.housekeeping') ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 dark:text-teal-200' }}">{{ __('pos.nav_hotel_housekeeping') }}</a>
    @endif
    @if($hotelDesk)
    <a href="{{ route('pos.hotel.guests') }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('pos.hotel.guests') ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 dark:text-teal-200' }}">{{ __('pos.nav_hotel_guests') }}</a>
    <a href="{{ route('pos.hotel.folios') }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('pos.hotel.folios') ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 dark:text-teal-200' }}">{{ __('pos.nav_hotel_folios') }}</a>
    <a href="{{ route('pos.hotel.reports') }}" class="px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('pos.hotel.reports') ? 'bg-teal-700 text-white' : 'bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 dark:text-teal-200' }}">{{ __('pos.nav_hotel_reports') }}</a>
    @endif
</nav>
@if($hotelDesk && !empty($showHotelPrimaryActions))
<div class="flex flex-wrap gap-2 mb-5" data-hotel-primary-actions="1">
    <a href="{{ route('pos.hotel.stays.create') }}" class="px-3 py-2 rounded-lg bg-teal-700 text-white text-xs font-semibold">{{ __('pos.hotel_action_booking') }}</a>
    <a href="{{ route('pos.hotel.stays.create', ['walk_in' => 1]) }}" class="px-3 py-2 rounded-lg bg-teal-700 text-white text-xs font-semibold">{{ __('pos.hotel_action_walkin') }}</a>
    <a href="{{ route('pos.hotel.stays.index', ['status' => 'reserved']) }}" class="px-3 py-2 rounded-lg bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 text-xs font-semibold">{{ __('pos.hotel_check_in_btn') }}</a>
    <a href="{{ route('pos.hotel.stays.index', ['status' => 'checked_in']) }}" class="px-3 py-2 rounded-lg bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 text-xs font-semibold">{{ __('pos.hotel_check_out_btn') }}</a>
    <a href="{{ route('pos.hotel.folios') }}" class="px-3 py-2 rounded-lg bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 text-xs font-semibold">{{ __('pos.hotel_action_charge') }}</a>
    <a href="{{ route('pos.hotel.folios') }}" class="px-3 py-2 rounded-lg bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 text-xs font-semibold">{{ __('pos.hotel_action_payment') }}</a>
    <a href="{{ route('pos.hotel.housekeeping') }}" class="px-3 py-2 rounded-lg bg-white dark:bg-gray-900 border border-teal-200 text-teal-900 text-xs font-semibold">{{ __('pos.nav_hotel_housekeeping') }}</a>
</div>
@endif
