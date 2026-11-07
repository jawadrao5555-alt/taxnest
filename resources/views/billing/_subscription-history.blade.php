@if($billingHistory !== null)
<section class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-5 mt-8" aria-labelledby="subscription-document-history">
    <h3 id="subscription-document-history" class="text-lg font-semibold text-gray-900 dark:text-gray-100">Billing history</h3>
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Verified subscription invoices and receipts. A remaining balance or excess is shown as recorded; excess is not an automatic credit or refund.</p>
    <div class="overflow-x-auto mt-4" role="region" tabindex="0" aria-label="Subscription billing history">
        <table class="w-full text-sm">
            <thead><tr class="text-left border-b border-gray-200 dark:border-gray-700"><th class="p-2">Invoice / plan</th><th class="p-2">Period</th><th class="p-2">Due / received</th><th class="p-2">Balance / excess</th><th class="p-2">Documents</th></tr></thead>
            <tbody>
            @forelse($billingHistory as $document)
                @php
                    $snapshot = $document->document_snapshot ?? [];
                    $purchase = $snapshot['purchase'] ?? [];
                    $plan = $purchase['plan']['name'] ?? $document->subscription?->pricingPlan?->name ?? 'Plan record unavailable';
                    $period = $purchase['period'] ?? [];
                @endphp
                <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                    <td class="p-2"><span class="font-semibold">{{ $document->document_number }}</span><br><span class="text-xs text-gray-500">{{ $plan }} · {{ str_replace('_', ' ', $document->status) }}</span></td>
                    <td class="p-2 whitespace-nowrap">{{ $period['start'] ?? 'Not recorded' }}<br>{{ $period['end'] ?? 'Not recorded' }}</td>
                    <td class="p-2">PKR {{ number_format((float) ($snapshot['amount_due'] ?? $document->amount), 2) }}<br><span class="text-xs text-gray-500">PKR {{ number_format((float) ($snapshot['verified_received'] ?? 0), 2) }}</span></td>
                    <td class="p-2">PKR {{ number_format((float) ($snapshot['remaining_balance'] ?? 0), 2) }}<br><span class="text-xs text-gray-500">Excess PKR {{ number_format((float) ($snapshot['unallocated_excess'] ?? 0), 2) }}</span></td>
                    <td class="p-2 whitespace-nowrap">
                        <a class="font-semibold underline" href="{{ route($billingDocumentRoutes['invoice'], ['invoice' => $document->id]) }}">Invoice PDF</a>
                        @foreach($document->payments as $recordedPayment)
                            <br><a class="font-semibold underline" href="{{ route($billingDocumentRoutes['receipt'], ['payment' => $recordedPayment->id]) }}">Receipt {{ $recordedPayment->receipt_number }}</a>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-3 text-gray-500">No verified subscription documents recorded yet. Legacy records remain unknown and are not invented.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $billingHistory->links() }}</div>
</section>
@endif
