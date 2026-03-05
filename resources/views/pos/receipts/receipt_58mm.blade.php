<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $transaction->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; font-size: 10px; max-width: 220px; margin: 0 auto; padding: 6px; background: #fff; color: #000; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .separator { border-top: 1px dashed #000; margin: 6px 0; }
        .double-separator { border-top: 2px solid #000; margin: 6px 0; }
        .header { margin-bottom: 6px; }
        .header h1 { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
        .header p { font-size: 9px; }
        .info-row { display: flex; justify-content: space-between; margin: 1px 0; font-size: 9px; }
        .invoice-numbers { border: 1px solid #000; padding: 4px; margin: 6px 0; font-size: 9px; }
        .invoice-numbers .inv-row { display: flex; justify-content: space-between; margin: 1px 0; }
        .invoice-numbers .inv-label { font-weight: bold; }
        .items-table { width: 100%; margin: 4px 0; border-collapse: collapse; }
        .items-table th, .items-table td { text-align: left; padding: 1px 0; font-size: 9px; }
        .items-table th { font-size: 8px; text-transform: uppercase; border-bottom: 1px solid #000; }
        .items-table .qty { width: 22px; text-align: center; }
        .items-table .price, .items-table .total { text-align: right; }
        .totals { margin: 4px 0; }
        .totals .row { display: flex; justify-content: space-between; margin: 1px 0; font-size: 9px; }
        .totals .grand-total { font-size: 13px; font-weight: bold; }
        .footer { margin-top: 8px; font-size: 8px; }
        .pra-badge { border: 1px solid #000; padding: 4px; margin: 6px 0; text-align: center; font-size: 9px; }
        .pra-badge .pra-title { font-size: 10px; font-weight: bold; margin-bottom: 2px; }
        .pra-badge .pra-number { font-size: 9px; font-weight: bold; }
        .local-badge { border: 1px dashed #666; padding: 4px; margin: 6px 0; text-align: center; font-size: 9px; color: #666; }
        .qr-code { text-align: center; margin: 6px 0; }
        .qr-code img { width: 90px; height: 90px; }
        .qr-code p { font-size: 7px; margin-top: 1px; }
        @media print {
            body { max-width: 58mm; padding: 1mm; }
            .no-print { display: none !important; }
        }
        @media screen {
            .no-print { margin-bottom: 12px; text-align: center; font-family: Arial, sans-serif; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding: 8px 24px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; margin-right: 8px;">Print</button>
        <button onclick="window.close()" style="padding: 8px 24px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer;">Close</button>
    </div>

    <div class="header text-center">
        <h1>{{ Str::limit($company->name, 24) }}</h1>
        @if($company->phone)<p>{{ $company->phone }}</p>@endif
        @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="separator"></div>

    <div class="invoice-numbers">
        <div class="inv-row">
            <span class="inv-label">POS#:</span>
            <span>{{ $transaction->invoice_number }}</span>
        </div>
        @if($transaction->pra_invoice_number)
        <div class="inv-row">
            <span class="inv-label">PRA#:</span>
            <span style="font-size:8px">{{ $transaction->pra_invoice_number }}</span>
        </div>
        @endif
    </div>

    <div class="info-row"><span>Date:</span><span>{{ $transaction->created_at->format('d/m/Y H:i') }}</span></div>
    @if($transaction->terminal)
    <div class="info-row"><span>Terminal:</span><span>{{ Str::limit($transaction->terminal->terminal_name, 12) }}</span></div>
    @endif
    @if($transaction->customer_name)
    <div class="info-row"><span>Cust:</span><span>{{ Str::limit($transaction->customer_name, 14) }}</span></div>
    @endif
    <div class="info-row"><span>Pay:</span><span>{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</span></div>

    <div class="separator"></div>

    <table class="items-table">
        <thead>
            <tr><th>Item</th><th class="qty">Q</th><th class="total">Amt</th></tr>
        </thead>
        <tbody>
            @foreach($transaction->items as $item)
            <tr>
                <td>{{ Str::limit($item->item_name, 12) }}</td>
                <td class="qty">{{ $item->quantity }}</td>
                <td class="total">{{ number_format($item->subtotal) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="separator"></div>

    <div class="totals">
        <div class="row"><span>Subtotal:</span><span>{{ number_format($transaction->subtotal, 2) }}</span></div>
        @if($transaction->discount_amount > 0)
        <div class="row"><span>Disc:</span><span>-{{ number_format($transaction->discount_amount, 2) }}</span></div>
        @endif
        <div class="row"><span>Tax ({{ $transaction->tax_rate }}%):</span><span>{{ number_format($transaction->tax_amount, 2) }}</span></div>
        <div class="double-separator"></div>
        <div class="row grand-total"><span>TOTAL:</span><span>PKR {{ number_format($transaction->total_amount, 2) }}</span></div>
    </div>

    <div class="separator"></div>

    @if($transaction->pra_invoice_number)
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
    @else
    <div class="local-badge">LOCAL INVOICE</div>
    @endif

    <div class="footer text-center">
        <p>Thank you!</p>
        <p>NestPOS</p>
        <p>{{ now()->format('d/m/Y H:i') }}</p>
    </div>
</body>
</html>
