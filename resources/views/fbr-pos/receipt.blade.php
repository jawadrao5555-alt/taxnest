<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $transaction->invoice_number }}</title>
    @php $paperSize = $company->print_paper_size ?? 'thermal'; @endphp
    <style>
        @if($paperSize === 'a4')
            /* 📄 A4 mode — thermal-width receipt centered on full A4.
               15mm side + 18mm bottom margins prevent corner-cut on consumer printers. */
            @page { size: A4 portrait; margin: 15mm 15mm 18mm 15mm; }
        @else
            /* 🧾 Thermal mode — 80mm continuous roll, auto-cut */
            @page { size: 80mm auto; margin: 0; }
        @endif
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
        .items-table .col-item { width: 38%; text-align: left; }
        .items-table .col-uom { width: 10%; text-align: center; }
        .items-table .col-qty { width: 10%; text-align: center; }
        .items-table .col-price { width: 20%; text-align: right; }
        .items-table .col-total { width: 22%; text-align: right; font-weight: bold; }
        .items-table tbody tr { border-bottom: 1px dashed #000; }
        .items-table tbody tr:last-child { border-bottom: none; }

        .totals-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .totals-table td { font-size: 11px; padding: 3px 0; vertical-align: top; color: #000; font-weight: 600; }
        .totals-table .tot-label { text-align: left; color: #000; }
        .totals-table .tot-value { text-align: right; white-space: nowrap; color: #000; font-weight: bold; }
        .totals-table .grand-total td { font-size: 15px; font-weight: bold; padding: 6px 4px; background: #000; color: #fff; }

        .fbr-badge { border: 2px solid #000; padding: 6px; margin: 6px 0; text-align: center; font-size: 10px; overflow: hidden; color: #000; font-weight: 600; }
        .fbr-badge .fbr-title { font-size: 12px; font-weight: bold; margin-bottom: 3px; color: #000; }
        .fbr-badge .fbr-number { font-size: 9px; font-weight: bold; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; max-width: 100%; display: block; color: #000; }
        .local-badge { border: 1.5px dashed #000; padding: 6px; margin: 6px 0; text-align: center; font-size: 10px; color: #000; font-weight: 700; }

        .footer { margin-top: 8px; font-size: 10px; line-height: 1.5; color: #000; font-weight: 600; }

        @media print {
            body { width: 80mm; max-width: 80mm; padding: 2mm; margin: 0 auto; }
            .no-print { display: none !important; }
            @if($paperSize === 'a4')
                /* A4: centered on page, no page break inside the receipt so it stays intact */
                html, body { background: #fff; }
                body { margin: 0 auto; page-break-inside: avoid; }
                .receipt-wrap { page-break-inside: avoid; break-inside: avoid; }
            @endif
        }
        @media screen {
            body { padding: 10px; }
            .no-print { margin-bottom: 15px; text-align: center; font-family: Arial, sans-serif; }
        }
    </style>
</head>
<body>
    <div class="no-print" id="receiptActions">
        <button onclick="window.print()" style="padding: 10px 30px; background: #2563eb; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; margin-right: 10px;">Print Receipt</button>
        <a href="{{ route('fbrpos.transactions') }}" target="_top" style="padding: 10px 30px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block;">Back to Transactions</a>
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
                window.location.href = '{{ route('fbrpos.transactions') }}';
            }
        };
    </script>

    <div class="receipt-wrap">
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
                <td class="inv-label">FBR POS #:</td>
                <td class="inv-value">{{ $transaction->invoice_number }}</td>
            </tr>
            @if($transaction->fbr_invoice_number)
            <tr>
                <td class="inv-label">FBR Invoice #:</td>
                <td class="inv-value">{{ $transaction->fbr_invoice_number }}</td>
            </tr>
            @endif
        </table>
    </div>

    <table class="info-table">
        <tr><td class="info-label">Date:</td><td class="info-value">{{ $transaction->created_at->format('d/m/Y h:i A') }}</td></tr>
        @if($transaction->customer_name)
        <tr><td class="info-label">Customer:</td><td class="info-value">{{ $transaction->customer_name }}</td></tr>
        @endif
        @if($transaction->customer_phone)
        <tr><td class="info-label">Phone:</td><td class="info-value">{{ $transaction->customer_phone }}</td></tr>
        @endif
        @if($transaction->customer_ntn)
        <tr><td class="info-label">NTN:</td><td class="info-value">{{ $transaction->customer_ntn }}</td></tr>
        @endif
        <tr><td class="info-label">Tax Period:</td><td class="info-value">{{ $transaction->created_at->format('M Y') }}</td></tr>
        <tr><td class="info-label">Payment:</td><td class="info-value">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</td></tr>
        @if($transaction->creator)
        <tr><td class="info-label">Cashier:</td><td class="info-value">{{ $transaction->creator->name }}</td></tr>
        @endif
        @if($company->fbr_pos_id)
        <tr><td class="info-label">POS Reg #:</td><td class="info-value">{{ $company->fbr_pos_id }}</td></tr>
        @endif
    </table>

    <div class="separator"></div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-item">Item</th>
                <th class="col-uom">UoM</th>
                <th class="col-qty">Qty</th>
                <th class="col-price">Price</th>
                <th class="col-total">Total</th>
            </tr>
        </thead>
        <tbody>
            @php
                $fmtQty = function($q) {
                    $f = (float) $q;
                    return $f == (int) $f ? (string) (int) $f : rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
                };
            @endphp
            @foreach($transaction->items as $item)
            <tr>
                <td class="col-item">{{ $item->item_name }}</td>
                <td class="col-uom">{{ $item->uom ?? 'U' }}</td>
                <td class="col-qty">{{ $fmtQty($item->quantity) }}</td>
                <td class="col-price">{{ number_format($item->unit_price, 0) }}</td>
                <td class="col-total">{{ number_format($item->subtotal, 0) }}</td>
            </tr>
            @if(($item->item_discount ?? 0) > 0)
            <tr>
                <td class="col-item" colspan="4" style="font-size: 0.9em; color: #000; padding-left: 8px; font-weight: bold;">&#x21B3; Item Discount</td>
                <td class="col-total" style="font-size: 0.9em; color: #000; font-weight: bold;">-{{ number_format($item->item_discount, 0) }}</td>
            </tr>
            @endif
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
        @if($transaction->fbr_service_charge > 0)
        <tr>
            <td class="tot-label">FBR POS Fee:</td>
            <td class="tot-value">PKR {{ number_format($transaction->fbr_service_charge, 2) }}</td>
        </tr>
        @endif
    </table>
    <div class="double-separator"></div>
    <table class="totals-table">
        <tr class="grand-total">
            <td class="tot-label">TOTAL:</td>
            <td class="tot-value">PKR {{ number_format($transaction->total_amount, 2) }}</td>
        </tr>
    </table>

    <div class="separator"></div>

    @php
        $qrData = json_encode([
            'pos' => $transaction->invoice_number,
            'fbr' => $transaction->fbr_invoice_number ?? '',
            'ntn' => $company->ntn ?? '',
            'date' => $transaction->created_at->format('d/m/Y'),
            'total' => number_format($transaction->total_amount, 2, '.', ''),
            'reg' => $company->fbr_pos_id ?? '',
        ]);
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($qrData);
    @endphp

    @if($transaction->fbr_status === 'submitted' && $transaction->fbr_invoice_number)
    <div class="fbr-badge">
        <div class="fbr-title">✓ INTEGRATED WITH FBR</div>
        <div style="font-size:11px; font-weight:bold; margin:3px 0;">FBR VERIFIED INVOICE</div>
        <div style="margin: 6px 0;">
            <img src="{{ $qrUrl }}" alt="FBR QR Code" style="width:100px; height:100px; margin:0 auto; display:block;">
        </div>
        <div>POS: {{ $transaction->invoice_number }}</div>
        <div class="fbr-number">FBR: {{ $transaction->fbr_invoice_number }}</div>
        @if($company->fbr_pos_id)
        <div style="font-size:9px; margin-top:3px;">POS Reg #: {{ $company->fbr_pos_id }}</div>
        @endif
    </div>
    @elseif($transaction->fbr_status === 'local')
    <div class="local-badge">
        LOCAL INVOICE<br>
        (FBR Reporting OFF)<br>
        {{ $transaction->invoice_number }}
    </div>
    @else
    <div class="fbr-badge" style="border-style: dashed;">
        <div class="fbr-title">⏳ FBR PENDING</div>
        <div style="margin: 6px 0;">
            <img src="{{ $qrUrl }}" alt="QR Code" style="width:100px; height:100px; margin:0 auto; display:block;">
        </div>
        <div>POS: {{ $transaction->invoice_number }}</div>
        <div style="font-size:10px; margin-top:3px;">Will retry automatically</div>
        @if($company->fbr_pos_id)
        <div style="font-size:9px; margin-top:3px;">POS Reg #: {{ $company->fbr_pos_id }}</div>
        @endif
    </div>
    @endif

    <div class="footer text-center">
        <p>Thank you for your purchase!</p>
        @if(!empty($company->receipt_footer_note))
        <p style="font-style: italic; margin-top:2px;">{{ $company->receipt_footer_note }}</p>
        @endif
        @if($company->fbr_pos_id)
        <p style="font-weight:bold;">Integrated with FBR | Reg #: {{ $company->fbr_pos_id }}</p>
        @endif
        <p>Powered by TaxNest FBR POS</p>
        <p>{{ now()->format('d/m/Y h:i:s A') }}</p>
    </div>
    </div>{{-- /.receipt-wrap --}}
</body>
</html>
