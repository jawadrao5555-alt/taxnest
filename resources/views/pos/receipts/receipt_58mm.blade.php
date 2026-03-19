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
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .separator { border-top: 1px dashed #000; margin: 5px 0; }
        .double-separator { border-top: 2px solid #000; margin: 5px 0; }

        .header { margin-bottom: 5px; }
        .header h1 { font-size: 12px; font-weight: bold; margin-bottom: 2px; word-wrap: break-word; }
        .header p { font-size: 9px; line-height: 1.3; word-wrap: break-word; }

        .info-table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .info-table td { font-size: 9px; padding: 1px 0; vertical-align: top; }
        .info-table .info-label { width: 30%; font-weight: bold; white-space: nowrap; }
        .info-table .info-value { width: 70%; text-align: right; word-wrap: break-word; }

        .invoice-numbers { border: 1px solid #000; padding: 3px; margin: 5px 0; }
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table td { font-size: 9px; padding: 1px 0; vertical-align: top; }
        .inv-table .inv-label { font-weight: bold; white-space: nowrap; width: 30%; }
        .inv-table .inv-value { text-align: right; word-wrap: break-word; font-family: 'Courier New', monospace; font-size: 8px; }

        .items-table { width: 100%; margin: 3px 0; border-collapse: collapse; table-layout: fixed; }
        .items-table th { font-size: 8px; text-transform: uppercase; border-bottom: 1px solid #000; padding: 2px 1px; text-align: left; }
        .items-table td { font-size: 9px; padding: 2px 1px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
        .items-table .col-item { width: 52%; text-align: left; }
        .items-table .col-qty { width: 12%; text-align: center; }
        .items-table .col-total { width: 36%; text-align: right; }
        .items-table tbody tr { border-bottom: 1px dotted #ccc; }
        .items-table tbody tr:last-child { border-bottom: none; }
        .exempt-tag { font-size: 7px; font-weight: bold; }

        .totals-table { width: 100%; border-collapse: collapse; margin: 3px 0; }
        .totals-table td { font-size: 9px; padding: 1px 0; vertical-align: top; }
        .totals-table .tot-label { text-align: left; }
        .totals-table .tot-value { text-align: right; white-space: nowrap; }
        .totals-table .grand-total td { font-size: 13px; font-weight: bold; padding-top: 3px; }

        .pra-badge { border: 1px solid #000; padding: 4px; margin: 5px 0; text-align: center; font-size: 9px; }
        .pra-badge .pra-title { font-size: 10px; font-weight: bold; margin-bottom: 2px; }
        .pra-badge .pra-number { font-size: 8px; font-weight: bold; word-wrap: break-word; }
        .local-badge { border: 1px dashed #666; padding: 4px; margin: 5px 0; text-align: center; font-size: 8px; color: #666; }
        .qr-code { text-align: center; margin: 5px 0; }
        .qr-code img { width: 85px; height: 85px; }
        .qr-code p { font-size: 7px; margin-top: 1px; }

        .footer { margin-top: 6px; font-size: 8px; line-height: 1.4; }

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
    <div class="no-print">
        <button onclick="window.print()" style="padding: 8px 24px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; margin-right: 8px;">Print</button>
        <a href="{{ route('pos.transactions') }}" style="padding: 8px 24px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-block;">Back</a>
    </div>
    <script>
        window.onafterprint = function() {
            if (window.opener) {
                window.close();
            } else {
                window.location.href = '{{ route('pos.transactions') }}';
            }
        };
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
    </table>

    <div class="separator"></div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-item">Item</th>
                <th class="col-qty">Qty</th>
                <th class="col-total">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transaction->items as $item)
            <tr>
                <td class="col-item">{{ $item->item_name }}@if($item->is_tax_exempt) <span class="exempt-tag">[E]</span>@endif</td>
                <td class="col-qty">{{ $item->quantity }}</td>
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
        @if(($transaction->exempt_amount ?? 0) > 0)
        <tr>
            <td class="tot-label">Exempt:</td>
            <td class="tot-value">{{ number_format($transaction->exempt_amount, 2) }}</td>
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
    <div class="local-badge">LOCAL INVOICE<br>(Not reported to PRA)</div>
    @endif

    <div class="footer text-center">
        <p>Thank you!</p>
        <p>NestPOS</p>
        <p>{{ now()->format('d/m/Y h:i A') }}</p>
    </div>
</body>
</html>
