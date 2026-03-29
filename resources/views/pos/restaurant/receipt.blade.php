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
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 12px;
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 4mm 3mm;
            background: #fff;
            color: #000;
            line-height: 1.4;
        }
        .separator { border-top: 1px dashed #000; margin: 6px 0; }
        .separator-bold { border-top: 2px dashed #000; margin: 8px 0; }
        .separator-double { border-top: 3px double #000; margin: 8px 0; }
        .bold { font-weight: bold; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-lg { font-size: 15px; }
        .text-xl { font-size: 18px; }
        .text-sm { font-size: 10px; }
        .text-xs { font-size: 9px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .mb-1 { margin-bottom: 4px; }
        .flex { display: flex; justify-content: space-between; align-items: center; }
        .items-table { width: 100%; border-collapse: collapse; margin: 6px 0; }
        .items-table td { padding: 3px 0; vertical-align: top; }
        .items-table .qty { width: 12%; font-weight: bold; text-align: center; }
        .items-table .name { width: 52%; }
        .items-table .price { width: 36%; text-align: right; font-weight: bold; }
        .items-table .header-row td { border-bottom: 1px solid #000; padding-bottom: 4px; }
        .items-table .item-row td { border-bottom: 1px dotted #ccc; padding: 4px 0; }
        .items-table .item-row:last-child td { border-bottom: none; }
        .totals-table { width: 100%; border-collapse: collapse; }
        .totals-table td { padding: 2px 0; }
        .totals-table .label { width: 55%; }
        .totals-table .value { width: 45%; text-align: right; font-weight: bold; }
        .grand-total td { font-size: 16px; font-weight: bold; padding: 6px 0 4px; border-top: 2px solid #000; }
        .badge { display: inline-block; padding: 2px 8px; border: 1px solid #000; font-weight: bold; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; margin: 2px 1px; }
        .qr-area { text-align: center; margin: 10px 0 6px; }
        .qr-area img { width: 100px; height: 100px; }
        .logo-area { text-align: center; margin-bottom: 6px; padding-top: 2px; }
        .logo-area img { max-height: 45px; max-width: 60mm; width: auto; }
        .print-btn-row { display: flex; gap: 6px; margin-top: 10px; }
        .print-btn-row button, .print-btn-row a { flex: 1; padding: 10px; text-align: center; border: none; border-radius: 8px; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; display: block; }
        .btn-print { background: #7c3aed; color: #fff; }
        .btn-print:hover { background: #6d28d9; }
        .btn-kot { background: #f59e0b; color: #fff; }
        .btn-close { background: #e5e7eb; color: #374151; }
        .item-discount { font-size: 9px; color: #666; font-style: italic; }
    </style>
</head>
<body>
    @if($company->logo)
    <div class="logo-area">
        <img src="{{ asset('storage/logos/' . $company->logo) }}" alt="{{ $company->name }}" onerror="this.style.display='none'">
    </div>
    @endif

    <div class="text-center">
        <p class="text-xl bold">{{ $company->name ?? 'Restaurant' }}</p>
        @if($company->address)<p class="text-sm mt-1">{{ $company->address }}</p>@endif
        @if($company->phone)<p class="text-sm">Tel: {{ $company->phone }}</p>@endif
        @if($company->ntn)<p class="text-sm bold">NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="separator-double"></div>

    <div class="text-center mb-1">
        <p class="text-lg bold">{{ $transaction->invoice_number }}</p>
        <div class="mt-1">
            <span class="badge">{{ strtoupper($transaction->payment_method ?? 'CASH') }}</span>
            @if($order && $order->order_type)
                <span class="badge">{{ strtoupper(str_replace('_', ' ', $order->order_type)) }}</span>
            @endif
        </div>
    </div>

    <div class="separator"></div>

    <div class="flex text-sm">
        <span>{{ $transaction->created_at->format('M d, Y') }}</span>
        <span class="bold">{{ $transaction->created_at->format('h:i A') }}</span>
    </div>

    @if($order && $order->table)
    <div class="flex text-sm mt-1">
        <span class="bold">Table: T-{{ $order->table->table_number }}</span>
        <span>{{ $order->table->seats }} seats</span>
    </div>
    @endif

    @if($transaction->customer_name)
    <div class="text-sm mt-1">
        <span class="bold">Customer: {{ $transaction->customer_name }}</span>
        @if($transaction->customer_phone) <span>| {{ $transaction->customer_phone }}</span>@endif
    </div>
    @endif

    <div class="text-xs mt-1" style="color:#555;">
        <span>Cashier: {{ $transaction->creator->name ?? 'Staff' }}</span>
    </div>

    <div class="separator-bold"></div>

    <table class="items-table">
        <tr class="header-row">
            <td class="qty bold text-sm">Qty</td>
            <td class="name bold text-sm">Item</td>
            <td class="price bold text-sm">Amount</td>
        </tr>
        @foreach($transaction->items as $item)
        <tr class="item-row">
            <td class="qty">{{ number_format($item->quantity, $item->quantity == intval($item->quantity) ? 0 : 2) }}</td>
            <td class="name">
                {{ $item->item_name }}
                @if($item->is_tax_exempt)<span class="text-xs"> [NT]</span>@endif
                <br><span class="text-xs" style="color:#555;">@ Rs. {{ number_format($item->unit_price, 2) }} each</span>
                @if(isset($item->item_discount_amount) && $item->item_discount_amount > 0)
                <br><span class="item-discount">Disc: -Rs. {{ number_format($item->item_discount_amount, 2) }}</span>
                @endif
            </td>
            <td class="price">{{ number_format($item->subtotal, 2) }}</td>
        </tr>
        @endforeach
    </table>

    <div class="separator-bold"></div>

    <table class="totals-table">
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">Rs. {{ number_format($transaction->subtotal, 2) }}</td>
        </tr>
        @if($transaction->discount_amount > 0)
        <tr>
            <td class="label">Discount
                @if($transaction->discount_type === 'percentage')({{ $transaction->discount_value }}%)@endif
            </td>
            <td class="value">-Rs. {{ number_format($transaction->discount_amount, 2) }}</td>
        </tr>
        @endif
        @if($transaction->exempt_amount > 0)
        <tr>
            <td class="label text-xs">Tax-Exempt Items</td>
            <td class="value text-xs">Rs. {{ number_format($transaction->exempt_amount, 2) }}</td>
        </tr>
        @endif
        <tr>
            <td class="label">Tax ({{ $transaction->tax_rate ?? 0 }}%)</td>
            <td class="value">Rs. {{ number_format($transaction->tax_amount, 2) }}</td>
        </tr>
        <tr class="grand-total">
            <td class="label">TOTAL</td>
            <td class="value">Rs. {{ number_format($transaction->total_amount, 2) }}</td>
        </tr>
    </table>

    <div class="separator-double"></div>

    @if($transaction->invoice_mode === 'pra' && $transaction->pra_invoice_number)
    <div class="text-center text-sm mt-1">
        <p class="bold">PRA Invoice: {{ $transaction->pra_invoice_number }}</p>
    </div>
    <div class="separator"></div>
    @endif

    <div class="qr-area">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data={{ urlencode($transaction->invoice_number . '|Rs.' . number_format($transaction->total_amount, 2) . '|' . $transaction->created_at->toIso8601String()) }}" alt="QR Code" onerror="this.style.display='none'">
    </div>

    <div class="text-center text-sm">
        <p>{{ $transaction->items->count() }} item(s) | {{ number_format($transaction->items->sum('quantity'), 0) }} unit(s)</p>
    </div>

    <div class="separator"></div>

    <div class="text-center text-xs mt-1">
        <p class="bold">Thank you for dining with us!</p>
        <p class="mt-1" style="color:#888;">Powered by TaxNest</p>
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
        function handlePrint() {
            if (hasPrinted && !confirm('This receipt has already been printed. Print again?')) return;
            hasPrinted = true;
            window.print();
        }
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_print') === '1' && !hasPrinted) {
                hasPrinted = true;
                setTimeout(function() { window.print(); }, 500);
            }
        };
    </script>
</body>
</html>
