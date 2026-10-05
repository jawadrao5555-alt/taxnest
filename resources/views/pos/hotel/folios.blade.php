<x-hotel-layout>
<div class="tn-page tn-hotel-page max-w-6xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-5">{{ __('pos.hotel_menu_bills') }}</h1>
    <div class="tn-table-shell bg-white dark:bg-gray-900 rounded-xl border overflow-x-auto">
        <table class="min-w-[40rem] w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 uppercase">
                    <th class="px-4 py-3">{{ __('pos.hotel_stay_no') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_guest') }}</th>
                    <th class="px-4 py-3">{{ __('pos.hotel_room') }}</th>
                    <th class="px-4 py-3">{{ __('pos.status_col') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('pos.hotel_folio_due') }} ({{ __('hotel_simplify.cash_estimate') }})</th>
                    <th class="px-4 py-3">{{ __('pos.action_col') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stays as $stay)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3"><a class="font-semibold text-teal-800" href="{{ route('pos.hotel.stays.show', $stay->id) }}">{{ $stay->stay_number }}</a></td>
                    <td class="px-4 py-3">{{ $stay->guest_name }}</td>
                    <td class="px-4 py-3">{{ $stay->room?->room_number }}</td>
                    <td class="px-4 py-3">{{ \App\Services\HotelShell::statusLabel($stay->status) }}</td>
                    <td class="px-4 py-3 text-right font-semibold">@if(($dues[$stay->id] ?? 0) > 0.009)<span class="text-amber-800">Rs {{ number_format($dues[$stay->id], 2) }}</span><span class="block text-xs font-normal">{{ __('hotel_simplify.payment_pending') }}</span>@else<span class="text-emerald-700">Rs 0.00</span>@endif</td>
                    <td class="px-4 py-3">
                        <a class="font-semibold text-teal-700" href="{{ route('pos.hotel.stays.statement', $stay->id) }}" data-hotel-bill-reprint="1">{{ __('hotel_bill.bill_action') }}</a>
                        @if($receiptId = $stay->folioEntries->pluck('pos_transaction_id')->filter()->first())
                        <a class="block text-xs text-teal-700 mt-1" href="{{ route('pos.hotel.bill-receipt', [$stay->id, $receiptId]) }}" target="_blank" rel="noopener">{{ __('hotel_bill.issued_receipt') }}</a>
                        @endif
                        @if(\App\Services\HotelAccessService::canManageRooms(auth('pos')->user()) && in_array($stay->status, ['checked_in', 'checked_out'], true) && !($fiscalLocked[$stay->id] ?? true))
                        <a class="block text-xs text-red-700 mt-1" href="{{ route('pos.hotel.stays.correction', $stay->id) }}">{{ __('hotel_correction.title') }}</a>
                        @endif
                        @if(($dues[$stay->id] ?? 0) > 0.009)
                        <a class="block text-xs text-teal-700 mt-1" href="{{ route('pos.hotel.stays.show', $stay->id) }}#hotel-payment">{{ __('pos.hotel_collect_now') }}</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-6 text-gray-500">{{ __('pos.hotel_no_pending') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
    <div class="mt-4">{{ $stays->links() }}</div>
</x-hotel-layout>
