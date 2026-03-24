<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $transaction->invoice_number }}</title>
    <style>
        @page { margin: 15mm 20mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 11px;
            color: #000;
            line-height: 1.5;
            background: #fff;
        }
        .receipt {
            max-width: 380px;
            margin: 0 auto;
            padding: 0;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .separator { border-top: 1px dashed #000; margin: 8px 0; }
        .double-separator { border-top: 2px solid #000; margin: 8px 0; }

        .header { margin-bottom: 6px; text-align: center; }
        .header .logo { margin-bottom: 6px; }
        .header .logo img { max-width: 140px; max-height: 50px; object-fit: contain; }
        .header h1 { font-size: 16px; font-weight: bold; margin-bottom: 2px; letter-spacing: 1px; }
        .header p { font-size: 10px; line-height: 1.5; color: #333; }

        .invoice-box { border: 1px solid #000; padding: 6px 8px; margin: 8px 0; }
        .invoice-row { display: table; width: 100%; }
        .invoice-row .lbl { display: table-cell; width: 38%; font-size: 10px; font-weight: bold; padding: 1px 0; }
        .invoice-row .val { display: table-cell; width: 62%; font-size: 10px; text-align: right; padding: 1px 0; font-family: 'Courier New', monospace; }

        .info-row { display: table; width: 100%; }
        .info-row .lbl { display: table-cell; width: 30%; font-size: 10px; font-weight: bold; padding: 2px 0; white-space: nowrap; }
        .info-row .val { display: table-cell; width: 70%; font-size: 10px; text-align: right; padding: 2px 0; }

        table.items { width: 100%; border-collapse: collapse; margin: 6px 0; }
        table.items thead th {
            font-size: 10px;
            text-transform: uppercase;
            border-bottom: 1px solid #000;
            padding: 3px 2px;
            text-align: left;
            font-weight: bold;
        }
        table.items thead th.r { text-align: right; }
        table.items tbody td {
            font-size: 10px;
            padding: 3px 2px;
            vertical-align: top;
            border-bottom: 1px dotted #ccc;
        }
        table.items tbody td.r { text-align: right; white-space: nowrap; }
        table.items tbody tr:last-child td { border-bottom: none; }
        .exempt-tag { font-size: 8px; font-weight: bold; }

        .totals { margin: 6px 0; }
        .total-row { display: table; width: 100%; }
        .total-row .lbl { display: table-cell; text-align: left; font-size: 10px; padding: 2px 0; }
        .total-row .val { display: table-cell; text-align: right; font-size: 10px; padding: 2px 0; white-space: nowrap; }
        .grand-total .lbl { font-size: 14px; font-weight: bold; padding-top: 4px; }
        .grand-total .val { font-size: 14px; font-weight: bold; padding-top: 4px; }

        .pra-box { border: 2px solid #000; padding: 8px; margin: 8px 0; text-align: center; }
        .pra-box .title { font-size: 11px; font-weight: bold; margin-bottom: 3px; letter-spacing: 0.5px; }
        .pra-box .num { font-size: 10px; font-weight: bold; }
        .local-box { border: 1px dashed #666; padding: 6px; margin: 8px 0; text-align: center; font-size: 10px; color: #666; }
        .qr-section { text-align: center; margin: 8px 0; }
        .qr-section img { width: 100px; height: 100px; }
        .qr-section p { font-size: 8px; margin-top: 2px; color: #666; }

        .footer { margin-top: 10px; text-align: center; font-size: 9px; color: #555; line-height: 1.6; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #000; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            @if($company->logo_path)
            <div class="logo">
                <img src="{{ public_path('storage/' . $company->logo_path) }}" alt="{{ $company->name }}">
            </div>
            @endif
            <h1>{{ $company->name }}</h1>
            @if($company->address)<p>{{ $company->address }}</p>@endif
            @if($company->phone)<p>Tel: {{ $company->phone }}</p>@endif
            @if($company->email)<p>{{ $company->email }}</p>@endif
            @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
            @if($company->pra_pos_id)<p>POS Reg: {{ $company->pra_pos_id }}</p>@endif
        </div>

        <div class="separator"></div>

        <div class="invoice-box">
            <div class="invoice-row">
                <div class="lbl">POS Invoice #:</div>
                <div class="val">{{ $transaction->invoice_number }}</div>
            </div>
            @if($transaction->pra_invoice_number)
            <div class="invoice-row">
                <div class="lbl">PRA Fiscal #:</div>
                <div class="val">{{ $transaction->pra_invoice_number }}</div>
            </div>
            @endif
        </div>

        <div class="info-row">
            <div class="lbl">Date:</div>
            <div class="val">{{ $transaction->created_at->format('d/m/Y h:i A') }}</div>
        </div>
        @if($transaction->terminal)
        <div class="info-row">
            <div class="lbl">Terminal:</div>
            <div class="val">{{ $transaction->terminal->terminal_name }}</div>
        </div>
        @endif
        <div class="info-row">
            <div class="lbl">Customer:</div>
            <div class="val">{{ $transaction->customer_name ?? 'Walk-in Customer' }}</div>
        </div>
        @if($transaction->customer_phone)
        <div class="info-row">
            <div class="lbl">Phone:</div>
            <div class="val">{{ $transaction->customer_phone }}</div>
        </div>
        @endif
        <div class="info-row">
            <div class="lbl">Payment:</div>
            <div class="val">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</div>
        </div>
        @if($transaction->creator)
        <div class="info-row">
            <div class="lbl">Cashier:</div>
            <div class="val">{{ $transaction->creator->name }}</div>
        </div>
        @endif

        <div class="separator"></div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:44%;">Item</th>
                    <th style="width:10%;">Qty</th>
                    <th class="r" style="width:22%;">Price</th>
                    <th class="r" style="width:24%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transaction->items as $item)
                <tr>
                    <td>
                        {{ $item->item_name }}
                        @if($item->is_tax_exempt)
                        <span class="exempt-tag">[EXEMPT]</span>
                        @endif
                    </td>
                    <td>{{ $item->quantity }}</td>
                    <td class="r">{{ number_format($item->unit_price, 0) }}</td>
                    <td class="r">{{ number_format($item->subtotal, 0) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="separator"></div>

        <div class="totals">
            <div class="total-row">
                <div class="lbl">Subtotal:</div>
                <div class="val">PKR {{ number_format($transaction->subtotal, 2) }}</div>
            </div>
            @if($transaction->discount_amount > 0)
            <div class="total-row">
                <div class="lbl">Discount{{ $transaction->discount_type === 'percentage' ? ' ('.$transaction->discount_value.'%)' : '' }}:</div>
                <div class="val">-PKR {{ number_format($transaction->discount_amount, 2) }}</div>
            </div>
            @endif
            <div class="total-row">
                <div class="lbl">Tax ({{ number_format($transaction->tax_rate, 0) }}%):</div>
                <div class="val">PKR {{ number_format($transaction->tax_amount, 2) }}</div>
            </div>
        </div>

        <div class="double-separator"></div>

        <div class="totals">
            <div class="total-row grand-total">
                <div class="lbl">TOTAL:</div>
                <div class="val">PKR {{ number_format($transaction->total_amount, 2) }}</div>
            </div>
        </div>

        <div class="separator"></div>

        @if($transaction->pra_status === 'submitted' && $transaction->pra_invoice_number)
        <div class="pra-box">
            <div class="title">PRA FISCAL INVOICE</div>
            <div>POS: {{ $transaction->invoice_number }}</div>
            <div class="num">PRA: {{ $transaction->pra_invoice_number }}</div>
        </div>
        @if($transaction->pra_qr_code)
        <div class="qr-section">
            <img src="{{ $transaction->pra_qr_code }}" alt="PRA QR">
            <p>Scan to verify on PRA portal</p>
        </div>
        @endif
        @elseif($transaction->pra_status === 'offline')
        <div class="local-box">
            OFFLINE INVOICE<br>
            Will sync to PRA automatically<br>
            {{ $transaction->invoice_number }}
        </div>
        @else
        <div class="local-box">
            LOCAL INVOICE<br>
            (Not reported to PRA)<br>
            {{ $transaction->invoice_number }}
        </div>
        @endif

        <div class="footer">
            <p>Thank you for your purchase!</p>
            <div class="brand">Powered by NestPOS</div>
            <p>{{ now()->format('d/m/Y h:i:s A') }}</p>
        </div>
    </div>
</body>
</html>
