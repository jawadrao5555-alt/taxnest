<x-pos-layout>
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.hotel.stays.index') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-teal-700 mb-3">{{ __('pos.hotel_back_stays') }}</a>
    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 text-emerald-700 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">{{ session('error') }}</div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stay->stay_number }}</h1>
            <p class="text-sm text-gray-500">{{ $stay->guest_name }} · {{ __('pos.hotel_room') }} {{ $stay->room?->room_number }} · {{ $stay->status }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $stay->check_in_date->format('d M Y') }} – {{ $stay->check_out_date->format('d M Y') }} · {{ $stay->nights }} {{ \App\Services\PosUnitCatalog::label($stay->rate_unit) }} · {{ __('pos.hotel_charging_nightly') }}</p>
            @if($stay->guest_phone || $stay->guest_cnic)
            <p class="text-xs text-gray-500 mt-1">{{ $stay->guest_phone }} @if($stay->guest_cnic)· {{ __('pos.hotel_cnic_optional') }} {{ $stay->guest_cnic }}@endif</p>
            <p class="text-[10px] text-gray-400">{{ __('pos.hotel_cnic_staff_only') }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if($stay->status === 'reserved')
            <form method="POST" action="{{ route('pos.hotel.stays.check-in', $stay->id) }}">@csrf<button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_check_in_btn') }}</button></form>
            <form method="POST" action="{{ route('pos.hotel.stays.cancel', $stay->id) }}">@csrf<button class="px-3 py-2 bg-gray-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_cancel_btn') }}</button></form>
            <form method="POST" action="{{ route('pos.hotel.stays.no-show', $stay->id) }}">@csrf<button class="px-3 py-2 bg-gray-600 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_no_show_btn') }}</button></form>
            @endif
            @if($stay->status === 'checked_in')
            <form method="POST" action="{{ route('pos.hotel.stays.check-out', $stay->id) }}">@csrf<button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold" @if(($totals['outstanding'] ?? 0) > 0) onclick="return confirm(@json(__('pos.hotel_checkout_due_confirm', ['amount' => number_format($totals['outstanding'], 2)])))"@endif>{{ __('pos.hotel_check_out_btn') }}</button></form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-6">
        @foreach([
            ['hotel_folio_charges', $totals['charges']],
            ['hotel_folio_paid', $totals['payments'] - $totals['refunds']],
            ['hotel_folio_due', $totals['outstanding']],
            ['hotel_folio_deposit', $totals['deposit_held']],
        ] as $tile)
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ __("pos.{$tile[0]}") }}</p>
            <p class="text-lg font-extrabold mt-1">Rs {{ number_format($tile[1]) }}</p>
        </div>
        @endforeach
    </div>

    @if(in_array($stay->status, ['reserved','checked_in'], true))
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <form method="POST" action="{{ route('pos.hotel.stays.extend', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
            @csrf
            <h3 class="text-sm font-semibold">{{ __('pos.hotel_extend') }}</h3>
            <input type="date" name="check_out_date" value="{{ $stay->check_out_date->toDateString() }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
            <button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_extend_btn') }}</button>
        </form>
        <form method="POST" action="{{ route('pos.hotel.stays.move', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
            @csrf
            <h3 class="text-sm font-semibold">{{ __('pos.hotel_move') }}</h3>
            <select name="room_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                @foreach($rooms as $room)
                <option value="{{ $room->id }}" @selected($room->id===$stay->room_id)>{{ $room->room_number }} · {{ $room->room_type }}</option>
                @endforeach
            </select>
            <button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_move_btn') }}</button>
        </form>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <form method="POST" action="{{ route('pos.hotel.folio.charge', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
            @csrf
            <h3 class="text-sm font-semibold">{{ __('pos.hotel_post_charge') }}</h3>
            <input name="description" placeholder="{{ __('pos.hotel_charge_desc') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
            @if(isset($products) && $products->isNotEmpty())
            <select name="product_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                <option value="">{{ __('pos.hotel_from_product') }}</option>
                @foreach($products as $product)
                <option value="{{ $product->id }}">{{ $product->name }} · Rs {{ number_format((float) $product->price, 2) }} {{ $product->uom }}</option>
                @endforeach
            </select>
            @endif
            <div class="grid grid-cols-3 gap-2">
                <select name="category" class="rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    @foreach(['room','food','laundry','extra','other'] as $cat)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                    @endforeach
                </select>
                <input type="number" step="0.001" name="quantity" value="1" required class="rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                <select name="uom" class="rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    @include('partials.pos-uom-options', ['uomGroups' => $uomGroups, 'uomSelected' => 'NOS'])
                </select>
            </div>
            <input type="number" step="0.01" name="unit_amount" required placeholder="{{ __('pos.hotel_unit_amount') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
            <button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_post_charge') }}</button>
        </form>
        <div class="space-y-4">
            <form method="POST" action="{{ route('pos.hotel.folio.payment', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
                @csrf
                <h3 class="text-sm font-semibold">{{ __('pos.hotel_take_money') }}</h3>
                <input type="number" step="0.01" name="amount" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                <select name="payment_method" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="debit_card">Debit card</option>
                    <option value="credit_card">Credit card</option>
                    <option value="qr_payment">QR</option>
                </select>
                <select name="kind" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    <option value="payment">{{ __('pos.hotel_advance_payment') }}</option>
                    <option value="deposit">{{ __('pos.hotel_security_deposit') }}</option>
                </select>
                <button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.save_btn') }}</button>
            </form>
            <form method="POST" action="{{ route('pos.hotel.folio.refund', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
                @csrf
                <h3 class="text-sm font-semibold">{{ __('pos.hotel_refund') }}</h3>
                <input type="number" step="0.01" name="amount" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                <select name="payment_method" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="qr_payment">QR</option>
                </select>
                <select name="kind" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    <option value="payment">{{ __('pos.hotel_advance_payment') }}</option>
                    <option value="deposit">{{ __('pos.hotel_security_deposit') }}</option>
                </select>
                <button class="px-3 py-2 bg-gray-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_refund') }}</button>
            </form>
            <form method="POST" action="{{ route('pos.hotel.folio.settle', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
                @csrf
                <h3 class="text-sm font-semibold">{{ __('pos.hotel_issue_bill') }}</h3>
                <p class="text-xs text-gray-500">{{ __('pos.hotel_issue_bill_hint') }}</p>
                <select name="payment_method" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="qr_payment">QR</option>
                </select>
                <button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_issue_bill') }}</button>
            </form>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 uppercase">
                    <th class="px-4 py-3">{{ __('pos.hotel_folio') }}</th>
                    <th class="px-4 py-3">{{ __('pos.unit_uom') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('pos.receipt_price') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($stay->folioEntries as $entry)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3">
                        <span class="text-[10px] uppercase font-bold text-gray-500">{{ $entry->entry_type }}</span>
                        <p>{{ $entry->description }}</p>
                        @if($entry->pos_transaction_id)
                        <a class="text-xs text-teal-800" href="{{ url('/pos/transaction/'.$entry->pos_transaction_id) }}">{{ __('pos.hotel_fiscal_bill') }}</a>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ rtrim(rtrim(number_format($entry->quantity, 3), '0'), '.') }} {{ \App\Services\PosUnitCatalog::label($entry->uom) }}</td>
                    <td class="px-4 py-3 text-right">Rs {{ number_format($entry->amount, 2) }}</td>
                    <td class="px-4 py-3">
                        @if($entry->entry_type === 'charge' && !$entry->pos_transaction_id)
                        <form method="POST" action="{{ route('pos.hotel.folio.reverse', $stay->id) }}">
                            @csrf
                            <input type="hidden" name="entry_id" value="{{ $entry->id }}">
                            <button class="text-xs text-red-700">{{ __('pos.hotel_reverse') }}</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="text-xs text-gray-500 mt-3">{{ __('pos.hotel_deposit_not_revenue') }}</p>
</div>
</x-pos-layout>
