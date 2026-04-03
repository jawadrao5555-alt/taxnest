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
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Inter', system-ui, sans-serif;
            font-size: 12px;
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 6mm 4mm;
            background: #fff;
            color: #1a1a1a;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }

        .logo-area { text-align: center; margin-bottom: 6px; }
        .logo-area img { max-height: 44px; max-width: 55mm; width: auto; }

        .receipt-header { text-align: center; padding-bottom: 8px; }
        .company-name { font-size: 17px; font-weight: 800; letter-spacing: -0.4px; color: #000; margin-bottom: 1px; }
        .company-meta { font-size: 10px; color: #555; line-height: 1.5; }
        .company-ntn { font-size: 10px; font-weight: 700; color: #222; margin-top: 3px; letter-spacing: 0.3px; }

        .sep { border: none; border-top: 1px solid #ddd; margin: 7px 0; }
        .sep-bold { border: none; border-top: 1.5px solid #111; margin: 8px 0; }
        .sep-dashed { border: none; border-top: 1px dashed #bbb; margin: 7px 0; }
        .sep-double { border: none; border-top: 3px double #111; margin: 8px 0; }

        .invoice-bar { text-align: center; margin: 6px 0; }
        .invoice-number { font-size: 13px; font-weight: 800; letter-spacing: 0.3px; color: #000; }
        .badge-row { margin-top: 5px; }
        .badge { display: inline-block; padding: 2px 8px; border: 1px solid #444; font-weight: 700; font-size: 8px; text-transform: uppercase; letter-spacing: 1.2px; border-radius: 3px; margin: 0 2px; color: #333; }

        .info-section { margin: 5px 0; }
        .info-row { display: flex; justify-content: space-between; align-items: center; font-size: 10px; color: #444; padding: 1.5px 0; }
        .info-row .lbl { font-weight: 600; color: #222; }

        .customer-box { background: #f7f7f7; border-left: 3px solid #333; padding: 5px 8px; margin: 5px 0; font-size: 10px; }
        .customer-box .c-name { font-weight: 700; color: #000; font-size: 11px; }
        .customer-box .c-phone { color: #555; margin-top: 1px; }

        .cashier-line { font-size: 9px; color: #888; margin: 3px 0; }

        .items-table { width: 100%; border-collapse: collapse; margin: 3px 0; }
        .items-header { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #666; border-bottom: 1.5px solid #222; }
        .items-header th { padding: 4px 2px 3px; }
        .col-qty { text-align: center; width: 10%; }
        .col-item { text-align: left; width: 50%; }
        .col-rate { text-align: right; width: 18%; }
        .col-amt { text-align: right; width: 22%; }
        .items-table td { padding: 5px 2px; font-size: 11px; vertical-align: middle; }
        .items-table td.col-qty { font-weight: 700; color: #222; text-align: center; }
        .items-table td.col-item { font-weight: 500; color: #111; }
        .items-table td.col-rate { color: #555; font-size: 10px; text-align: right; font-variant-numeric: tabular-nums; }
        .items-table td.col-amt { font-weight: 700; color: #000; text-align: right; font-variant-numeric: tabular-nums; }
        .item-row { border-bottom: 1px dotted #ddd; }
        .item-row:last-child { border-bottom: none; }
        .tax-exempt-tag { font-size: 7px; color: #059669; font-weight: 700; background: #ecfdf5; padding: 1px 3px; border-radius: 2px; margin-left: 3px; vertical-align: middle; }
        .items-count { font-size: 9px; color: #888; text-align: right; margin-top: 2px; padding-right: 2px; }

        .totals { margin: 4px 0; }
        .total-line { display: flex; justify-content: space-between; padding: 2.5px 0; font-size: 11px; }
        .total-line .t-label { color: #444; font-weight: 500; }
        .total-line .t-value { font-weight: 600; color: #222; font-variant-numeric: tabular-nums; }
        .total-line.discount .t-label, .total-line.discount .t-value { color: #c2410c; }
        .total-line.exempt .t-label, .total-line.exempt .t-value { color: #059669; font-size: 10px; }

        .grand-total-box { background: #f5f5f5; border-top: 2px solid #000; border-bottom: 2px solid #000; padding: 7px 6px; margin: 5px 0; display: flex; justify-content: space-between; align-items: center; }
        .grand-total-box .gt-label { font-size: 14px; font-weight: 800; color: #000; letter-spacing: 0.5px; }
        .grand-total-box .gt-value { font-size: 14px; font-weight: 800; color: #000; font-variant-numeric: tabular-nums; }

        .pra-box { text-align: center; font-size: 10px; font-weight: 700; color: #222; background: #f0f0f0; padding: 5px 8px; border-radius: 4px; margin: 5px 0; border: 1px solid #ddd; }

        .qr-section { text-align: center; margin: 10px 0 6px; }
        .qr-section img { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e5e5e5; padding: 3px; background: #fff; }

        .footer { text-align: center; padding: 6px 0 2px; }
        .footer .thanks { font-size: 11px; font-weight: 700; color: #111; letter-spacing: -0.2px; }
        .footer .powered { font-size: 7px; color: #bbb; margin-top: 4px; letter-spacing: 1.2px; text-transform: uppercase; }

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

    <hr class="sep-double">

    <div class="invoice-bar">
        <p class="invoice-number">{{ $transaction->invoice_number }}</p>
        <div class="badge-row">
            <span class="badge">{{ strtoupper($transaction->payment_method ?? 'CASH') }}</span>
            @if($order && $order->order_type)
                <span class="badge">{{ strtoupper(str_replace('_', ' ', $order->order_type)) }}</span>
            @endif
        </div>
    </div>

    <hr class="sep">

    <div class="info-section">
        <div class="info-row">
            <span>{{ $transaction->created_at->format('d M Y') }}</span>
            <span class="lbl">{{ $transaction->created_at->format('h:i A') }}</span>
        </div>
        @if($order && $order->table)
        <div class="info-row">
            <span class="lbl">Table T-{{ $order->table->table_number }}</span>
            <span>{{ $order->table->seats }} seats</span>
        </div>
        @endif
    </div>

    @if($transaction->customer_name)
    <div class="customer-box">
        <p class="c-name">{{ $transaction->customer_name }}</p>
        @if($transaction->customer_phone)<p class="c-phone">{{ $transaction->customer_phone }}</p>@endif
    </div>
    @endif

    <p class="cashier-line">Cashier: {{ $transaction->creator->name ?? 'Staff' }}</p>

    <hr class="sep-bold">

    <table class="items-table">
        <thead>
            <tr class="items-header">
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
            <td class="col-item">{{ $item->item_name }}@if($item->is_tax_exempt)<span class="tax-exempt-tag">NT</span>@endif</td>
            <td class="col-rate">{{ number_format($item->unit_price) }}</td>
            <td class="col-amt">{{ number_format($item->subtotal) }}</td>
        </tr>
        @endforeach
        </tbody>
    </table>
    <p class="items-count">{{ $transaction->items->count() }} item(s) &middot; {{ number_format($transaction->items->sum('quantity'), 0) }} qty</p>

    <hr class="sep-bold">

    <div class="totals">
        <div class="total-line">
            <span class="t-label">Subtotal</span>
            <span class="t-value">Rs. {{ number_format($transaction->subtotal, 2) }}</span>
        </div>
        @if($transaction->discount_amount > 0)
        <div class="total-line discount">
            <span class="t-label">Discount @if($transaction->discount_type === 'percentage')({{ $transaction->discount_value }}%)@endif</span>
            <span class="t-value">-Rs. {{ number_format($transaction->discount_amount, 2) }}</span>
        </div>
        @endif
        @if($transaction->exempt_amount > 0)
        <div class="total-line exempt">
            <span class="t-label">Tax-Exempt Items</span>
            <span class="t-value">Rs. {{ number_format($transaction->exempt_amount, 2) }}</span>
        </div>
        @endif
        <div class="total-line">
            <span class="t-label">Tax ({{ $transaction->tax_rate ?? 0 }}%)</span>
            <span class="t-value">Rs. {{ number_format($transaction->tax_amount, 2) }}</span>
        </div>
    </div>

    <div class="grand-total-box">
        <span class="gt-label">TOTAL</span>
        <span class="gt-value">Rs. {{ number_format($transaction->total_amount, 2) }}</span>
    </div>

    @if($transaction->invoice_mode === 'pra' && $transaction->pra_invoice_number)
    <div class="pra-box">
        PRA Invoice: {{ $transaction->pra_invoice_number }}
    </div>
    @endif

    <div class="qr-section">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data={{ urlencode($transaction->invoice_number . '|Rs.' . number_format($transaction->total_amount, 2) . '|' . $transaction->created_at->toIso8601String()) }}" alt="QR" onerror="this.style.display='none'">
    </div>

    <hr class="sep-dashed">

    <div class="footer">
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
