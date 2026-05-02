<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $transaction->invoice_number }}</title>
    <style>
        @page {
            size: 58mm auto;
            margin: 0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 10px;
            width: 58mm;
            max-width: 58mm;
            margin: 0 auto;
            padding: 2mm;
            background: #fff;
            color: #000;
            line-height: 1.35;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-weight: 500;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .separator { border-top: 1px dashed #000; margin: 5px 0; }
        .double-separator { border-top: 2px solid #000; margin: 5px 0; }

        .header { margin-bottom: 5px; }
        .header h1 { font-size: 12px; font-weight: bold; margin-bottom: 2px; word-wrap: break-word; color: #000; }
        .header p { font-size: 9px; line-height: 1.3; word-wrap: break-word; color: #000; font-weight: 600; }

        .info-table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .info-table td { font-size: 9px; padding: 2px 0; vertical-align: top; color: #000; font-weight: 600; }
        .info-table .info-label { width: 30%; font-weight: bold; white-space: nowrap; color: #000; }
        .info-table .info-value { width: 70%; text-align: right; word-wrap: break-word; color: #000; }

        .invoice-numbers { border: 1.5px solid #000; padding: 4px; margin: 5px 0; }
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table td { font-size: 9px; padding: 2px 0; vertical-align: top; color: #000; }
        .inv-table .inv-label { font-weight: bold; white-space: nowrap; width: 30%; color: #000; }
        .inv-table .inv-value { text-align: right; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; font-family: 'Courier New', monospace; font-size: 8px; font-weight: bold; color: #000; }

        .items-table { width: 100%; margin: 3px 0; border-collapse: collapse; table-layout: fixed; }
        .items-table th { font-size: 8px; text-transform: uppercase; border-bottom: 1.5px solid #000; border-top: 1.5px solid #000; padding: 3px 1px; text-align: left; font-weight: bold; color: #000; }
        .items-table td { font-size: 9px; padding: 3px 1px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; color: #000; font-weight: 600; }
        .items-table .col-item { width: 44%; text-align: left; }
        .items-table .col-qty { width: 16%; text-align: center; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .items-table .col-rate { width: 18%; text-align: right; }
        .items-table .col-total { width: 22%; text-align: right; font-weight: bold; }
        .items-table tbody tr { border-bottom: 1px dashed #000; }
        .items-table tbody tr:last-child { border-bottom: none; }
        .exempt-tag { font-size: 7px; font-weight: 800; color: #000; border: 1px solid #000; padding: 0 2px; margin-left: 2px; vertical-align: middle; }
        .exempt-note { font-size: 8px; font-weight: 700; color: #000; text-align: center; margin: 3px 0 2px; padding: 2px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; letter-spacing: 0.3px; }

        .totals-table { width: 100%; border-collapse: collapse; margin: 3px 0; }
        .totals-table td { font-size: 9px; padding: 2px 0; vertical-align: top; color: #000; font-weight: 600; }
        .totals-table .tot-label { text-align: left; color: #000; }
        .totals-table .tot-value { text-align: right; white-space: nowrap; color: #000; font-weight: bold; }
        .totals-table .grand-total td { font-size: 13px; font-weight: bold; padding: 5px 3px; background: #000; color: #fff; }

        .pra-badge { border: 1.5px solid #000; padding: 4px; margin: 5px 0; text-align: center; font-size: 9px; overflow: hidden; color: #000; font-weight: 600; }
        .pra-badge .pra-title { font-size: 10px; font-weight: bold; margin-bottom: 2px; color: #000; }
        .pra-badge .pra-number { font-size: 8px; font-weight: bold; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; max-width: 100%; display: block; color: #000; }
        .local-badge { border: 1.5px dashed #000; padding: 4px; margin: 5px 0; text-align: center; font-size: 9px; color: #000; font-weight: 700; }
        .qr-code { text-align: center; margin: 5px 0; }
        .qr-code img { width: 75px; height: 75px; }
        .qr-code p { font-size: 8px; margin-top: 1px; color: #000; font-weight: 600; }

        .footer { margin-top: 6px; font-size: 9px; line-height: 1.4; color: #000; font-weight: 600; }

        @media print {
            body { width: 58mm; max-width: 58mm; padding: 1mm; margin: 0; }
            .no-print { display: none !important; }
        }
        @media screen {
            body { padding: 8px; }
            .no-print { margin-bottom: 12px; text-align: center; font-family: Arial, sans-serif; }
        }
    </style>
</head>
<body>
    <div class="no-print" id="receiptActions">
        <button onclick="window.print()" style="padding: 8px 24px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; margin-right: 8px;">Print</button>
        <a href="{{ route('pos.transactions') }}" target="_top" style="padding: 8px 24px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-block;">Back</a>
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
        // See receipt_80mm for full rationale.
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
                setTimeout(signalParent, 20000);
            }
            setTimeout(function() { window.print(); }, 500);
        });
    </script>

    <div class="header text-center">
        @if($company->logo_path)
        <div style="margin-bottom: 3px;">
            <img src="{{ asset('storage/' . $company->logo_path) }}" alt="{{ $company->name }}" style="max-width: 110px; max-height: 40px; margin: 0 auto; display: block; object-fit: contain;">
        </div>
        @endif
        <h1>{{ $company->name }}</h1>
        @if($company->phone)<p>{{ $company->phone }}</p>@endif
        @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="separator"></div>

    <div class="invoice-numbers">
        <table class="inv-table">
            <tr>
                <td class="inv-label">POS#:</td>
                <td class="inv-value">{{ $transaction->invoice_number }}</td>
            </tr>
            @if($transaction->pra_invoice_number)
            <tr>
                <td class="inv-label">PRA#:</td>
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
        <tr><td class="info-label">Cust:</td><td class="info-value">{{ $transaction->customer_name }}</td></tr>
        @endif
        <tr><td class="info-label">Pay:</td><td class="info-value">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</td></tr>
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
            <td class="tot-value">{{ number_format($transaction->subtotal, 2) }}</td>
        </tr>
        @if($transaction->discount_amount > 0)
        <tr>
            <td class="tot-label">Disc:</td>
            <td class="tot-value">-{{ number_format($transaction->discount_amount, 2) }}</td>
        </tr>
        @endif
        <tr>
            <td class="tot-label">Tax ({{ number_format($transaction->tax_rate, 0) }}%):</td>
            <td class="tot-value">{{ number_format($transaction->tax_amount, 2) }}</td>
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
        <div class="pra-title">PRA FISCAL</div>
        <div class="pra-number">{{ $transaction->pra_invoice_number }}</div>
    </div>
    @if($transaction->pra_qr_code)
    <div class="qr-code">
        <img src="{{ $transaction->pra_qr_code }}" alt="PRA QR">
        <p>Scan to verify</p>
    </div>
    @endif
    @elseif($transaction->pra_status === 'offline')
    <div class="local-badge">OFFLINE - Will sync to PRA</div>
    @else
    @php
        $qrData = json_encode([
            'type' => 'Provisional Bill',
            'inv' => $transaction->invoice_number,
            'date' => $transaction->created_at->format('d/m/Y H:i'),
            'total' => number_format($transaction->total_amount, 2),
            'business' => $transaction->company->name ?? 'NestPOS',
        ]);
        $qrUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=120x120&chl=' . urlencode($qrData);
    @endphp
    <div class="local-badge" style="border: 1.5px dashed #000; color: #000; padding: 6px; font-weight: 700;">
        <strong style="font-size: 10px; color: #000;">PROVISIONAL BILL</strong><br>
        <span style="font-size: 9px; font-weight: bold;">{{ $transaction->invoice_number }}</span>
    </div>
    <div class="qr-code">
        <img src="{{ $qrUrl }}" alt="Invoice QR" style="width: 80px; height: 80px; margin: 3px auto;">
        <p style="font-size: 8px; color: #000; font-weight: 600;">Scan for details</p>
    </div>
    @endif

    <div class="footer text-center">
        <p>Thank you!</p>
        <p>NestPOS</p>
        <p>{{ now()->format('d/m/Y h:i A') }}</p>
    </div>
</body>
</html>
