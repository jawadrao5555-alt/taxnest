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
            padding: 3mm;
            background: #fff;
            color: #000;
            line-height: 1.4;
        }
        .separator { border-top: 1px dashed #000; margin: 5px 0; }
        .separator-bold { border-top: 2px dashed #000; margin: 5px 0; }
        .bold { font-weight: bold; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-lg { font-size: 15px; }
        .text-xl { font-size: 18px; }
        .text-sm { font-size: 10px; }
        .text-xs { font-size: 9px; }
        .mt-1 { margin-top: 3px; }
        .mt-2 { margin-top: 6px; }
        .mb-1 { margin-bottom: 3px; }
        .flex { display: flex; justify-content: space-between; align-items: center; }
        .items-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .items-table td { padding: 2px 0; vertical-align: top; }
        .items-table .qty { width: 10%; font-weight: bold; text-align: center; }
        .items-table .name { width: 55%; }
        .items-table .price { width: 35%; text-align: right; font-weight: bold; }
        .items-table tr { border-bottom: 1px dotted #ccc; }
        .items-table tr:last-child { border-bottom: none; }
        .totals-table { width: 100%; border-collapse: collapse; }
        .totals-table td { padding: 1px 0; }
        .totals-table .label { width: 60%; }
        .totals-table .value { width: 40%; text-align: right; font-weight: bold; }
        .grand-total td { font-size: 16px; font-weight: bold; padding: 4px 0; border-top: 2px solid #000; }
        .badge { display: inline-block; padding: 1px 8px; border: 1px solid #000; font-weight: bold; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
        .qr-area { text-align: center; margin: 6px 0; }
        .qr-area img { max-width: 120px; height: auto; }
        .logo-area { text-align: center; margin-bottom: 4px; }
        .logo-area img { max-height: 50px; width: auto; }
        .print-btn { display: block; width: 100%; padding: 12px; margin-top: 10px; background: #7c3aed; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: bold; cursor: pointer; }
        .print-btn:hover { background: #6d28d9; }
        .print-btn-row { display: flex; gap: 6px; margin-top: 8px; }
        .print-btn-row button, .print-btn-row a { flex: 1; padding: 10px; text-align: center; border: none; border-radius: 8px; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; display: block; }
        .btn-print { background: #7c3aed; color: #fff; }
        .btn-kot { background: #f59e0b; color: #fff; }
        .btn-close { background: #e5e7eb; color: #374151; }
    </style>
</head>
<body>
    @if($company->logo)
    <div class="logo-area">
        <img src="{{ asset('storage/logos/' . $company->logo) }}" alt="{{ $company->name }}" onerror="this.style.display='none'">
    </div>
    @endif

    <div class="text-center">
        <p class="text-lg bold">{{ $company->name ?? 'Restaurant' }}</p>
        @if($company->address)<p class="text-sm">{{ $company->address }}</p>@endif
        @if($company->phone)<p class="text-sm">Tel: {{ $company->phone }}</p>@endif
        @if($company->ntn)<p class="text-sm">NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="separator-bold"></div>

    <div class="text-center mb-1">
        <p class="text-lg bold">{{ $transaction->invoice_number }}</p>
        <span class="badge">{{ strtoupper($transaction->payment_method ?? 'CASH') }}</span>
        @if($order && $order->order_type)
            <span class="badge">{{ strtoupper(str_replace('_', ' ', $order->order_type)) }}</span>
        @endif
    </div>

    <div class="flex text-sm">
        <span>{{ $transaction->created_at->format('M d, Y') }}</span>
        <span>{{ $transaction->created_at->format('h:i A') }}</span>
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
        @if($transaction->customer_phone) <span> | {{ $transaction->customer_phone }}</span>@endif
    </div>
    @endif

    <div class="flex text-xs mt-1">
        <span>Cashier: {{ $transaction->creator->name ?? 'Staff' }}</span>
    </div>

    <div class="separator"></div>

    <table class="items-table">
        <tr style="border-bottom: 1px solid #000;">
            <td class="qty bold text-sm">Qty</td>
            <td class="name bold text-sm">Item</td>
            <td class="price bold text-sm">Amount</td>
        </tr>
        @foreach($transaction->items as $item)
        <tr>
            <td class="qty">{{ number_format($item->quantity, $item->quantity == intval($item->quantity) ? 0 : 2) }}</td>
            <td class="name">
                {{ $item->item_name }}
                @if($item->is_tax_exempt)<span class="text-xs">[NT]</span>@endif
            </td>
            <td class="price">{{ number_format($item->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="text-xs" style="color:#555;">@ Rs. {{ number_format($item->unit_price, 2) }} each</td>
            <td></td>
        </tr>
        @endforeach
    </table>

    <div class="separator"></div>

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

    <div class="separator-bold"></div>

    @if($transaction->invoice_mode === 'pra' && $transaction->pra_invoice_number)
    <div class="text-center text-sm mt-1">
        <p class="bold">PRA Invoice: {{ $transaction->pra_invoice_number }}</p>
    </div>
    @endif

    <div class="qr-area">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data={{ urlencode($transaction->invoice_number . '|' . $transaction->total_amount . '|' . $transaction->created_at->toIso8601String()) }}" alt="QR Code" onerror="this.style.display='none'">
    </div>

    <div class="text-center text-sm mt-1">
        <p>{{ $transaction->items->count() }} item(s) | {{ $transaction->items->sum('quantity') }} unit(s)</p>
    </div>

    <div class="separator"></div>

    <div class="text-center text-xs mt-1">
        <p>Thank you for dining with us!</p>
        <p class="mt-1">Powered by TaxNest</p>
    </div>

    <div class="no-print print-btn-row">
        <button class="btn-print" onclick="window.print()">Print Receipt</button>
        @if($order)
        <a href="{{ route('pos.restaurant.kitchen-ticket', $order->id) }}" class="btn-kot" target="_blank">KOT</a>
        @endif
        <button class="btn-close" onclick="window.close()">Close</button>
    </div>

    <script>
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_print') === '1') {
                setTimeout(function() { window.print(); }, 500);
            }
        };
    </script>
</body>
</html>
