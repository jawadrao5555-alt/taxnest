<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;color:#172b2b;font-size:12px}h1{font-size:22px;margin:0}.muted{color:#667}.box{border:1px solid #ccd7d7;border-radius:6px;padding:12px;margin-top:14px}.grid{width:100%;border-collapse:collapse}.grid td,.grid th{border-bottom:1px solid #e2e8e8;padding:7px;text-align:left}.right{text-align:right}.brand{color:#006b61;font-weight:bold}.total{font-size:16px;font-weight:bold}
</style></head><body>
<div class="brand">TaxNest</div><h1>{{ $kind === 'receipt' ? 'Payment Receipt' : 'Subscription Invoice' }}</h1>
<p class="muted">Document {{ $number }} · PKR · {{ data_get($snapshot, 'review.at', 'Date not recorded') }}</p>
<div class="box"><strong>{{ data_get($snapshot, 'company.name', $invoice->company?->name ?? 'Company record unavailable') }}</strong><br>Product: {{ data_get($snapshot, 'company.product_type', $invoice->company?->product_type ?? 'Not recorded') }}<br>Subscription #{{ $invoice->subscription_id }}</div>
<table class="grid box">
<tr><th>Description</th><th class="right">Amount</th></tr>
<tr><td>{{ data_get($snapshot, 'purchase.plan.name', $invoice->subscription?->pricingPlan?->name ?? 'Subscription') }}<br><span class="muted">{{ data_get($snapshot, 'purchase.period.start', 'Period not recorded') }} to {{ data_get($snapshot, 'purchase.period.end', 'Period not recorded') }}</span></td><td class="right">PKR {{ number_format((float) data_get($snapshot, 'amount_due', $invoice->amount), 2) }}</td></tr>
<tr><td>Verified payment received</td><td class="right">PKR {{ number_format((float) data_get($snapshot, 'verified_received', 0), 2) }}</td></tr>
<tr><td>Allocated to this invoice</td><td class="right">PKR {{ number_format((float) data_get($snapshot, 'allocated', 0), 2) }}</td></tr>
<tr><td class="total">Remaining balance</td><td class="right total">PKR {{ number_format((float) data_get($snapshot, 'remaining_balance', 0), 2) }}</td></tr>
<tr><td>Unallocated excess (evidence only; not an automatic credit/refund)</td><td class="right">PKR {{ number_format((float) data_get($snapshot, 'unallocated_excess', 0), 2) }}</td></tr>
</table>
@if($kind === 'receipt')<div class="box">Payment method: {{ data_get($snapshot, 'payment_method', 'Not recorded') }}<br>Transaction reference: {{ data_get($snapshot, 'transaction_ref', 'Not recorded') }}<br>Invoice: {{ data_get($snapshot, 'invoice_number', $invoice->document_number) }}</div>@endif
<p class="muted">This document reflects the verified TaxNest subscription record. It is not a shop sales receipt or regulator invoice.</p>
</body></html>
