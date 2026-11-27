@if($lane === null)
    <p class="text-sm mt-1">Billing records unavailable for this scope.</p>
@else
    <dl class="text-sm space-y-1 mt-1">
        <div class="flex flex-wrap justify-between gap-2">
            <dt>Invoiced · {{ number_format($lane['invoice_count']) }} invoices</dt>
            <dd class="font-semibold">PKR {{ number_format($lane['invoiced'], 2) }}</dd>
        </div>
        <div class="flex flex-wrap justify-between gap-2">
            <dt>{{ $isSubscription ? 'Verified received' : 'Received' }} · {{ number_format($lane['receipt_count']) }} receipts</dt>
            <dd class="font-semibold">PKR {{ number_format($lane['received'], 2) }}</dd>
        </div>
        @unless($isSubscription)
            <div class="flex flex-wrap justify-between gap-2"><dt>{{ $reversalLabel ?? 'Refunds' }}</dt><dd>PKR {{ number_format($lane['refunds'], 2) }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt>Net collections</dt><dd>PKR {{ number_format($lane['net_received'], 2) }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt>Open balance on selected invoices</dt><dd>PKR {{ number_format($lane['outstanding'], 2) }}</dd></div>
        @endunless
    </dl>
@endif
