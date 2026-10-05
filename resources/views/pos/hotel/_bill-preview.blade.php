<dialog id="hotel-bill-preview" data-hotel-bill-preview="1" class="w-[calc(100%_-_2rem)] max-w-lg max-h-[90vh] overflow-y-auto rounded-xl border p-5 dark:bg-gray-900 dark:text-white backdrop:bg-black/50">
    <h2 class="text-xl font-bold">{{ __('hotel_preview.title') }}</h2>
    <div data-preview-desk-controls hidden class="mt-3 grid sm:grid-cols-2 gap-3 text-sm">
        <label>{{ __('hotel_preview.action') }}<select data-desk-flow class="block w-full rounded border p-2"><option value="collect">{{ __('hotel_preview.collect_bill') }}</option><option value="checkout">{{ __('pos.hotel_check_out_btn') }}</option></select></label>
        <label>{{ __('pos.hotel_payment_method') }}<select data-desk-method class="block w-full rounded border p-2">@include('pos.hotel._payment-methods', ['hotelPayMethods' => ['cash', 'card', 'qr_payment']])</select></label>
        <label>{{ __('pos.hotel_collect_now') }}<input data-desk-amount type="number" min="0" step="0.01" class="block w-full rounded border p-2"></label>
        <label data-desk-balance-label hidden><input data-desk-balance type="checkbox"> {{ __('pos.hotel_leave_balance') }}</label>
        <button type="button" data-desk-update class="rounded-lg border p-2">{{ __('hotel_preview.update') }}</button>
    </div>
    <p data-preview-meta class="mt-2 font-semibold"></p>
    <p class="text-sm mt-2" data-preview-note>{{ __('hotel_preview.draft') }}</p>
    <div data-preview-lines class="my-3 divide-y text-sm"></div>
    <dl data-preview-totals class="grid grid-cols-2 gap-2 text-sm"></dl>
    <p data-preview-error role="alert" class="mt-3 text-red-700"></p>
    <div data-preview-result hidden class="mt-3 space-y-2">
        <p data-preview-status role="status"></p>
        <p data-preview-number class="font-bold"></p>
        <img data-preview-qr hidden alt="PRA QR" class="w-32 h-32">
        <a data-preview-receipt hidden target="_blank" rel="noopener" class="block rounded-lg bg-teal-700 p-3 text-center text-white">{{ __('hotel_preview.print') }}</a>
        <button type="button" data-preview-refresh class="underline">{{ __('hotel_preview.refresh') }}</button>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <button type="button" data-preview-back class="rounded-lg border p-3">{{ __('hotel_preview.back') }}</button>
        <button type="button" data-preview-confirm class="rounded-lg bg-teal-700 text-white p-3 disabled:opacity-50"></button>
        <button type="button" data-preview-checkout hidden class="rounded-lg bg-teal-700 text-white p-3">{{ __('pos.hotel_check_out_btn') }}</button>
        <a data-preview-stay hidden class="rounded-lg border p-3">{{ __('hotel_preview.stay') }}</a>
    </div>
</dialog>
<script>
window.hotelBillPreviewLabels = @json(__('hotel_preview'));
</script>
<script src="{{ asset('js/hotel-bill-preview.js') }}?v={{ filemtime(public_path('js/hotel-bill-preview.js')) }}" defer></script>
