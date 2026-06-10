<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 12px; margin: 0; }
        .head { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 14px; }
        .head h1 { font-size: 18px; margin: 0; }
        .head .sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .meta { margin-bottom: 14px; font-size: 12px; }
        .meta b { display: inline-block; min-width: 90px; color: #374151; }
        .kpis { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        .kpis td { width: 33%; border: 1px solid #e5e7eb; padding: 8px 10px; }
        .kpis .lbl { font-size: 9px; color: #6b7280; text-transform: uppercase; }
        .kpis .val { font-size: 15px; font-weight: bold; }
        table.tx { width: 100%; border-collapse: collapse; }
        table.tx th { background: #f3f4f6; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; color: #4b5563; border-bottom: 1px solid #d1d5db; }
        table.tx td { padding: 6px 8px; border-bottom: 1px solid #eee; }
        table.tx .r { text-align: right; }
        .tot { font-weight: bold; }
        .foot { margin-top: 16px; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <h1>Customer Purchase History</h1>
        <div class="sub">{{ $company->name ?? '' }} · Generated {{ now()->format('d M Y, H:i') }}</div>
    </div>

    <div class="meta">
        <div><b>Customer:</b> {{ $customer->name }}</div>
        <div><b>Phone:</b> {{ $customer->phone ?: '—' }}</div>
        @if($customer->cnic)<div><b>CNIC:</b> {{ $customer->cnic }}</div>@endif
        @if($customer->city)<div><b>City:</b> {{ $customer->city }}</div>@endif
        <div><b>Type:</b> {{ ucfirst($customer->type) }}</div>
    </div>

    <table class="kpis">
        <tr>
            <td><div class="lbl">Total Orders</div><div class="val">{{ number_format($totalOrders) }}</div></td>
            <td><div class="lbl">Total Spent</div><div class="val">PKR {{ number_format($totalSpent, 0) }}</div></td>
            <td><div class="lbl">Avg. Order</div><div class="val">PKR {{ number_format($totalOrders > 0 ? $totalSpent / $totalOrders : 0, 0) }}</div></td>
        </tr>
    </table>

    <table class="tx">
        <thead>
            <tr>
                <th>Date</th>
                <th>Invoice #</th>
                <th>Mode</th>
                <th>Payment</th>
                <th class="r">Subtotal</th>
                <th class="r">Discount</th>
                <th class="r">Tax</th>
                <th class="r">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $t)
            <tr>
                <td>{{ $t->created_at->format('d M Y H:i') }}</td>
                <td>{{ $t->invoice_number }}</td>
                <td>{{ $t->invoice_mode === 'local' ? 'Local' : 'PRA' }}</td>
                <td>{{ ucwords(str_replace('_', ' ', $t->payment_method)) }}</td>
                <td class="r">{{ number_format($t->subtotal, 0) }}</td>
                <td class="r">{{ number_format($t->discount_amount, 0) }}</td>
                <td class="r">{{ number_format($t->tax_amount, 0) }}</td>
                <td class="r">{{ number_format($t->total_amount, 0) }}</td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center; padding:20px; color:#9ca3af;">No purchase history found.</td></tr>
            @endforelse
        </tbody>
        @if($transactions->count())
        <tfoot>
            <tr class="tot">
                <td colspan="7" class="r">TOTAL</td>
                <td class="r">PKR {{ number_format($totalSpent, 0) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>

    <div class="foot">History matches sales by linked customer or phone number. NestPOS (PRA) — confidential.</div>
</body>
</html>
