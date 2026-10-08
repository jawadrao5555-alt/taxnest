<dialog data-hotel-checkin-popup class="w-[calc(100%_-_2rem)] max-w-4xl max-h-[90dvh] overflow-y-auto rounded-xl border p-4 dark:bg-gray-900 dark:text-white backdrop:bg-black/50">
    <button type="button" data-checkin-close class="float-right rounded-lg border px-3 py-2">{{ __('hotel_preview.close') }}</button>
    <p data-checkin-error role="alert" class="clear-both text-red-700"></p>
    <div data-checkin-form></div>
</dialog>
<dialog data-hotel-receipt-popup class="w-[calc(100%_-_2rem)] max-w-3xl max-h-[90dvh] overflow-y-auto rounded-xl border p-4 dark:bg-gray-900 dark:text-white backdrop:bg-black/50">
    <h2 class="font-bold text-xl">{{ __('hotel_bill.issued_receipt') }}</h2>
    <label data-receipt-picker-label hidden class="block mt-2 text-sm">{{ __('hotel_bill.invoices') }}<select data-receipt-picker class="block w-full rounded-lg border p-2 dark:bg-gray-800"></select></label>
    <p data-receipt-status role="status" aria-live="polite" class="my-2 text-sm"></p>
    <iframe data-receipt-frame title="{{ __('hotel_bill.issued_receipt') }}" class="w-full h-[55dvh] border rounded bg-white"></iframe>
    <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" data-receipt-print disabled class="rounded-lg bg-teal-700 text-white px-4 py-2 disabled:opacity-50">{{ __('hotel_bill.print') }}</button>
        <button type="button" data-receipt-browser hidden class="rounded-lg border px-4 py-2">{{ __('hotel_bill.browser_print') }}</button>
        <button type="button" data-receipt-close class="rounded-lg border px-4 py-2">{{ __('hotel_preview.close') }}</button>
    </div>
</dialog>
@php
$hotelDeskPopupConfig = [
    'labels' => __('hotel_bill'), 'failed' => __('hotel_preview.failed'),
    'autoReceipt' => session('hotel_credit_receipt'),
    'create' => route('pos.hotel.stays.create'), 'base' => url('/pos/hotel/stays'),
];
@endphp
<script>
window.hotelDeskPopupConfig = @json($hotelDeskPopupConfig);
</script>
<script src="{{ asset('js/hotel-desk-popups.js') }}?v={{ filemtime(public_path('js/hotel-desk-popups.js')) }}" defer></script>
