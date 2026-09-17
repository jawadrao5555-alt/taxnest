<x-pos-layout>
<div class="tn-page tn-hotel-page max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.hotel._nav')
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-5">{{ __('pos.nav_hotel_guests') }}</h1>
    <div class="tn-table-shell bg-white dark:bg-gray-900 rounded-xl border overflow-x-auto">
        <table class="min-w-[36rem] w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 uppercase">
                    <th class="px-4 py-3">{{ __('pos.hotel_guest') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_phone') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_stay_no') }}</th>
                    <th class="px-4 py-3">{{ __('pos.status_col') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($guests as $stay)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3"><a class="font-semibold text-teal-800" href="{{ route('pos.hotel.stays.show', $stay->id) }}">{{ $stay->guest_name }}</a></td>
                    <td class="px-4 py-3">{{ $stay->guest_phone }}</td>
                    <td class="px-4 py-3">{{ $stay->stay_number }}</td>
                    <td class="px-4 py-3">{{ \App\Services\HotelShell::statusLabel($stay->status) }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-6 text-gray-500">{{ __('pos.hotel_no_stays') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-pos-layout>
