<x-hotel-layout>
<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-5">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $heading ?? __('pos.hotel_stays') }}</h1>
        <a href="{{ route('pos.hotel.stays.create') }}" class="px-4 py-2 rounded-lg bg-teal-700 text-white text-sm font-semibold">{{ __('pos.hotel_new_stay') }}</a>
    </div>
    <nav class="flex flex-wrap gap-2 mb-4" aria-label="{{ __('pos.hotel_menu_stays') }}">
        @foreach(['' => 'hotel_all_stays', 'reserved' => 'hotel_upcoming', 'checked_in' => 'hotel_in_house', 'checked_out' => 'hotel_completed'] as $value => $label)
        <a href="{{ route('pos.hotel.stays.index', ['status' => $value, 'checkout' => request('checkout')]) }}" class="rounded-lg px-3 py-2 text-sm {{ $status === $value ? 'bg-teal-700 text-white' : 'bg-white border dark:bg-gray-900 dark:text-white' }}">{{ __('pos.'.$label) }}</a>
        @endforeach
    </nav>
    <form method="GET" class="flex gap-2 mb-4">
        <input type="hidden" name="status" value="{{ $status }}">
        <input type="hidden" name="checkout" value="{{ request('checkout') }}">
        <input name="q" value="{{ request('q') }}" placeholder="{{ __('pos.hotel_search') }}" class="w-full max-w-sm rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        <button class="rounded-lg border px-3 text-sm dark:text-white">{{ __('pos.hotel_search_btn') }}</button>
    </form>
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="min-w-[40rem] w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 uppercase">
                    <th class="px-4 py-3">{{ __('pos.hotel_stay_no') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_guest') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_room') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_dates') }}</th>
                    <th class="px-4 py-3">{{ __('pos.status_col') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stays as $stay)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3"><a class="font-semibold text-teal-800" href="{{ route(request()->boolean('checkout') && $stay->status === 'checked_in' ? 'pos.hotel.checkout' : 'pos.hotel.stays.show', $stay->id) }}">{{ $stay->stay_number }}</a></td>
                    <td class="px-4 py-3">{{ $stay->guest_name }}</td>
                    <td class="px-4 py-3">{{ $stay->room?->room_number }}</td>
                    <td class="px-4 py-3">{{ $stay->check_in_date->format('d M') }} – {{ $stay->check_out_date->format('d M') }} ({{ $stay->nights }} {{ \App\Services\PosUnitCatalog::label($stay->rate_unit) }})</td>
                    <td class="px-4 py-3">{{ \App\Services\HotelShell::statusLabel($stay->status) }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-6 text-gray-500">{{ __('pos.hotel_no_stays') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $stays->links() }}</div>
</div>
</x-hotel-layout>
