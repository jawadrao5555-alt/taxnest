<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #193430; font-size: 12px; }
        h1 { font-size: 23px; margin: 4px 0; }
        .brand { font-size: 18px; font-weight: bold; color: #006b61; }
        .muted { color: #536961; }
        .box { border: 1px solid #ccd7d7; padding: 12px; margin: 15px 0; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; }
        td { border-bottom: 1px solid #dde8e4; padding: 8px 4px; }
        .amount { text-align: right; } .total { font-weight: bold; font-size: 16px; }
    </style>
</head>
<body>
@php
    $historical = $historical ?? false;
    $purchase = $snapshot['purchase'] ?? data_get($invoice->document_snapshot, 'purchase') ?? $invoice->subscription?->purchase_snapshot ?? [];
    $purchaseKind = $snapshot['kind'] ?? 'subscription';
    $purchaseLabel = data_get($purchase, 'plan.name') ?? ($purchase['label'] ?? null) ?? $invoice->subscription?->pricingPlan?->name ?? 'Purchase details unavailable';
    $cycle = data_get($purchase, 'price.cycle') ?? data_get($purchase, 'cycle');
    if ($purchaseKind === 'subscription') {
        $cycle ??= $invoice->subscription?->billing_cycle;
    }
    $periodStart = data_get($purchase, 'period.start');
    $periodEnd = data_get($purchase, 'period.end');
    if ($historical) {
        $periodStart ??= $invoice->subscription?->start_date?->toDateString();
        $periodEnd ??= $invoice->subscription?->end_date?->toDateString();
    }
    $method = $snapshot['payment_method'] ?? $payment?->payment_method;
    $received = $snapshot['verified_received'] ?? $payment?->amount;
@endphp
<div class="brand">TaxNest</div>
<h1>{{ $kind === 'receipt' ? 'Payment Receipt' : ($purchaseKind === 'subscription' ? 'Subscription Invoice' : 'Add-on Invoice') }}</h1>
<p class="muted">{{ $historical ? 'Historical payment record' : 'Document' }} {{ $number }}</p>
<div class="box">
    <strong>{{ $kind === 'receipt' ? 'Received from' : 'Billed to' }}</strong>
    {{ data_get($snapshot, 'company.name') ?? $invoice->company?->name ?? 'Company name unavailable' }}<br>
    Product: {{ data_get($snapshot, 'company.product_type') ?? $invoice->company?->product_type ?? 'Not recorded' }}<br>
    {{ $purchaseKind === 'subscription' ? 'Package' : 'Purchase' }}: {{ $purchaseLabel }}
    @if($cycle) · {{ ucwords(str_replace('_', ' ', $cycle)) }} @endif
    @if($periodStart || $periodEnd)<br>Period: {{ $periodStart ?? 'Date unavailable' }} to {{ $periodEnd ?? 'Date unavailable' }}@endif
    @if($kind === 'receipt')
        <br>Payment method: <strong>{{ filled($method) ? ucwords(str_replace('_', ' ', $method)) : 'Not recorded' }}</strong>
        <br>{{ isset($snapshot['payment_date']) ? 'Payment date' : 'Recorded payment date' }}: {{ $snapshot['payment_date'] ?? $payment?->paid_at?->format('d M Y') ?? 'Not recorded' }}
        <br>Transaction reference: {{ ($snapshot['transaction_ref'] ?? $payment?->transaction_ref) ?: 'Not recorded' }}
        <br>Invoice: {{ $snapshot['invoice_number'] ?? $invoice->document_number ?? ('Charge #'.$invoice->getKey()) }}
    @endif
    @if(data_get($snapshot, 'review.at'))<br>Verified on: {{ data_get($snapshot, 'review.at') }}@endif
</div>
<table>
    @unless($historical)
        <tr><td>Invoice amount</td><td class="amount">PKR {{ number_format((float) ($snapshot['amount_due'] ?? $invoice->amount), 2) }}</td></tr>
    @endunless
    @if($received !== null)
        <tr class="total"><td>{{ $historical ? 'Recorded payment received' : 'Verified payment received' }}</td><td class="amount">PKR {{ number_format((float) $received, 2) }}</td></tr>
    @endif
    @isset($snapshot['allocated'])<tr><td>Allocated to this invoice</td><td class="amount">PKR {{ number_format((float) $snapshot['allocated'], 2) }}</td></tr>@endisset
    @isset($snapshot['remaining_balance'])<tr><td>Remaining balance</td><td class="amount">PKR {{ number_format((float) $snapshot['remaining_balance'], 2) }}</td></tr>@endisset
    @isset($snapshot['unallocated_excess'])<tr><td>Unallocated excess (evidence only; not an automatic credit/refund)</td><td class="amount">PKR {{ number_format((float) $snapshot['unallocated_excess'], 2) }}</td></tr>@endisset
</table>
<p class="muted">{{ $historical ? 'Source: historical TaxNest payment ledger. The document reference identifies the recorded payment.' : 'Issued by TaxNest for this billing transaction.' }}</p>
</body>
</html>
