<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $transaction->invoice_number }}</title>
    <style>
        @page { margin: 12mm 18mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.5;
            background: #fff;
        }
        .receipt {
            max-width: 420px;
            margin: 0 auto;
            padding: 0;
        }

        .header-bar {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%);
            padding: 18px 20px 14px;
            text-align: center;
            border-radius: 0 0 12px 12px;
            margin-bottom: 12px;
        }
        .header-bar .logo { margin-bottom: 6px; }
        .header-bar .logo img { max-width: 130px; max-height: 45px; object-fit: contain; }
        .header-bar h1 { font-size: 17px; font-weight: bold; color: #fff; margin-bottom: 2px; letter-spacing: 1.5px; text-transform: uppercase; }
        .header-bar p { font-size: 9px; color: #e9d5ff; line-height: 1.5; }

        .invoice-box {
            background: #f5f3ff;
            border: 1.5px solid #c4b5fd;
            border-radius: 8px;
            padding: 8px 12px;
            margin: 0 0 10px;
        }
        .invoice-row { display: table; width: 100%; }
        .invoice-row .lbl { display: table-cell; width: 36%; font-size: 10px; font-weight: bold; padding: 2px 0; color: #6d28d9; }
        .invoice-row .val { display: table-cell; width: 64%; font-size: 10px; text-align: right; padding: 2px 0; font-weight: bold; color: #1e293b; letter-spacing: 0.3px; }

        .info-section {
            background: #fafafa;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 10px;
        }
        .info-row { display: table; width: 100%; }
        .info-row .lbl { display: table-cell; width: 28%; font-size: 9.5px; font-weight: bold; padding: 2px 0; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; }
        .info-row .val { display: table-cell; width: 72%; font-size: 10px; text-align: right; padding: 2px 0; color: #334155; }

        .section-label {
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #7c3aed;
            margin-bottom: 4px;
            padding-bottom: 3px;
            border-bottom: 1.5px solid #e9d5ff;
        }

        table.items { width: 100%; border-collapse: collapse; margin: 6px 0; }
        table.items thead th {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
            color: #fff;
            background: #7c3aed;
        }
        table.items thead th:first-child { border-radius: 6px 0 0 0; }
        table.items thead th:last-child { border-radius: 0 6px 0 0; }
        table.items thead th.r { text-align: right; }
        table.items tbody td {
            font-size: 10px;
            padding: 5px 4px;
            vertical-align: top;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        table.items tbody tr:nth-child(even) { background: #faf5ff; }
        table.items tbody td.r { text-align: right; white-space: nowrap; font-weight: 600; }
        table.items tbody tr:last-child td { border-bottom: none; }
        .exempt-tag { font-size: 7px; font-weight: bold; color: #d97706; background: #fef3c7; padding: 1px 4px; border-radius: 3px; }

        .totals-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            margin: 10px 0;
        }
        .total-row { display: table; width: 100%; }
        .total-row .lbl { display: table-cell; text-align: left; font-size: 10px; padding: 3px 0; color: #64748b; }
        .total-row .val { display: table-cell; text-align: right; font-size: 10px; padding: 3px 0; white-space: nowrap; color: #334155; font-weight: 600; }
        .total-row.discount .val { color: #dc2626; }
        .total-row.tax .lbl { color: #7c3aed; font-weight: bold; }
        .total-row.tax .val { color: #7c3aed; font-weight: bold; }

        .grand-total-box {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            border-radius: 8px;
            padding: 10px 14px;
            margin: 6px 0 10px;
            display: table;
            width: 100%;
        }
        .grand-total-box .lbl {
            display: table-cell;
            text-align: left;
            font-size: 15px;
            font-weight: bold;
            color: #fff;
            vertical-align: middle;
        }
        .grand-total-box .val {
            display: table-cell;
            text-align: right;
            font-size: 15px;
            font-weight: bold;
            color: #fff;
            vertical-align: middle;
        }

        .pra-box {
            border: 2px solid #059669;
            background: #ecfdf5;
            border-radius: 8px;
            padding: 10px;
            margin: 8px 0;
            text-align: center;
        }
        .pra-box .title { font-size: 11px; font-weight: bold; color: #059669; margin-bottom: 3px; letter-spacing: 0.5px; text-transform: uppercase; }
        .pra-box .num { font-size: 10px; font-weight: bold; color: #065f46; }
        .pra-box div { color: #047857; font-size: 9px; }
        .local-box {
            border: 1.5px dashed #94a3b8;
            background: #f8fafc;
            border-radius: 8px;
            padding: 8px;
            margin: 8px 0;
            text-align: center;
            font-size: 10px;
            color: #64748b;
        }
        .qr-section { text-align: center; margin: 8px 0; }
        .qr-section img { width: 100px; height: 100px; }
        .qr-section p { font-size: 8px; margin-top: 2px; color: #94a3b8; }

        .footer {
            margin-top: 12px;
            text-align: center;
            padding-top: 8px;
            border-top: 1.5px solid #e9d5ff;
        }
        .footer p { font-size: 9px; color: #94a3b8; line-height: 1.6; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #7c3aed; margin-top: 3px; }
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
                        <span class="exempt-tag">EXEMPT</span>
                        @endif
                    </td>
                    <td>{{ $item->quantity }}</td>
                    <td class="r">{{ number_format($item->unit_price, 0) }}</td>
                    <td class="r">{{ number_format($item->subtotal, 0) }}</td>
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
            <div class="total-row tax">
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
            <div class="title">PRA Fiscal Invoice</div>
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
