@php
    \App\Services\HotelShell::leaveRestaurantOutlet();
    $hotelUser = auth('pos')->user();
    $hotelDesk = \App\Services\HotelAccessService::canFrontDesk($hotelUser);
    $hotelManager = \App\Services\HotelAccessService::canManageRooms($hotelUser);
    $hotelCompany = \App\Models\Company::find(app('currentCompanyId'));
    $links = $hotelDesk ? [
        ['pos.hotel.dashboard', [], 'hotel_menu_dashboard', 'dashboard'],
        ['pos.hotel.stays.create', ['walk_in' => 1], 'hotel_check_in_btn', 'checkin'],
        ['pos.hotel.stays.index', [], 'hotel_menu_stays', 'stays'],
        ['pos.hotel.guests', [], 'hotel_menu_guests', 'guests'],
        ['pos.hotel.stays.index', ['status' => 'checked_in', 'checkout' => 1], 'hotel_check_out_btn', 'checkout'],
        ['pos.hotel.folios', [], 'hotel_menu_bills', 'folios'],
    ] : [];
@endphp
<nav class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-1 gap-1" data-hotel-native-nav="1" aria-label="{{ __('pos.nav_hotel_front_desk') }}">
    @foreach($links as [$name, $params, $label, $key])
    <a href="{{ route($name, $params) }}" data-hotel-desk-link="{{ $key }}" class="rounded-lg px-3 py-2.5 text-sm font-semibold {{ request()->routeIs($name) && !request()->boolean('checkout') && $key !== 'checkout' ? 'bg-teal-700 text-white' : 'text-slate-700 hover:bg-teal-50 dark:text-slate-200 dark:hover:bg-slate-800' }}">{{ __('pos.'.$label) }}</a>
    @endforeach
    @if(!$hotelDesk && \App\Services\HotelAccessService::canHousekeeping($hotelUser))
    <a href="{{ route('pos.hotel.housekeeping') }}" class="rounded-lg px-3 py-2.5 bg-teal-700 text-white text-sm font-semibold">{{ __('pos.nav_hotel_housekeeping') }}</a>
    @endif
</nav>
@if($hotelManager)
<details class="mt-4 border-t border-slate-200 dark:border-slate-700 pt-3" @if(request()->routeIs('pos.hotel.rooms', 'pos.hotel.housekeeping', 'pos.hotel.reports')) open @endif>
    <summary class="cursor-pointer text-sm font-semibold dark:text-white">{{ __('pos.hotel_desk_more_management') }}</summary>
    <nav class="mt-2 grid gap-1 text-sm">
        <a href="{{ route('pos.hotel.rooms') }}" data-hotel-desk-link="rooms" class="rounded-lg px-3 py-2 hover:bg-teal-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('pos.nav_hotel_rooms') }}</a>
        <a href="{{ route('pos.hotel.housekeeping') }}" class="rounded-lg px-3 py-2 hover:bg-teal-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('pos.nav_hotel_housekeeping') }}</a>
        <a href="{{ route('pos.hotel.reports') }}" class="rounded-lg px-3 py-2 hover:bg-teal-50 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('pos.nav_hotel_reports') }}</a>
    </nav>
</details>
@endif
@if(\App\Services\HotelShell::restaurantOutletOn($hotelCompany) && \App\Services\HotelShell::canOpenRestaurantOutlet($hotelUser, $hotelCompany))
<a href="{{ route('pos.hotel.restaurant-outlet') }}" data-hotel-restaurant-outlet="1" class="block mt-4 rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('pos.nav_hotel_restaurant_outlet') }}</a>
@endif
