@php
    $printSetup=$company->printerSettings();
    $assignedUid=auth('pos')->user()?->pos_device_uid;
    $printDevice=$assignedUid && \App\Http\Controllers\AgentController::deviceRoutingReady()
        ? \App\Models\PosAgentDevice::where('company_id',$company->id)->where('device_uid',$assignedUid)->first() : null;
    $counterReady=$printDevice && $printDevice->isOnline() && !empty($printDevice->receipt_printer);
    $receiptDestination=$assignedUid ? ($counterReady ? $printDevice->receipt_printer : null) : ($printSetup['receipt_printer'] ?? null);
@endphp
<section data-saved-print-summary class="mb-5 rounded-xl border border-gray-300 dark:border-gray-600 p-4 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-gray-100">
    <h2 class="font-bold mb-2">{{ __('pos.saved_print_setup') }}</h2>
    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <div><dt class="font-semibold">{{ __('pos.saved_receipt_destination') }}</dt><dd>{{ $receiptDestination ?: __('pos.saved_no_receipt_destination') }}</dd></div>
        <div><dt class="font-semibold">{{ __('pos.saved_kitchen_default') }}</dt><dd>{{ $printSetup['kot_printer'] ?: __('pos.saved_no_kitchen_destination') }}</dd></div>
        <div><dt class="font-semibold">{{ __('pos.saved_print_mode') }}</dt><dd>{{ $printSetup['silent_print_enabled'] ? __('pos.saved_print_agent') : __('pos.saved_print_browser') }}</dd></div>
        <div><dt class="font-semibold">{{ __('pos.saved_print_ask') }}</dt><dd>{{ $printSetup['print_confirm_ask'] ? __('pos.saved_print_yes') : __('pos.saved_print_no') }}</dd></div>
    </dl>
    @if($assignedUid && !$counterReady)<p class="mt-2 font-semibold">{{ __('pos.saved_assigned_counter_unavailable') }}</p>@endif
    <p class="mt-2">{{ __('pos.saved_kitchen_station_hint') }}</p>
    <p class="mt-2">{{ __('pos.saved_print_paper_hint') }}</p>
    <a href="{{ url('/pos/printer-settings') }}" class="inline-block mt-2 underline font-semibold">{{ __('pos.saved_open_printer_settings') }}</a>
</section>
