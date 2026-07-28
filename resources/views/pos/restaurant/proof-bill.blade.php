<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proof Bill - {{ $order->order_number }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 13px;
            /* Thermal rule: never force body width = physical paper width
               (80mm prints ~72mm) — auto + max-width cap. */
            width: auto;
            max-width: 80mm;
            margin: 0 auto;
            padding: 3mm;
            background: #fff;
            color: #000;
            line-height: 1.5;
        }
        .separator { border-top: 2px dashed #000; margin: 6px 0; }
        .bold { font-weight: bold; }
        .text-center { text-align: center; }
        .text-lg { font-size: 16px; }
        .text-sm { font-size: 11px; }
        .mt-1 { margin-top: 4px; }
        .flex { display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; }
        .items-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .items-table td { padding: 4px 2px; vertical-align: top; font-size: 13px; }
        .items-table .qty { width: 12%; font-weight: bold; }
        .items-table .amt { width: 25%; text-align: right; white-space: nowrap; }
        .items-table tr.head { border-top: 2px solid #000; border-bottom: 2px solid #000; }
        .items-table tr.head td { font-weight: bold; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; padding: 3px 2px; }
        .totals td { padding: 2px 2px; font-size: 13px; }
        .totals .grand td { font-weight: bold; font-size: 16px; border-top: 2px solid #000; padding-top: 4px; }
        /* Slim single-line marker — no reversed block, no boxed banner (thermal rules). */
        .proof-line { text-align: center; font-weight: 900; font-size: 15px; letter-spacing: 1px; margin: 4px 0; }
        @media print { body { width: auto; max-width: 80mm; } }
    </style>
</head>
<body>
    <div class="text-center bold text-lg">{{ $company->business_name ?? $company->name }}</div>
    <div class="proof-line">*** PROOF BILL ***</div>
    <div class="text-center text-sm bold">YEH PAKKI RECEIPT NAHI HAI — NOT PAID</div>
    <div class="separator"></div>
    <div class="flex text-sm">
        <span>Order: <span class="bold">{{ $order->order_number }}</span></span>
        <span>{{ $order->created_at->format('d M Y g:i A') }}</span>
    </div>
    <div class="flex text-sm">
        <span>
            @if($order->table)Table: <span class="bold">T-{{ $order->table->table_number }}</span>@else{{ ucfirst(str_replace('_',' ',$order->order_type)) }}@endif
        </span>
        @if($order->creator)<span>By: {{ \Illuminate\Support\Str::of($order->creator->name)->before(' ') }}</span>@endif
    </div>
    <table class="items-table">
        <tr class="head"><td class="qty">Qty</td><td>Item</td><td class="amt">Amount</td></tr>
        @foreach($order->items as $item)
        <tr>
            <td class="qty">{{ rtrim(rtrim(number_format((float)$item->quantity, 2), '0'), '.') }}</td>
            <td>{{ $item->item_name }}</td>
            <td class="amt">{{ number_format((float)($item->subtotal ?? ((float)$item->quantity * (float)$item->unit_price)), 0) }}</td>
        </tr>
        @endforeach
    </table>
    <table class="totals" style="width:100%">
        <tr><td>Subtotal</td><td style="text-align:right">Rs {{ number_format((float)$order->subtotal, 0) }}</td></tr>
        @if((float)$order->discount_amount > 0)
        <tr><td>Discount</td><td style="text-align:right">- Rs {{ number_format((float)$order->discount_amount, 0) }}</td></tr>
        @endif
        @if((float)$order->tax_amount > 0)
        <tr><td>Tax</td><td style="text-align:right">Rs {{ number_format((float)$order->tax_amount, 0) }}</td></tr>
        @endif
        <tr class="grand"><td>TOTAL</td><td style="text-align:right">Rs {{ number_format((float)$order->total_amount, 0) }}</td></tr>
    </table>
    <div class="separator"></div>
    <div class="proof-line">*** PROOF BILL — NOT PAID ***</div>
    <div class="text-center text-sm">Final bill counter se milega. Shukriya!</div>

    <script>
        // Same auto-print contract as the KOT ticket: iframe → postMessage the
        // parent (strict print ordering); popup → print then self-close.
        var hasPrinted = false;
        window.onload = function() {
            var urlParams = new URLSearchParams(window.location.search);
            var isInIframe = window.parent && window.parent !== window;
            var frameSignal = urlParams.get('_signal');
            if (urlParams.get('auto_print') === '1' && !hasPrinted) {
                hasPrinted = true;
                if (isInIframe && frameSignal) {
                    var signaled = false;
                    var signalParent = function() {
                        if (signaled) return;
                        signaled = true;
                        try { window.parent.postMessage({ type: 'pos_print_done', signal: frameSignal }, '*'); } catch (e) {}
                    };
                    window.addEventListener('afterprint', signalParent, { once: true });
                    setTimeout(signalParent, 20000);
                } else {
                    window.addEventListener('afterprint', function() {
                        setTimeout(function() { window.close(); }, 300);
                    }, { once: true });
                }
                setTimeout(function() { window.print(); }, 200);
            }
        };
    </script>
</body>
</html>
