<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $transaction->invoice_number }}</title>
    <style>
        @page { margin: 8mm 12mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000000;
            line-height: 1.5;
            background: #fff;
        }
        .receipt { max-width: 100%; margin: 0 auto; }

        .header-bar {
            background-color: #1e1b4b;
            padding: 16px 20px 14px;
            text-align: center;
            margin-bottom: 12px;
        }
        .header-bar .logo { margin-bottom: 6px; }
        .header-bar .logo img { max-width: 120px; max-height: 45px; object-fit: contain; }
        .header-bar h1 { font-size: 16px; font-weight: bold; color: #ffffff; margin-bottom: 4px; letter-spacing: 2px; text-transform: uppercase; }
        .header-bar p { font-size: 10px; color: #e5e7eb; line-height: 1.6; }

        .invoice-box {
            border: 2px solid #1e1b4b;
            padding: 8px 14px;
            margin: 0 0 10px;
        }
        .invoice-row { display: table; width: 100%; }
        .invoice-row .lbl { display: table-cell; width: 36%; font-size: 11px; font-weight: bold; padding: 3px 0; color: #000000; }
        .invoice-row .val { display: table-cell; width: 64%; font-size: 11px; text-align: right; padding: 3px 0; font-weight: bold; color: #000000; letter-spacing: 0.5px; }

        .info-section { padding: 6px 0; margin-bottom: 8px; border-bottom: 1.5px solid #9ca3af; }
        .info-row { display: table; width: 100%; }
        .info-row .lbl { display: table-cell; width: 28%; font-size: 10px; font-weight: bold; padding: 3px 0; color: #000000; text-transform: uppercase; letter-spacing: 0.3px; }
        .info-row .val { display: table-cell; width: 72%; font-size: 10px; text-align: right; padding: 3px 0; color: #000000; }

        .section-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #000000; margin-bottom: 4px; }

        table.items { width: 100%; border-collapse: collapse; margin: 6px 0; }
        table.items thead th {
            font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px;
            padding: 6px 5px; text-align: left; font-weight: bold; color: #ffffff; background-color: #1e1b4b;
        }
        table.items thead th.r { text-align: right; }
        table.items thead th.c { text-align: center; }
        table.items tbody td { font-size: 10px; padding: 5px 5px; vertical-align: top; border-bottom: 1px solid #d1d5db; color: #000000; }
        table.items tbody tr:nth-child(even) { background-color: #f5f3ff; }
        table.items tbody td.r { text-align: right; white-space: nowrap; font-weight: 700; }
        table.items tbody td.c { text-align: center; }
        .exempt-tag { font-size: 8px; font-weight: bold; color: #92400e; background: #fef3c7; padding: 1px 4px; border-radius: 2px; }

        .totals-box { border-top: 2px solid #1e1b4b; padding: 6px 0; margin: 6px 0; }
        .total-row { display: table; width: 100%; }
        .total-row .lbl { display: table-cell; text-align: left; font-size: 10px; padding: 3px 0; color: #000000; font-weight: 600; }
        .total-row .val { display: table-cell; text-align: right; font-size: 10px; padding: 3px 0; white-space: nowrap; color: #000000; font-weight: 700; }
        .total-row.discount .val { color: #dc2626; }

        .grand-total-box {
            background-color: #1e1b4b; padding: 10px 16px; margin: 4px 0 10px; display: table; width: 100%;
        }
        .grand-total-box .lbl { display: table-cell; text-align: left; font-size: 16px; font-weight: bold; color: #ffffff; vertical-align: middle; }
        .grand-total-box .val { display: table-cell; text-align: right; font-size: 16px; font-weight: bold; color: #ffffff; vertical-align: middle; }

        .pra-box { border: 2px solid #1e1b4b; padding: 8px; margin: 6px 0; text-align: center; }
        .pra-box .title { font-size: 11px; font-weight: bold; color: #1e1b4b; margin-bottom: 3px; letter-spacing: 0.5px; text-transform: uppercase; }
        .pra-box .num { font-size: 10px; font-weight: bold; color: #000000; }
        .pra-box div { color: #000000; font-size: 10px; }
        .local-box { border: 1.5px dashed #6b7280; padding: 6px; margin: 6px 0; text-align: center; font-size: 10px; color: #374151; font-weight: 600; }
        .qr-section { text-align: center; margin: 6px 0; }
        .qr-section img { width: 90px; height: 90px; }
        .qr-section p { font-size: 8px; margin-top: 2px; color: #374151; }

        .footer { margin-top: 10px; text-align: center; padding-top: 8px; border-top: 1.5px solid #9ca3af; }
        .footer p { font-size: 9px; color: #374151; line-height: 1.6; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #1e1b4b; margin-top: 3px; }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header-bar">
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

        <div class="info-section">
            <div class="info-row">
                <div class="lbl">Date</div>
                <div class="val">{{ $transaction->created_at->format('d/m/Y h:i A') }}</div>
            </div>
            @if($transaction->terminal)
            <div class="info-row">
                <div class="lbl">Terminal</div>
                <div class="val">{{ $transaction->terminal->terminal_name }}</div>
            </div>
            @endif
            <div class="info-row">
                <div class="lbl">Customer</div>
                <div class="val">{{ $transaction->customer_name ?? 'Walk-in Customer' }}</div>
            </div>
            @if($transaction->customer_phone)
            <div class="info-row">
                <div class="lbl">Phone</div>
                <div class="val">{{ $transaction->customer_phone }}</div>
            </div>
            @endif
            <div class="info-row">
                <div class="lbl">Payment</div>
                <div class="val">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</div>
            </div>
            @if($transaction->creator)
            <div class="info-row">
                <div class="lbl">Cashier</div>
                <div class="val">{{ $transaction->creator->name }}</div>
            </div>
            @endif
        </div>

        <div class="section-label">Order Items</div>
        <table class="items">
            <thead>
                <tr>
                    <th style="width:40%;">Item</th>
                    <th class="c" style="width:10%;">Qty</th>
                    <th class="r" style="width:10%;">Tax%</th>
                    <th class="r" style="width:20%;">Price</th>
                    <th class="r" style="width:20%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transaction->items as $item)
                <tr>
                    <td>
                        {{ $item->item_name }}
                        @if($item->is_tax_exempt)
                        <span class="exempt-tag">EXEMPT</span>
                        @endif
                    </td>
                    <td class="c">{{ $item->quantity }}</td>
                    <td class="r">{{ $item->is_tax_exempt ? 'Exempt' : number_format($item->tax_rate ?? $transaction->tax_rate, 0) . '%' }}</td>
                    <td class="r">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="r">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-box">
            <div class="total-row">
                <div class="lbl">Subtotal</div>
                <div class="val">PKR {{ number_format($transaction->subtotal, 2) }}</div>
            </div>
            @if($transaction->discount_amount > 0)
            <div class="total-row discount">
                <div class="lbl">Discount{{ $transaction->discount_type === 'percentage' ? ' ('.$transaction->discount_value.'%)' : '' }}</div>
                <div class="val">-PKR {{ number_format($transaction->discount_amount, 2) }}</div>
            </div>
            @endif
            <div class="total-row">
                <div class="lbl">Tax ({{ number_format($transaction->tax_rate, 0) }}%)</div>
                <div class="val">PKR {{ number_format($transaction->tax_amount, 2) }}</div>
            </div>
        </div>

        <div class="grand-total-box">
            <div class="lbl">TOTAL</div>
            <div class="val">PKR {{ number_format($transaction->total_amount, 2) }}</div>
        </div>

        @if($transaction->pra_status === 'submitted' && $transaction->pra_invoice_number)
        <div class="pra-box">
            <div class="title">✓ PRA Fiscal Invoice</div>
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
            OFFLINE INVOICE — Will sync to PRA automatically<br>
            {{ $transaction->invoice_number }}
        </div>
        @else
        <div class="local-box">
            LOCAL INVOICE (Not reported to PRA)<br>
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
