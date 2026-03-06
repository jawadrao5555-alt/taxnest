<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $transaction->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; font-size: 12px; max-width: 302px; margin: 0 auto; padding: 10px; background: #fff; color: #000; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .separator { border-top: 1px dashed #000; margin: 8px 0; }
        .double-separator { border-top: 2px solid #000; margin: 8px 0; }
        .header { margin-bottom: 10px; }
        .header h1 { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .header p { font-size: 10px; }
        .info-row { display: flex; justify-content: space-between; margin: 2px 0; font-size: 11px; }
        .invoice-numbers { border: 1px solid #000; padding: 6px; margin: 8px 0; font-size: 10px; }
        .invoice-numbers .inv-row { display: flex; justify-content: space-between; margin: 2px 0; }
        .invoice-numbers .inv-label { font-weight: bold; }
        .items-table { width: 100%; margin: 5px 0; border-collapse: collapse; }
        .items-table th, .items-table td { text-align: left; padding: 2px 0; font-size: 11px; }
        .items-table th { font-size: 10px; text-transform: uppercase; border-bottom: 1px solid #000; }
        .items-table .qty { width: 30px; text-align: center; }
        .items-table .price, .items-table .total { text-align: right; }
        .totals { margin: 5px 0; }
        .totals .row { display: flex; justify-content: space-between; margin: 2px 0; font-size: 11px; }
        .totals .grand-total { font-size: 16px; font-weight: bold; }
        .footer { margin-top: 10px; font-size: 10px; }
        .pra-badge { border: 2px solid #000; padding: 8px; margin: 8px 0; text-align: center; font-size: 10px; }
        .pra-badge .pra-title { font-size: 12px; font-weight: bold; margin-bottom: 4px; }
        .pra-badge .pra-number { font-size: 11px; font-weight: bold; letter-spacing: 0.5px; }
        .local-badge { border: 1px dashed #666; padding: 5px; margin: 8px 0; text-align: center; font-size: 10px; color: #666; }
        .qr-code { text-align: center; margin: 8px 0; }
        .qr-code img { width: 120px; height: 120px; }
        .qr-code p { font-size: 8px; margin-top: 2px; }
        @media print {
            body { max-width: 80mm; padding: 2mm; }
            .no-print { display: none !important; }
        }
        @media screen {
            .no-print { margin-bottom: 15px; text-align: center; font-family: Arial, sans-serif; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding: 10px 30px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; margin-right: 10px;">Print Receipt</button>
        <a href="{{ route('pos.transactions') }}" style="padding: 10px 30px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block;">Back to Transactions</a>
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
        <div style="margin-bottom: 6px;"><img src="{{ asset('storage/' . $company->logo_path) }}" alt="{{ $company->name }}" style="max-width: 160px; max-height: 60px; margin: 0 auto; display: block; object-fit: contain;"></div>
        @endif
        <h1>{{ $company->name }}</h1>
        @if($company->address)<p>{{ $company->address }}</p>@endif
        @if($company->phone)<p>Tel: {{ $company->phone }}</p>@endif
        @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="separator"></div>

    <div class="invoice-numbers">
        <div class="inv-row">
            <span class="inv-label">POS Invoice #:</span>
            <span>{{ $transaction->invoice_number }}</span>
        </div>
        @if($transaction->pra_invoice_number)
        <div class="inv-row">
            <span class="inv-label">PRA Fiscal #:</span>
            <span>{{ $transaction->pra_invoice_number }}</span>
        </div>
        @endif
    </div>

    <div class="info-row"><span>Date:</span><span>{{ $transaction->created_at->format('d/m/Y H:i') }}</span></div>
    @if($transaction->terminal)
    <div class="info-row"><span>Terminal:</span><span>{{ $transaction->terminal->terminal_name }}</span></div>
    @endif
    @if($transaction->customer_name)
    <div class="info-row"><span>Customer:</span><span>{{ $transaction->customer_name }}</span></div>
    @endif
    <div class="info-row"><span>Payment:</span><span>{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</span></div>

    <div class="separator"></div>

    <table class="items-table">
        <thead>
            <tr><th>Item</th><th class="qty">Qty</th><th class="price">Price</th><th class="total">Total</th></tr>
        </thead>
        <tbody>
            @foreach($transaction->items as $item)
            <tr>
                <td>{{ Str::limit($item->item_name, 16) }}</td>
                <td class="qty">{{ $item->quantity }}</td>
                <td class="price">{{ number_format($item->unit_price) }}</td>
                <td class="total">{{ number_format($item->subtotal) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="separator"></div>

    <div class="totals">
        <div class="row"><span>Subtotal:</span><span>PKR {{ number_format($transaction->subtotal, 2) }}</span></div>
        @if($transaction->discount_amount > 0)
        <div class="row"><span>Discount{{ $transaction->discount_type === 'percentage' ? ' ('.$transaction->discount_value.'%)' : '' }}:</span><span>-PKR {{ number_format($transaction->discount_amount, 2) }}</span></div>
        @endif
        <div class="row"><span>Tax ({{ $transaction->tax_rate }}%):</span><span>PKR {{ number_format($transaction->tax_amount, 2) }}</span></div>
        <div class="double-separator"></div>
        <div class="row grand-total"><span>TOTAL:</span><span>PKR {{ number_format($transaction->total_amount, 2) }}</span></div>
    </div>

    <div class="separator"></div>

    @if($transaction->pra_status === 'submitted' && $transaction->pra_invoice_number)
    <div class="pra-badge">
        <div class="pra-title">PRA FISCAL INVOICE</div>
        <div>POS Invoice #: {{ $transaction->invoice_number }}</div>
        <div class="pra-number">PRA #: {{ $transaction->pra_invoice_number }}</div>
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
    <div class="local-badge">
        LOCAL INVOICE<br>
        (Not reported to PRA)<br>
        {{ $transaction->invoice_number }}
    </div>
    @endif

    <div class="footer text-center">
        <p>Thank you for your purchase!</p>
        <p>Powered by NestPOS</p>
        <p>{{ now()->format('d/m/Y H:i:s') }}</p>
    </div>
</body>
</html>
