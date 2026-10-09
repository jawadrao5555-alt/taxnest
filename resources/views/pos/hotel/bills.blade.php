<x-hotel-layout>
<div class="tn-page tn-hotel-page max-w-7xl mx-auto" data-hotel-billing="1">
    @include('pos.partials.back-link')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div><h1 class="text-2xl font-bold">{{ __('hotel_billing.title') }}</h1><p class="text-sm text-gray-500 mt-1">{{ $dateLabel }}</p></div>
        <div class="flex gap-2">
            <a class="rounded-lg bg-emerald-600 text-white px-4 py-2 text-sm font-semibold" href="{{ route('pos.hotel.folios.csv', array_merge(request()->query(), ['stream' => $stream])) }}">{{ __('pos.download_csv') }}</a>
            <a class="rounded-lg bg-red-600 text-white px-4 py-2 text-sm font-semibold" href="{{ route('pos.hotel.folios.pdf', array_merge(request()->query(), ['stream' => $stream])) }}">{{ __('pos.download_pdf') }}</a>
        </div>
    </div>
    <form method="GET" action="{{ route('pos.hotel.folios') }}" class="rounded-xl border bg-white dark:bg-gray-900 shadow-md p-4 mb-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3" data-hotel-billing-filters="1">
        <label class="text-xs">{{ __('hotel_billing.stream') }}
            <select name="stream" class="mt-1 block w-full rounded-lg border p-2 text-sm">
                @if(auth('pos')->user()->isPosAdmin() && !auth('pos')->user()->posHidesLocalStream())
                <option value="all" @selected($stream === 'all')>{{ __('pos.opt_all_bills') }}</option>
                @endif
                <option value="pra" @selected($stream === 'pra')>{{ __('pos.pra_word') }}</option>
                @if(auth('pos')->user()->isPosAdmin() && !auth('pos')->user()->posHidesLocalStream())
                <option value="local" @selected($stream === 'local')>{{ __('hotel_bill.local') }}</option>
                @endif
            </select>
        </label>
        <label class="text-xs">{{ __('pos.lbl_period') }}
            <select name="period" class="mt-1 block w-full rounded-lg border p-2 text-sm">
                @foreach(['today' => 'opt_today', 'yesterday' => 'opt_yesterday', 'weekly' => 'opt_this_week', 'monthly' => 'opt_this_month', 'last_month' => 'opt_last_month', 'all' => 'opt_all_time'] as $value => $key)
                <option value="{{ $value }}" @selected(request('period', 'today') === $value)>{{ __('pos.'.$key) }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs">{{ __('pos.hotel_guest') }}<input name="customer" value="{{ request('customer') }}" class="mt-1 block w-full rounded-lg border p-2 text-sm"></label>
        <label class="text-xs">{{ __('pos.hotel_room') }}<input name="room" value="{{ request('room') }}" class="mt-1 block w-full rounded-lg border p-2 text-sm"></label>
        <label class="text-xs">{{ __('pos.th_pos_invoice_no') }}<input name="invoice" value="{{ request('invoice') }}" class="mt-1 block w-full rounded-lg border p-2 text-sm"></label>
        <label class="text-xs">{{ __('pos.lbl_bill_type') }}
            <select name="bill_type" class="mt-1 block w-full rounded-lg border p-2 text-sm">
                @foreach(['' => 'opt_all_bills', 'sales' => 'opt_sales_only', 'returns' => 'opt_credit_notes_only'] as $value => $key)
                <option value="{{ $value }}" @selected(request('bill_type', '') === $value)>{{ __('pos.'.$key) }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs">{{ __('pos.pra_word') }}
            <select name="pra_status" class="mt-1 block w-full rounded-lg border p-2 text-sm">
                <option value="">{{ __('hotel_billing.all_statuses') }}</option>
                @foreach(['submitted', 'pending', 'failed', 'offline', 'local'] as $status)
                <option value="{{ $status }}" @selected(request('pra_status') === $status)>{{ __('hotel_preview.status_'.$status) }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs">{{ __('hotel_billing.payment_state') }}
            <select name="payment_state" class="mt-1 block w-full rounded-lg border p-2 text-sm">
                <option value="">{{ __('hotel_billing.all_statuses') }}</option>
                <option value="paid" @selected(request('payment_state') === 'paid')>{{ __('hotel_billing.paid') }}</option>
                <option value="due" @selected(request('payment_state') === 'due')>{{ __('hotel_billing.due') }}</option>
            </select>
        </label>
        <label class="text-xs">{{ __('pos.lbl_payment_method') }}
            <select name="payment_method" class="mt-1 block w-full rounded-lg border p-2 text-sm">
                <option value="">{{ __('pos.opt_all_methods') }}</option>
                <option value="cash" @selected(request('payment_method') === 'cash')>{{ __('pos.pm_cash') }}</option>
                <option value="card" @selected(in_array(request('payment_method'), \App\Support\PosPaymentLabels::CARD_ALIASES, true))>{{ __('pos.pm_card') }}</option>
                <option value="qr_payment" @selected(request('payment_method') === 'qr_payment')>{{ __('pos.pm_online') }}</option>
            </select>
        </label>
        <label class="text-xs">{{ __('pos.lbl_tax_rate') }}
            <select name="tax_rate" class="mt-1 block w-full rounded-lg border p-2 text-sm">
                <option value="">{{ __('pos.opt_all_taxes') }}</option>
                @foreach($availableRates as $rate)
                <option value="{{ $rate }}" @selected(request()->filled('tax_rate') && request('tax_rate') !== 'exempt' && (float) request('tax_rate') === (float) $rate)>{{ $rate }}%</option>
                @endforeach
                <option value="exempt" @selected(request('tax_rate') === 'exempt')>{{ __('pos.opt_exempt_items_only') }}</option>
            </select>
        </label>
        <label class="text-xs">{{ __('pos.lbl_date_from') }}<input type="date" name="date_from" value="{{ request('date_from') }}" class="mt-1 block w-full rounded-lg border p-2 text-sm"></label>
        <label class="text-xs">{{ __('pos.lbl_date_to') }}<input type="date" name="date_to" value="{{ request('date_to') }}" class="mt-1 block w-full rounded-lg border p-2 text-sm"></label>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
            <button class="rounded-lg bg-blue-600 text-white px-4 py-2 text-sm font-semibold">{{ __('pos.apply_filters') }}</button>
            <a class="rounded-lg bg-gray-100 px-4 py-2 text-sm" href="{{ route('pos.hotel.folios') }}">{{ __('pos.clear') }}</a>
        </div>
    </form>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
        @php
            $cards = [
                [__('pos.kpi_total_invoices'), number_format($summary->total_invoices)],
                [__('hotel_billing.net_bills'), 'Rs '.number_format($summary->total_sales, 2)],
                [__('pos.tr_credit_notes'), number_format($summary->return_count ?? 0)],
                [__('pos.kpi_total_tax'), 'Rs '.number_format($summary->total_tax, 2)],
                [__('hotel_billing.stay_paid'), $directory['restricted'] ? '—' : 'Rs '.number_format($directory['paid'], 2)],
                [__('hotel_billing.stay_due'), $directory['restricted'] ? '—' : 'Rs '.number_format($directory['due'], 2)],
            ];
        @endphp
        @foreach($cards as [$label, $value])
        <div class="rounded-xl border bg-white dark:bg-gray-900 p-4 shadow-sm text-center"><p class="text-xs text-gray-500">{{ $label }}</p><p class="text-lg font-bold mt-1">{{ $value }}</p></div>
        @endforeach
    </div>
    <p class="text-xs text-gray-500 mb-4">{{ __('hotel_billing.balance_help') }}</p>
    <div class="flex justify-between items-center mb-3">
        <a href="{{ route('pos.hotel.stays.index') }}" class="text-sm underline">{{ __('hotel_billing.unbilled') }}</a>
        <button type="button" data-hotel-tax-toggle aria-expanded="false" class="rounded-lg border px-3 py-2 text-sm">{{ __('hotel_billing.tax_details') }}</button>
    </div>
    <div class="rounded-xl border bg-white dark:bg-gray-900 shadow-md overflow-hidden">
        <div class="overflow-x-auto" data-tax-report-scroll="1">
            @include('pos.hotel._billing-table', ['billingPdf' => false])
        </div>
    </div>
    <div class="mt-4">{{ $transactions->links() }}</div>
</div>
<script>
(() => {
    const root = document.querySelector('[data-hotel-billing]');
    const button = root.querySelector('[data-hotel-tax-toggle]');
    button.addEventListener('click', () => {
        const open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(open));
        root.querySelectorAll('[data-hotel-tax-detail]').forEach(cell => { cell.hidden = !open; });
    });
    const form = root.querySelector('form');
    form.elements.period.addEventListener('change', () => { form.elements.date_from.value = ''; form.elements.date_to.value = ''; });
    for (const key of ['date_from', 'date_to']) form.elements[key].addEventListener('change', () => { if(form.elements[key].value) form.elements.period.value = 'all'; });
})();
</script>
</x-hotel-layout>
