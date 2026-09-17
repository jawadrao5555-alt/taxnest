<x-pos-layout>
<div class="tn-page tn-services-page max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 min-w-0" data-service-order="{{ $order->job_number }}">
    <a href="{{ route('pos.service-work-orders.index') }}" class="text-sm text-purple-600">← {{ $profile['noun'] }} Board</a>
    @if(session('success'))<div class="mt-4 p-3 rounded-lg bg-emerald-50 text-emerald-800 text-sm">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="mt-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm">{{ session('error') }}</div>@endif
    <div class="tn-panel mt-4 bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-xl shadow-sm p-5">
        <div class="flex flex-wrap justify-between gap-3"><div><div class="text-xs text-gray-500">{{ $profile['noun'] }}</div><h1 class="text-2xl font-bold dark:text-white">{{ $order->job_number }} · {{ $order->customer_name }}</h1><p class="text-sm text-gray-500">{{ $order->title }}</p></div><span class="h-fit px-3 py-1.5 rounded-full bg-purple-100 text-purple-800 font-bold text-sm">{{ ucwords(str_replace('_', ' ', $order->status)) }}</span></div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 my-6 text-sm"><div><span class="block text-xs text-gray-500">Phone</span>{{ $order->customer_phone ?: '—' }}</div><div><span class="block text-xs text-gray-500">Scheduled</span>{{ $order->scheduled_at?->format('d M Y H:i') ?? 'Walk-in' }}</div><div><span class="block text-xs text-gray-500">Due</span>{{ $order->due_at?->format('d M Y H:i') ?? '—' }}</div><div><span class="block text-xs text-gray-500">Amount</span><strong>Rs {{ number_format((float)$order->total_amount, 2) }}</strong></div></div>
        @if($order->details)<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-4 rounded-lg bg-gray-50 dark:bg-gray-800">@foreach($order->details as $key => $value)<div><span class="block text-xs text-gray-500">{{ $profile['fields'][$key] ?? ucwords($key) }}</span><span class="dark:text-white">{{ $value }}</span></div>@endforeach</div>@endif
        @if($canTransition && ($nextStatuses || \App\Services\PosServiceWorkflowProfiles::canTransition($profile, $order->status, 'cancelled')))
        <form method="POST" action="{{ route('pos.service-work-orders.transition', $order) }}" class="mt-5 flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-end gap-3">@csrf
            <div class="flex-1 min-w-0"><label class="block text-xs font-semibold mb-1 dark:text-gray-200">Update note</label><input name="note" class="w-full min-w-0 rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white"></div>
            @foreach($nextStatuses as $next)<button name="to_status" value="{{ $next }}" class="w-full sm:w-auto px-4 py-2 bg-purple-600 text-white rounded-lg font-bold">Move to {{ ucwords(str_replace('_', ' ', $next)) }}</button>@endforeach
            @if(\App\Services\PosServiceWorkflowProfiles::canTransition($profile, $order->status, 'cancelled'))<button name="to_status" value="cancelled" class="w-full sm:w-auto px-4 py-2 border border-red-300 text-red-700 rounded-lg">Cancel</button>@endif
        </form>@endif
        @if($order->pos_transaction_id)
        <div class="mt-5 p-4 rounded-lg bg-emerald-50 text-emerald-900 text-sm">
            Fiscal sale {{ $order->posTransaction?->invoice_number ?? ('#'.$order->pos_transaction_id) }}
            is linked. Operational reference remains <strong>{{ $order->job_number }}</strong>.
            <a class="underline font-semibold ml-2" href="{{ route('pos.transaction.show', $order->pos_transaction_id) }}">Open bill</a>
        </div>
        @elseif(!empty($canInvoice))
        <form method="POST" action="{{ route('pos.service-work-orders.invoice', $order) }}" class="mt-5 flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-end gap-3 border-t pt-5">@csrf
            <div><label class="block text-xs font-semibold mb-1 dark:text-gray-200">Payment</label>
                <select name="payment_method" class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="debit_card">Debit card</option>
                    <option value="credit_card">Credit card</option>
                    <option value="qr_payment">QR payment</option>
                </select>
            </div>
            <button class="w-full sm:w-auto px-4 py-2 bg-emerald-600 text-white rounded-lg font-bold">Create NestPOS bill</button>
            <p class="w-full text-xs text-gray-500">Creates a fiscal sale with a P/L invoice number. Keeps {{ $order->job_number }} as the operational job reference.</p>
        </form>
        @endif
    </div>
    <div class="mt-5 bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-xl p-5"><h2 class="font-bold dark:text-white mb-3">Timeline</h2><div class="space-y-3">@foreach($order->events as $event)<div class="border-l-2 border-purple-300 pl-3"><div class="text-sm font-semibold dark:text-white">{{ ucwords(str_replace('_', ' ', $event->to_status)) }}</div><div class="text-xs text-gray-500">{{ $event->occurred_at?->format('d M Y H:i') }}{{ $event->note ? ' · '.$event->note : '' }}</div></div>@endforeach</div></div>
</div>
</x-pos-layout>
