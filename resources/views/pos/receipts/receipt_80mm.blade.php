<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $transaction->invoice_number }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
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
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-weight: 500;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .separator { border-top: 1px dashed #000; margin: 6px 0; }
        .double-separator { border-top: 2px solid #000; margin: 6px 0; }

        .header { margin-bottom: 8px; }
        .header h1 { font-size: 15px; font-weight: bold; margin-bottom: 3px; word-wrap: break-word; color: #000; }
        .header p { font-size: 10px; line-height: 1.4; word-wrap: break-word; color: #000; font-weight: 600; }

        .info-table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .info-table td { font-size: 11px; padding: 2px 0; vertical-align: top; color: #000; font-weight: 600; }
        .info-table .info-label { width: 32%; font-weight: bold; white-space: nowrap; color: #000; }
        .info-table .info-value { width: 68%; text-align: right; word-wrap: break-word; color: #000; }

        .invoice-numbers { border: 1.5px solid #000; padding: 6px; margin: 6px 0; }
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table td { font-size: 10px; padding: 2px 0; vertical-align: top; color: #000; }
        .inv-table .inv-label { font-weight: bold; white-space: nowrap; width: 35%; color: #000; }
        .inv-table .inv-value { text-align: right; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; font-family: 'Courier New', monospace; font-size: 9px; font-weight: bold; color: #000; }

        .items-table { width: 100%; margin: 4px 0; border-collapse: collapse; table-layout: fixed; }
        .items-table th { font-size: 10px; text-transform: uppercase; border-bottom: 1.5px solid #000; border-top: 1.5px solid #000; padding: 4px 1px; text-align: left; font-weight: bold; color: #000; }
        .items-table td { font-size: 11px; padding: 4px 1px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; color: #000; font-weight: 600; }
        .items-table .col-item { width: 44%; text-align: left; }
        .items-table .col-qty { width: 16%; text-align: center; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .items-table .col-rate { width: 18%; text-align: right; }
        .items-table .col-total { width: 22%; text-align: right; font-weight: bold; }
        .items-table tbody tr { border-bottom: 1px dashed #000; }
        .items-table tbody tr:last-child { border-bottom: none; }
        .exempt-tag { font-size: 8px; font-weight: 800; color: #000; border: 1px solid #000; padding: 0 3px; margin-left: 3px; vertical-align: middle; letter-spacing: 0.3px; }
        .exempt-note { font-size: 9px; font-weight: 700; color: #000; text-align: center; margin: 4px 0 2px; padding: 3px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; letter-spacing: 0.4px; }

        .totals-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .totals-table td { font-size: 11px; padding: 3px 0; vertical-align: top; color: #000; font-weight: 600; }
        .totals-table .tot-label { text-align: left; color: #000; }
        .totals-table .tot-value { text-align: right; white-space: nowrap; color: #000; font-weight: bold; }
        .totals-table .grand-total td { font-size: 15px; font-weight: bold; padding: 6px 4px; background: #000; color: #fff; }

        .pra-badge { border: 2px solid #000; padding: 6px; margin: 6px 0; text-align: center; font-size: 10px; overflow: hidden; color: #000; font-weight: 600; }
        .pra-badge .pra-title { font-size: 12px; font-weight: bold; margin-bottom: 3px; color: #000; }
        .pra-badge .pra-number { font-size: 9px; font-weight: bold; letter-spacing: 0; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; max-width: 100%; display: block; color: #000; }
        .local-badge { border: 1.5px dashed #000; padding: 6px; margin: 6px 0; text-align: center; font-size: 10px; color: #000; font-weight: 700; }
        .qr-code { text-align: center; margin: 6px 0; }
        .qr-code img { width: 100px; height: 100px; }
        .qr-code p { font-size: 9px; margin-top: 2px; color: #000; font-weight: 600; }

        .footer { margin-top: 8px; font-size: 10px; line-height: 1.5; color: #000; font-weight: 600; }

        @media print {
            body { width: 80mm; max-width: 80mm; padding: 2mm; margin: 0; }
            .no-print { display: none !important; }
        }
        @media screen {
            body { padding: 10px; }
            .no-print { margin-bottom: 15px; text-align: center; font-family: Arial, sans-serif; }
        }
    </style>
</head>
<body>
    <div class="no-print" id="receiptActions">
        <button onclick="window.print()" style="padding: 10px 30px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; margin-right: 10px;">Print Receipt</button>
        <a href="{{ route('pos.transactions') }}" target="_top" style="padding: 10px 30px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block;">Back to Transactions</a>
    </div>
    <script>
        var isInIframe = (window.self !== window.top);
        if (isInIframe) {
            var actions = document.getElementById('receiptActions');
            if (actions) actions.style.display = 'none';
        }
        window.onafterprint = function() {
            if (isInIframe) return;
            if (window.opener) {
                window.close();
            } else {
                window.location.href = '{{ route('pos.transactions') }}';
            }
        };

        // AUTO-PRINT + POSTMESSAGE SIGNAL (Restaurant POS sale-time chain)
        // ----------------------------------------------------------------
        // When loaded with ?auto_print=1, automatically fire window.print().
        // When loaded with ?_signal=<token> inside the parent's hidden print
        // iframe, attach `afterprint` here (where it's reliable per spec) and
        // signal the parent via postMessage when the print dialog actually
        // closes. Parent then chains the next print (e.g. KOT) only AFTER
        // receiving this signal — eliminates the "KOT pops up before receipt"
        // race. Mirrors the wiring previously used in pos.restaurant.receipt.
        window.addEventListener('load', function() {
            var urlParams = new URLSearchParams(window.location.search);
            var frameSignal = urlParams.get('_signal');
            if (urlParams.get('auto_print') !== '1') return;
            if (isInIframe && frameSignal) {
                var signaled = false;
                var signalParent = function() {
                    if (signaled) return;
                    signaled = true;
                    try { window.parent.postMessage({ type: 'pos_print_done', signal: frameSignal }, '*'); } catch (e) {}
                };
                window.addEventListener('afterprint', signalParent, { once: true });
                // Safety net inside the iframe — if afterprint never fires
                // (silent printer drivers), signal the parent after a generous
                // wait so the chain still advances.
                setTimeout(signalParent, 20000);
            }
            setTimeout(function() { window.print(); }, 500);
        });
    </script>

    <div class="header text-center">
        @if($company->logo_path)
        <div style="margin-bottom: 5px;">
            <img src="{{ asset('storage/' . $company->logo_path) }}" alt="{{ $company->name }}" style="max-width: 150px; max-height: 55px; margin: 0 auto; display: block; object-fit: contain;">
        </div>
        @endif
        <h1>{{ $company->name }}</h1>
        @if($company->address)<p>{{ $company->address }}</p>@endif
        @if($company->phone)<p>Tel: {{ $company->phone }}</p>@endif
        @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="separator"></div>

    <div class="invoice-numbers">
        <table class="inv-table">
            <tr>
                <td class="inv-label">POS Invoice #:</td>
                <td class="inv-value">{{ $transaction->invoice_number }}</td>
            </tr>
            @if($transaction->pra_invoice_number)
            <tr>
                <td class="inv-label">PRA Fiscal #:</td>
                <td class="inv-value">{{ $transaction->pra_invoice_number }}</td>
            </tr>
            @endif
        </table>
    </div>

    <table class="info-table">
        <tr><td class="info-label">Date:</td><td class="info-value">{{ $transaction->created_at->format('d/m/Y h:i A') }}</td></tr>
        @if($transaction->terminal)
        <tr><td class="info-label">Terminal:</td><td class="info-value">{{ $transaction->terminal->terminal_name }}</td></tr>
        @endif
        @if($transaction->customer_name)
        <tr><td class="info-label">Customer:</td><td class="info-value">{{ $transaction->customer_name }}</td></tr>
        @endif
        @if($transaction->customer_phone)
        <tr><td class="info-label">Phone:</td><td class="info-value">{{ $transaction->customer_phone }}</td></tr>
        @endif
        <tr><td class="info-label">Payment:</td><td class="info-value">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</td></tr>
        @if($transaction->creator)
        <tr><td class="info-label">Cashier:</td><td class="info-value">{{ $transaction->creator->name }}</td></tr>
        @endif
    </table>

    <div class="separator"></div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-item">Item</th>
                <th class="col-qty">Qty</th>
                <th class="col-rate">Rate</th>
                <th class="col-total">Amt</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transaction->items as $item)
            <tr>
                <td class="col-item">{{ $item->item_name }}@if($item->is_tax_exempt)<span class="exempt-tag">NT</span>@endif</td>
                <td class="col-qty">{{ rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') ?: '0' }}</td>
                <td class="col-rate">{{ number_format($item->unit_price, 0) }}</td>
                <td class="col-total">{{ number_format($item->subtotal, 0) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="separator"></div>

    <table class="totals-table">
        <tr>
            <td class="tot-label">Subtotal:</td>
            <td class="tot-value">PKR {{ number_format($transaction->subtotal, 2) }}</td>
        </tr>
        @if($transaction->discount_amount > 0)
        <tr>
            <td class="tot-label">Discount{{ $transaction->discount_type === 'percentage' ? ' ('.$transaction->discount_value.'%)' : '' }}:</td>
            <td class="tot-value">-PKR {{ number_format($transaction->discount_amount, 2) }}</td>
        </tr>
        @endif
        <tr>
            <td class="tot-label">Tax ({{ number_format($transaction->tax_rate, 0) }}%):</td>
            <td class="tot-value">PKR {{ number_format($transaction->tax_amount, 2) }}</td>
        </tr>
    </table>
    <div class="double-separator"></div>
    <table class="totals-table">
        <tr class="grand-total">
            <td class="tot-label">TOTAL:</td>
            <td class="tot-value">PKR {{ number_format($transaction->total_amount, 2) }}</td>
        </tr>
    </table>

    <div class="separator"></div>

    @if($transaction->pra_status === 'submitted' && $transaction->pra_invoice_number)
    <div class="pra-badge">
        <div class="pra-title">PRA FISCAL INVOICE</div>
        <div>POS: {{ $transaction->invoice_number }}</div>
        <div class="pra-number">PRA: {{ $transaction->pra_invoice_number }}</div>
    </div>
    @if($transaction->pra_qr_code)
    <div class="qr-code">
        <img src="{{ $transaction->pra_qr_code }}" alt="PRA Verification QR">
        <p>Scan to verify on PRA portal</p>
    </div>
    @endif
    @elseif($transaction->pra_status === 'offline')
    <div class="local-badge">
        OFFLINE INVOICE<br>
        Will sync to PRA automatically<br>
        {{ $transaction->invoice_number }}
    </div>
    @else
    @php
        $qrData = json_encode([
            'type' => 'Provisional Bill',
            'inv' => $transaction->invoice_number,
            'date' => $transaction->created_at->format('d/m/Y H:i'),
            'total' => number_format($transaction->total_amount, 2),
            'business' => $transaction->company->name ?? 'NestPOS',
        ]);
        $qrUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=150x150&chl=' . urlencode($qrData);
    @endphp
    <div class="local-badge" style="border: 2px dashed #000; color: #000; padding: 8px; font-weight: 700;">
        <strong style="font-size: 12px; color: #000;">PROVISIONAL BILL</strong><br>
        <span style="font-weight: bold;">{{ $transaction->invoice_number }}</span><br>
        <span style="font-size: 10px; color: #000; font-weight: 600;">This is a provisional bill for your reference</span>
    </div>
    <div class="qr-code">
        <img src="{{ $qrUrl }}" alt="Invoice QR" style="width: 100px; height: 100px; margin: 4px auto;">
        <p style="font-size: 10px; color: #000; font-weight: 600;">Scan for invoice details</p>
    </div>
    @endif

    <div class="footer text-center">
        <p>Thank you for your purchase!</p>
        <p>Powered by NestPOS</p>
        <p>{{ now()->format('d/m/Y h:i:s A') }}</p>
    </div>
</body>
</html>
