<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $transaction->invoice_number }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        @media print { body { width: 80mm; } .no-print { display: none !important; } }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
            font-size: 12px;
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 5mm 4mm;
            background: #fff;
            color: #1a1a1a;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        .receipt-header { text-align: center; padding-bottom: 10px; }
        .company-name { font-size: 18px; font-weight: 800; letter-spacing: -0.3px; color: #111; margin-bottom: 2px; }
        .company-meta { font-size: 10px; color: #666; line-height: 1.6; }
        .company-ntn { font-size: 10px; font-weight: 700; color: #333; margin-top: 2px; }
        .divider { height: 1px; background: linear-gradient(90deg, transparent, #ddd 20%, #ddd 80%, transparent); margin: 8px 0; }
        .divider-bold { height: 2px; background: linear-gradient(90deg, transparent, #222 15%, #222 85%, transparent); margin: 10px 0; }
        .divider-dotted { border-bottom: 1px dotted #ccc; margin: 8px 0; }
        .invoice-number { font-size: 14px; font-weight: 800; text-align: center; letter-spacing: 0.5px; color: #111; }
        .badge-row { text-align: center; margin: 6px 0; }
        .badge { display: inline-block; padding: 3px 10px; border: 1.5px solid #333; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 1.5px; border-radius: 4px; margin: 2px 3px; color: #333; }
        .meta-row { display: flex; justify-content: space-between; align-items: center; font-size: 10px; color: #555; padding: 1px 0; }
        .meta-label { font-weight: 600; color: #333; }
        .customer-block { background: #f8f8f8; border-radius: 6px; padding: 6px 8px; margin: 6px 0; font-size: 10px; }
        .customer-block .name { font-weight: 700; color: #111; font-size: 11px; }
        .customer-block .detail { color: #666; margin-top: 1px; }
        .items-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .items-table th { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #888; padding: 4px 0; border-bottom: 1.5px solid #999; }
        .items-table .col-qty { text-align: center; width: 10%; }
        .items-table .col-item { text-align: left; width: 55%; }
        .items-table .col-rate { text-align: right; width: 15%; }
        .items-table .col-amt { text-align: right; width: 20%; }
        .items-table td { padding: 4px 2px; vertical-align: top; font-size: 11px; }
        .items-table td.col-qty { font-weight: 700; color: #333; text-align: center; }
        .items-table td.col-item { font-weight: 600; color: #111; }
        .items-table td.col-rate { color: #666; font-size: 10px; text-align: right; }
        .items-table td.col-amt { font-weight: 700; color: #111; font-variant-numeric: tabular-nums; white-space: nowrap; text-align: right; }
        .items-table .tax-exempt { font-size: 8px; color: #059669; font-weight: 600; background: #ecfdf5; padding: 1px 4px; border-radius: 3px; }
        .item-row { border-bottom: 1px dotted #ddd; }
        .item-row:last-child { border-bottom: none; }
        .totals-section { padding: 4px 0; }
        .total-row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 11px; }
        .total-row .label { color: #555; }
        .total-row .value { font-weight: 700; color: #333; font-variant-numeric: tabular-nums; }
        .total-row.discount .label, .total-row.discount .value { color: #c2410c; }
        .total-row.exempt .label, .total-row.exempt .value { color: #059669; font-size: 10px; }
        .grand-total { display: flex; justify-content: space-between; padding: 8px 0 4px; border-top: 2px solid #111; margin-top: 4px; }
        .grand-total .label { font-size: 15px; font-weight: 800; color: #111; }
        .grand-total .value { font-size: 15px; font-weight: 800; color: #111; font-variant-numeric: tabular-nums; }
        .qr-section { text-align: center; margin: 12px 0 8px; }
        .qr-section img { width: 90px; height: 90px; border-radius: 6px; border: 1px solid #eee; padding: 4px; }
        .footer-stats { text-align: center; font-size: 10px; color: #666; margin: 4px 0; }
        .footer-message { text-align: center; padding: 8px 0 4px; }
        .footer-message .thanks { font-size: 12px; font-weight: 700; color: #111; }
        .footer-message .powered { font-size: 8px; color: #bbb; margin-top: 4px; letter-spacing: 1px; text-transform: uppercase; }
        .logo-area { text-align: center; margin-bottom: 8px; padding-top: 2px; }
        .logo-area img { max-height: 50px; max-width: 60mm; width: auto; }
        .pra-info { text-align: center; font-size: 10px; font-weight: 700; color: #333; background: #f0f0f0; padding: 4px 8px; border-radius: 4px; margin: 4px 0; }
        .print-btn-row { display: flex; gap: 6px; margin-top: 12px; }
        .print-btn-row button, .print-btn-row a { flex: 1; padding: 12px; text-align: center; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; display: block; transition: all 0.15s; }
        .btn-print { background: linear-gradient(135deg, #7c3aed, #6d28d9); color: #fff; box-shadow: 0 4px 12px rgba(124,58,237,0.3); }
        .btn-print:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(124,58,237,0.4); }
        .btn-kot { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; box-shadow: 0 4px 12px rgba(245,158,11,0.3); }
        .btn-close { background: #f3f4f6; color: #374151; }
        .btn-close:hover { background: #e5e7eb; }
    </style>
</head>
<body>
    @if($company->logo)
    <div class="logo-area">
        <img src="{{ asset('storage/logos/' . $company->logo) }}" alt="{{ $company->name }}" onerror="this.style.display='none'">
    </div>
    @endif

    <div class="receipt-header">
        <p class="company-name">{{ $company->name ?? 'Restaurant' }}</p>
        <div class="company-meta">
            @if($company->address)<p>{{ $company->address }}</p>@endif
            @if($company->phone)<p>Tel: {{ $company->phone }}</p>@endif
        </div>
        @if($company->ntn)<p class="company-ntn">NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="divider-bold"></div>

    <p class="invoice-number">{{ $transaction->invoice_number }}</p>
    <div class="badge-row">
        <span class="badge">{{ strtoupper($transaction->payment_method ?? 'CASH') }}</span>
        @if($order && $order->order_type)
            <span class="badge">{{ strtoupper(str_replace('_', ' ', $order->order_type)) }}</span>
        @endif
    </div>

    <div class="divider"></div>

    <div class="meta-row">
        <span>{{ $transaction->created_at->format('M d, Y') }}</span>
        <span class="meta-label">{{ $transaction->created_at->format('h:i A') }}</span>
    </div>

    @if($order && $order->table)
    <div class="meta-row">
        <span class="meta-label">Table: T-{{ $order->table->table_number }}</span>
        <span>{{ $order->table->seats }} seats</span>
    </div>
    @endif

    @if($transaction->customer_name)
    <div class="customer-block">
        <p class="name">{{ $transaction->customer_name }}</p>
        @if($transaction->customer_phone)<p class="detail">{{ $transaction->customer_phone }}</p>@endif
    </div>
    @endif

    <div style="font-size: 9px; color: #999; margin: 2px 0;">
        Cashier: {{ $transaction->creator->name ?? 'Staff' }}
    </div>

    <div class="divider-bold"></div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-qty">Qty</th>
                <th class="col-item">Item</th>
                <th class="col-rate">Rate</th>
                <th class="col-amt">Amt</th>
            </tr>
        </thead>
        <tbody>
        @foreach($transaction->items as $item)
        <tr class="item-row">
            <td class="col-qty">{{ number_format($item->quantity, $item->quantity == intval($item->quantity) ? 0 : 2) }}</td>
            <td class="col-item">{{ $item->item_name }}@if($item->is_tax_exempt) <span class="tax-exempt">NT</span>@endif</td>
            <td class="col-rate">{{ number_format($item->unit_price) }}</td>
            <td class="col-amt">{{ number_format($item->subtotal) }}</td>
        </tr>
        @endforeach
        </tbody>
    </table>

    <div class="divider-bold"></div>

    <div class="totals-section">
        <div class="total-row">
            <span class="label">Subtotal</span>
            <span class="value">Rs. {{ number_format($transaction->subtotal, 2) }}</span>
        </div>
        @if($transaction->discount_amount > 0)
        <div class="total-row discount">
            <span class="label">Discount @if($transaction->discount_type === 'percentage')({{ $transaction->discount_value }}%)@endif</span>
            <span class="value">-Rs. {{ number_format($transaction->discount_amount, 2) }}</span>
        </div>
        @endif
        @if($transaction->exempt_amount > 0)
        <div class="total-row exempt">
            <span class="label">Tax-Exempt Items</span>
            <span class="value">Rs. {{ number_format($transaction->exempt_amount, 2) }}</span>
        </div>
        @endif
        <div class="total-row">
            <span class="label">Tax ({{ $transaction->tax_rate ?? 0 }}%)</span>
            <span class="value">Rs. {{ number_format($transaction->tax_amount, 2) }}</span>
        </div>
        <div class="grand-total">
            <span class="label">TOTAL</span>
            <span class="value">Rs. {{ number_format($transaction->total_amount, 2) }}</span>
        </div>
    </div>

    <div class="divider"></div>

    @if($transaction->invoice_mode === 'pra' && $transaction->pra_invoice_number)
    <div class="pra-info">
        PRA Invoice: {{ $transaction->pra_invoice_number }}
    </div>
    <div class="divider"></div>
    @endif

    <div class="qr-section">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data={{ urlencode($transaction->invoice_number . '|Rs.' . number_format($transaction->total_amount, 2) . '|' . $transaction->created_at->toIso8601String()) }}" alt="QR Code" onerror="this.style.display='none'">
    </div>

    <div class="footer-stats">
        <p>{{ $transaction->items->count() }} item(s) &middot; {{ number_format($transaction->items->sum('quantity'), 0) }} unit(s)</p>
    </div>

    <div class="divider-dotted"></div>

    <div class="footer-message">
        <p class="thanks">Thank you for dining with us!</p>
        <p class="powered">Powered by TaxNest</p>
    </div>

    <div class="no-print print-btn-row">
        <button class="btn-print" onclick="handlePrint()">Print Receipt</button>
        @if($order)
        <a href="{{ route('pos.restaurant.kitchen-ticket', $order->id) }}" class="btn-kot" target="_blank">KOT</a>
        @endif
        <button class="btn-close" onclick="window.close()">Close</button>
    </div>

    <script>
        let hasPrinted = false;
        const txnId = {{ $transaction->id }};
        function markPrinted() {
            fetch('/pos/restaurant/api/receipt-printed/' + txnId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            }).catch(function(){});
        }
        function handlePrint() {
            if (hasPrinted && !confirm('This receipt has already been printed (reprint #{{ ($transaction->reprint_count ?? 0) + 1 }}). Print again?')) return;
            hasPrinted = true;
            markPrinted();
            window.print();
        }
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_print') === '1' && !hasPrinted) {
                hasPrinted = true;
                markPrinted();
                setTimeout(function() { window.print(); }, 500);
            }
        };
    </script>
</body>
</html>
