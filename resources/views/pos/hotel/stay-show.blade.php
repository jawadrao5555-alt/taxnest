<x-hotel-layout>
<div class="tn-page tn-hotel-page max-w-6xl mx-auto">
    @if($stay->status === 'checked_in')
    <form hidden data-hotel-bill-desk="1" data-initial-flow="{{ request('bill_action') === 'checkout' ? 'checkout' : 'collect' }}" data-hotel-confirm-flow="collect" data-auto-open="{{ ((int) session('hotel_checkin_preview') === (int) $stay->id || request('bill_action') === 'checkout') ? 1 : 0 }}" data-preview-url="{{ route('pos.hotel.bill-preview', $stay->id) }}" data-confirm-url="{{ route('pos.hotel.bill-confirm', $stay->id) }}" data-quote-url="{{ route('pos.hotel.checkout-quote', $stay->id) }}">
        @csrf
        <input name="payment_method" value="cash">
        <input name="amount" value="{{ $deskSummary['balance'] }}">
    </form>
    @endif
    <a href="{{ route('pos.hotel.stays.index') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-teal-700 mb-3">{{ __('pos.hotel_back_stays') }}</a>
    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 text-emerald-700 text-sm">{{ session('success') }}</div>
    @endif
    @if($errors->any())<p role="alert" class="mb-4 text-red-700">{{ $errors->first() }}</p>@endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">{{ session('error') }}</div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stay->stay_number }}</h1>
            <p class="text-sm text-gray-500">{{ $stay->guest_name }} · {{ __('pos.hotel_room') }} {{ $stay->room?->room_number }} · {{ \App\Services\HotelShell::statusLabel($stay->status) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $stay->check_in_date->format('d M Y') }} – {{ $stay->check_out_date->format('d M Y') }} · {{ $stay->nights }} {{ \App\Services\PosUnitCatalog::label($stay->rate_unit) }} · {{ __('pos.hotel_charging_nightly') }}</p>
            @if($stay->guest_phone || $stay->guest_cnic)
            <p class="text-xs text-gray-500 mt-1">{{ $stay->guest_phone }} @if($stay->guest_cnic)· {{ __('pos.hotel_cnic_optional') }} {{ $stay->guest_cnic }}@endif</p>
            <p class="text-[10px] text-gray-400">{{ __('pos.hotel_cnic_staff_only') }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if($stay->status === 'checked_in')<button type="button" data-hotel-open-desk="1" class="rounded-lg bg-teal-700 text-white px-3 py-2 text-sm">{{ __('hotel_preview.title') }}</button>@endif
            <a href="{{ route('pos.hotel.stays.statement', $stay->id) }}" class="px-3 py-2 rounded-lg border border-teal-300 text-teal-800 text-xs font-semibold" data-hotel-print-bill="1">{{ __('hotel_bill.bill_action') }}</a>
            @if($stay->status === 'reserved')
            <form method="POST" action="{{ route('pos.hotel.stays.check-in', $stay->id) }}">@csrf<button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_check_in_btn') }}</button></form>
            @endif
            @if($stay->status === 'checked_in')
            <a href="{{ route('pos.hotel.checkout', $stay->id) }}" class="px-4 py-2 bg-teal-700 text-white text-sm rounded-lg font-semibold">{{ __('pos.hotel_check_out_btn') }}</a>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-3 gap-2 mb-6">
        @foreach([
            ['hotel_total', $deskSummary['total_stay']],
            ['hotel_folio_paid', $totals['payments'] - $totals['refunds']],
            ['hotel_folio_due', $deskSummary['balance']],
        ] as $tile)
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ __("pos.{$tile[0]}") }}</p>
            <p class="text-lg font-extrabold mt-1">Rs {{ number_format($tile[1]) }}</p>
        </div>
        @endforeach
    </div>
    @if($issuedBills->isNotEmpty())
    <section class="mb-4 rounded-xl border bg-white dark:bg-gray-900 p-4" data-hotel-issued-bills="1">
        <h2 class="font-semibold">{{ __('hotel_bill.bill_action') }}</h2>
        @foreach($issuedBills as $bill)
        @php
            $accepted = $bill->pra_status === 'submitted' && !empty($bill->pra_invoice_number);
            $billState = $accepted ? 'submitted' : ($bill->pra_status ?: 'local');
        @endphp
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2" data-hotel-bill-id="{{ $bill->id }}" data-hotel-bill-state="{{ $billState }}">
            <div>
                <strong>{{ $bill->invoice_number }}</strong>
                <p class="text-xs">{{ $accepted ? __('pos.hotel_fiscal_bill').' · '.$bill->pra_invoice_number : __('hotel_preview.status_'.$billState) }}</p>
            </div>
            <a href="{{ route('pos.receipt', $bill->id) }}" target="_blank" rel="noopener" class="rounded-lg border px-3 py-2 text-sm">{{ __('hotel_bill.issued_receipt') }}</a>
        </div>
        @endforeach
    </section>
    @endif
    @if(in_array($stay->status, ['checked_in', 'checked_out'], true) && ($totals['uninvoiced_charges'] ?? 0) > 0.009)
            <form method="POST" action="{{ route('pos.hotel.bill-confirm', $stay->id) }}" data-hotel-confirm-flow="settle" data-preview-url="{{ route('pos.hotel.bill-preview', $stay->id) }}" data-confirm-url="{{ route('pos.hotel.bill-confirm', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
                @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                <h3 class="text-sm font-semibold">{{ __('hotel_preview.title') }}</h3>
                <p class="text-xs text-gray-500">{{ __('pos.hotel_issue_bill_hint') }}</p>
                <select name="payment_method" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    @include('pos.hotel._payment-methods', ['hotelPayMethods' => ['cash', 'card', 'qr_payment']])
                </select>
                <button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('hotel_preview.title') }}</button>
            </form>
    @endif
    @if(($deskSummary['balance'] ?? 0) > 0.009)
    <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ __('hotel_simplify.payment_pending') }} · {{ __('hotel_simplify.cash_estimate') }}. {{ __('hotel_simplify.method_changes_tax') }} <a href="#hotel-payment" class="font-semibold underline">{{ __('pos.hotel_collect_now') }}</a></p>
    @endif

    @if(($totals['advance_credit'] ?? 0) > 0 || ($totals['deposit_held'] ?? 0) > 0)
    <p class="text-xs text-slate-600 dark:text-slate-300 mb-5">
        {{ __('pos.hotel_folio_advance_credit') }}: Rs {{ number_format($totals['advance_credit'] ?? 0) }}
        · {{ __('pos.hotel_folio_deposit') }}: Rs {{ number_format($totals['deposit_held'] ?? 0) }}
    </p>
    @endif

    @if(in_array($stay->status, ['reserved','checked_in'], true))
    <details class="mb-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
        <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('pos.hotel_desk_stay_options') }}</summary>
        @if($stay->notes)<p class="mt-3 text-sm">{{ $stay->notes }}</p>@endif
    @php
        $changeConfig = ['url' => route('pos.hotel.change-quote', $stay->id), 'rate' => (float) $stay->rate_amount, 'date' => $stay->check_out_date->toDateString(), 'room' => '', 'failure' => __('pos.hotel_quote_failed')];
    @endphp
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
        <form x-data="hotelChangeForm(@js($changeConfig + ['kind' => 'extend']))" @submit="if (!quote || busy || error) $event.preventDefault()" method="POST" action="{{ route('pos.hotel.stays.extend', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <h3 class="text-sm font-semibold">{{ __('pos.hotel_extend') }}</h3>
            <input x-model="date" type="date" name="check_out_date" value="{{ $stay->check_out_date->toDateString() }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
            <label class="block text-xs">{{ __('pos.hotel_agreed_rate') }}<input x-model="rate" name="rate_amount" type="number" min="0" max="10000000" step="0.01" class="block mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800"></label>
            <button type="button" @click="refresh()" class="text-sm text-teal-700 underline">{{ __('pos.hotel_preview_change') }}</button>
            <p x-show="error" x-text="error" class="text-xs text-red-700" role="alert"></p>
            <p x-show="quote" class="text-sm">{{ __('pos.hotel_change_amount') }}: <strong x-text="'Rs ' + money(quote?.change)"></strong> · {{ __('pos.hotel_open_bill_total') }}: <strong x-text="'Rs ' + money(quote?.total)"></strong></p>
            <p class="text-xs text-gray-500">{{ __('pos.hotel_change_hint') }}</p>
            <button :disabled="!quote || busy || !!error" class="disabled:opacity-50 px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_extend_btn') }}</button>
        </form>
        <form x-data="hotelChangeForm(@js($changeConfig + ['kind' => 'move']))" @submit="if (!quote || busy || error) $event.preventDefault()" method="POST" action="{{ route('pos.hotel.stays.move', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <h3 class="text-sm font-semibold">{{ __('pos.hotel_move') }}</h3>
            <select x-model="room" name="room_id" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                <option value="" selected disabled>{{ __('pos.hotel_select_room') }}</option>
                @foreach($rooms as $room)
                @if($room->id !== $stay->room_id)
                <option value="{{ $room->id }}">{{ $room->room_number }} · {{ $room->room_type }}</option>
                @endif
                @endforeach
            </select>
            <label class="block text-xs">{{ __('pos.hotel_agreed_rate') }}<input x-model="rate" name="rate_amount" type="number" min="0" max="10000000" step="0.01" class="block mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800"></label>
            <button type="button" @click="refresh()" class="text-sm text-teal-700 underline">{{ __('pos.hotel_preview_change') }}</button>
            <p x-show="error" x-text="error" class="text-xs text-red-700" role="alert"></p>
            <p x-show="quote" class="text-sm">{{ __('pos.hotel_change_amount') }}: <strong x-text="'Rs ' + money(quote?.change)"></strong> · {{ __('pos.hotel_open_bill_total') }}: <strong x-text="'Rs ' + money(quote?.total)"></strong></p>
            <p class="text-xs text-gray-500">{{ __('pos.hotel_change_hint') }}</p>
            <button :disabled="!quote || busy || !!error" class="disabled:opacity-50 px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_move_btn') }}</button>
        </form>
    </div>
    @if($stay->status === 'reserved')
    <div class="flex gap-3 mt-4">
        <form method="POST" action="{{ route('pos.hotel.stays.cancel', $stay->id) }}">@csrf<button class="text-xs font-semibold text-gray-600 underline">{{ __('pos.hotel_cancel_btn') }}</button></form>
        <form method="POST" action="{{ route('pos.hotel.stays.no-show', $stay->id) }}">@csrf<button class="text-xs font-semibold text-gray-600 underline">{{ __('pos.hotel_no_show_btn') }}</button></form>
    </div>
    @endif
    <form method="POST" action="{{ route('pos.hotel.stays.discount', $stay->id) }}" class="mt-4 flex flex-wrap gap-2 items-end">
        @csrf
        <label class="text-xs">{{ __('pos.hotel_room_discount') }}<input name="discount_value" value="{{ $stay->discount_value ?? 0 }}" type="number" min="0" max="10000000" step="0.01" required class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800"></label>
        <select name="discount_type" class="rounded-lg border-gray-300 dark:bg-gray-800 text-sm"><option value="amount" @selected($stay->discount_type !== 'percentage')>Rs</option><option value="percentage" @selected($stay->discount_type === 'percentage')>%</option></select>
        <button class="rounded-lg border px-3 py-2 text-sm">{{ __('pos.save_btn') }}</button>
        <p class="w-full text-xs text-gray-500">{{ __('pos.hotel_pricing_invoiced') }}</p>
    </form>
    </details>
    @endif

    @if(\App\Services\HotelAccessService::canManageRooms(auth('pos')->user()) && in_array($stay->status, ['checked_in', 'checked_out'], true) && !\App\Services\HotelCorrectionService::fiscalLocked($issuedBills))
    <details class="mb-6 rounded-xl border border-red-200 p-3" data-hotel-void-error="1">
        <summary class="cursor-pointer text-sm font-semibold text-red-700">{{ __('pos.hotel_void_error_title') }}</summary>
        <p class="mt-2 text-xs text-gray-600">{{ __('pos.hotel_void_error_hint') }}</p>
        <form method="POST" action="{{ route('pos.hotel.stays.void-error', $stay->id) }}" class="mt-2 flex flex-wrap items-end gap-2">
            @csrf
            <label class="text-xs">{{ __('pos.hotel_void_reason') }}
                <input name="reason" required minlength="5" maxlength="255" class="block rounded-lg border-gray-300 dark:bg-gray-800">
            </label>
            <button class="rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-700">{{ __('pos.hotel_void_error_title') }}</button>
        </form>
    </details>
    @endif

    @if(\App\Services\HotelAccessService::canManageRooms(auth('pos')->user()) && in_array($stay->status, ['checked_in', 'checked_out'], true))
    <a class="block mb-4 underline" href="{{ route('pos.hotel.credit-notes', $stay->id) }}">{{ __('hotel_credit.title') }}</a>
    @endif
    @if(\App\Services\HotelAccessService::canManageRooms(auth('pos')->user()) && in_array($stay->status, ['checked_in', 'checked_out'], true) && !\App\Services\HotelCorrectionService::fiscalLocked($issuedBills))
    <a href="{{ route('pos.hotel.stays.correction', $stay->id) }}" class="block mb-6 text-red-700 underline font-semibold">{{ __('hotel_correction.title') }}</a>
    @endif
    <div class="mb-6" x-data="{ action: window.location.hash === '#hotel-charge' ? 'charge' : (window.location.hash === '#hotel-payment' ? 'payment' : '') }">
        <div class="flex flex-wrap gap-3 mb-4">
            <button type="button" data-hotel-take-payment="1" @click="action = action === 'payment' ? '' : 'payment'" :aria-expanded="action === 'payment'" class="rounded-lg bg-teal-700 px-4 py-2 text-white text-sm font-semibold">{{ __('pos.hotel_take_money') }}</button>
            @if($stay->isOpen())<button type="button" @click="action = action === 'charge' ? '' : 'charge'" :aria-expanded="action === 'charge'" class="rounded-lg border border-teal-300 px-4 py-2 text-teal-800 dark:text-teal-200 text-sm font-semibold">{{ __('pos.hotel_action_charge') }}</button>@endif
        </div>
        <form method="POST" action="{{ route('pos.hotel.folio.charge', $stay->id) }}" id="hotel-charge" x-data="{ price: '', product: '', service: '' }" x-show="action === 'charge'" x-cloak class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <h3 class="text-sm font-semibold">{{ __('pos.hotel_post_charge') }}</h3>
            <input name="description" placeholder="{{ __('pos.hotel_charge_desc') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
            @if(isset($products) && $products->isNotEmpty())
            <select name="product_id" x-model="product" @change="service = ''; price = $event.target.selectedOptions[0]?.dataset.price || ''" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm" data-hotel-stay-extras="1">
                <option value="">{{ __('pos.hotel_from_product') }}</option>
                @foreach($products as $product)
                <option value="{{ $product->id }}" data-price="{{ $product->price }}">{{ $product->name }} · Rs {{ number_format((float) $product->price, 2) }} {{ $product->uom }}</option>
                @endforeach
            </select>
            @endif
            @if(isset($services) && $services->isNotEmpty())
            <select name="service_id" x-model="service" @change="product = ''; price = $event.target.selectedOptions[0]?.dataset.price || ''" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm" data-hotel-stay-services="1">
                <option value="">{{ __('pos.hotel_from_service') }}</option>
                @foreach($services as $service)
                <option value="{{ $service->id }}" data-price="{{ $service->price }}">{{ $service->name }} · Rs {{ number_format((float) $service->price, 2) }}</option>
                @endforeach
            </select>
            @endif
            <div class="grid grid-cols-3 gap-2">
                <select name="category" class="rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    @foreach(['room','food','laundry','extra','other'] as $cat)
                    <option value="{{ $cat }}">{{ __('pos.hotel_cat_'.$cat) }}</option>
                    @endforeach
                </select>
                <input type="number" step="0.001" name="quantity" value="1" required class="rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                <select name="uom" class="rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    @include('partials.pos-uom-options', ['uomGroups' => $uomGroups, 'uomSelected' => 'NOS'])
                </select>
            </div>
            <input type="number" step="0.01" name="unit_amount" x-model="price" required placeholder="{{ __('pos.hotel_unit_amount') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
            <details class="text-sm"><summary class="cursor-pointer">{{ __('pos.hotel_extra_discount') }}</summary>
                <div class="flex gap-2 mt-2">
                    <select name="discount_type" class="rounded-lg border-gray-300 dark:bg-gray-800"><option value="amount">Rs</option><option value="percentage">%</option></select>
                    <input name="discount_value" type="number" min="0" max="10000000" step="0.01" value="0" class="min-w-0 rounded-lg border-gray-300 dark:bg-gray-800">
                </div>
            </details>
            <button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_post_charge') }}</button>
        </form>
        <div class="space-y-4">
            <form method="POST" action="{{ route('pos.hotel.folio.payment', $stay->id) }}" id="hotel-payment" x-data="hotelCollectionForm(@js(['url' => route('pos.hotel.checkout-quote', $stay->id), 'failure' => __('pos.hotel_quote_failed')]))" x-show="action === 'payment'" x-cloak class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
                @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                <h3 class="text-sm font-semibold">{{ __('pos.hotel_take_money') }}</h3>
                <p data-hotel-collection-quote="1" x-show="kind === 'payment' && quote" class="text-sm">{{ __('pos.hotel_folio_due') }} ({{ __('pos.hotel_payment_method') }}): <strong x-text="'Rs ' + money(quote?.balance)"></strong></p>
                <p x-show="busy" class="text-xs">{{ __('pos.hotel_calculating') }}</p>
                <p x-show="error" x-text="error" class="text-xs text-red-700" role="alert"></p>
                <label class="block text-sm">{{ __('pos.hotel_collect_now') }}<input x-model="amount" @input="edited = true" type="number" min="0.01" max="10000000" step="0.01" name="amount" required class="block mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm"></label>
                <p class="text-xs text-slate-600">{{ __('hotel_simplify.method_changes_tax') }}</p>
                <select x-model="method" @change="selectMethod()" name="payment_method" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    @include('pos.hotel._payment-methods')
                </select>
                <select x-model="kind" @change="if (kind === 'deposit') { amount = ''; edited = true; } else { selectMethod(); }" name="kind" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    <option value="payment">{{ __('pos.hotel_advance_payment') }}</option>
                    <option value="deposit">{{ __('pos.hotel_security_deposit') }}</option>
                </select>
                <button class="px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.save_btn') }}</button>
            </form>
            <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">{{ __('pos.hotel_more_billing') }}</summary>
            @if(\App\Services\HotelAccessService::canManageRooms(auth('pos')->user()))
            <details class="bg-white dark:bg-gray-900 rounded-xl border p-4">
                <summary class="cursor-pointer text-sm font-semibold">{{ __('pos.hotel_refund') }}</summary>
            <form method="POST" action="{{ route('pos.hotel.folio.refund', $stay->id) }}" class="mt-3 space-y-2">
                @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                <h3 class="text-sm font-semibold">{{ __('pos.hotel_refund') }}</h3>
                <input type="number" step="0.01" name="amount" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                <select name="payment_method" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    @include('pos.hotel._payment-methods', ['hotelPayMethods' => ['cash', 'card', 'qr_payment']])
                </select>
                <select name="kind" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                    <option value="payment">{{ __('pos.hotel_advance_payment') }}</option>
                    <option value="deposit">{{ __('pos.hotel_security_deposit') }}</option>
                </select>
                <button class="px-3 py-2 bg-gray-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_refund') }}</button>
            </form>
            </details>
            @endif

            </details>
        </div>
    </div>

    <div class="tn-table-shell bg-white dark:bg-gray-900 rounded-xl border overflow-x-auto">
        <table class="min-w-[40rem] w-full text-sm">
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
                        <span class="text-[10px] uppercase font-bold text-gray-500">{{ __('pos.hotel_entry_'.$entry->entry_type) }} · {{ __('pos.hotel_cat_'.($entry->category ?: 'other')) }}</span>
                        <p>{{ $entry->description }}</p>
                        @if($entry->discount_amount > 0)<p class="text-xs text-teal-700">{{ __($entry->category === 'room' ? 'pos.hotel_room_discount' : 'pos.hotel_extra_discount') }}: Rs {{ number_format($entry->discount_amount, 2) }}</p>@endif
                        @if($entry->pos_transaction_id)
                        <a class="text-xs text-teal-800" href="{{ route('pos.receipt', $entry->pos_transaction_id) }}" target="_blank" rel="noopener">{{ __('hotel_bill.issued_receipt') }}</a>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ rtrim(rtrim(number_format($entry->quantity, 3), '0'), '.') }} {{ \App\Services\PosUnitCatalog::label($entry->uom) }}</td>
                    <td class="px-4 py-3 text-right">Rs {{ number_format($entry->amount, 2) }}</td>
                    <td class="px-4 py-3">
                        @if(\App\Services\HotelAccessService::canManageRooms(auth('pos')->user()) && $entry->entry_type === 'charge' && !$entry->pos_transaction_id)
                        <form method="POST" action="{{ route('pos.hotel.folio.reverse', $stay->id) }}">
                            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
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
    @if(!empty($timeline))
    <details class="mt-6 bg-white dark:bg-gray-900 rounded-xl border p-4">
        <summary class="cursor-pointer text-sm font-bold">{{ __('pos.hotel_timeline') }}</summary>
        <ol class="space-y-2 mt-3">
            @foreach($timeline as $row)
            <li class="text-sm text-gray-700 dark:text-gray-200">
                <span class="text-[11px] text-gray-400 font-mono">{{ $row['at'] }}</span>
                <span class="ml-2">{{ $row['label'] }}</span>
            </li>
            @endforeach
        </ol>
    </details>
    @endif
</div>
</x-hotel-layout>
