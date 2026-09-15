<x-pos-layout>
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.hotel._nav', ['showHotelPrimaryActions' => true])
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-5">{{ __('pos.nav_hotel_folios') }}</h1>
    <div class="bg-white dark:bg-gray-900 rounded-xl border overflow-x-auto">
        <table class="min-w-[40rem] w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 uppercase">
                    <th class="px-4 py-3">{{ __('pos.hotel_stay_no') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_guest') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_room') }}</th>
                    <th class="px-4 py-3">{{ __('pos.status_col') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('pos.hotel_folio_due') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stays as $stay)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3"><a class="font-semibold text-teal-800" href="{{ route('pos.hotel.stays.show', $stay->id) }}">{{ $stay->stay_number }}</a></td>
                    <td class="px-4 py-3">{{ $stay->guest_name }}</td>
                    <td class="px-4 py-3">{{ $stay->room?->room_number }}</td>
                    <td class="px-4 py-3">{{ \App\Services\HotelShell::statusLabel($stay->status) }}</td>
                    <td class="px-4 py-3 text-right font-semibold">Rs {{ number_format($dues[$stay->id] ?? 0, 2) }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-6 text-gray-500">{{ __('pos.hotel_no_pending') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-pos-layout>
