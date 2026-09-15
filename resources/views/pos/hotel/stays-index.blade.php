<x-pos-layout>
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.hotel.dashboard') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-teal-700 mb-3">{{ __('pos.hotel_back_desk') }}</a>
    <div class="flex items-center justify-between mb-5">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.hotel_stays') }}</h1>
        <a href="{{ route('pos.hotel.stays.create') }}" class="px-4 py-2 rounded-lg bg-teal-700 text-white text-sm font-semibold">{{ __('pos.hotel_new_stay') }}</a>
    </div>
    <form method="GET" class="mb-4">
        <select name="status" onchange="this.form.submit()" class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
            <option value="">{{ __('pos.hotel_all_stays') }}</option>
            @foreach(['reserved','checked_in','checked_out','cancelled','no_show'] as $st)
            <option value="{{ $st }}" @selected($status===$st)>{{ $st }}</option>
            @endforeach
        </select>
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
                    <td class="px-4 py-3"><a class="font-semibold text-teal-800" href="{{ route('pos.hotel.stays.show', $stay->id) }}">{{ $stay->stay_number }}</a></td>
                    <td class="px-4 py-3">{{ $stay->guest_name }}</td>
                    <td class="px-4 py-3">{{ $stay->room?->room_number }}</td>
                    <td class="px-4 py-3">{{ $stay->check_in_date->format('d M') }} – {{ $stay->check_out_date->format('d M') }} ({{ $stay->nights }} {{ \App\Services\PosUnitCatalog::label($stay->rate_unit) }})</td>
                    <td class="px-4 py-3">{{ $stay->status }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-6 text-gray-500">{{ __('pos.hotel_no_stays') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $stays->links() }}</div>
</div>
</x-pos-layout>
