<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $transaction->invoice_number }}</title>
    <style>
        @page { margin: 10mm 15mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #111827;
            line-height: 1.4;
            background: #fff;
        }
        .receipt { max-width: 100%; margin: 0 auto; }

        .header-bar {
            background-color: #1e3a5f;
            padding: 14px 18px 12px;
            text-align: center;
            margin-bottom: 10px;
        }
        .header-bar .logo { margin-bottom: 6px; }
        .header-bar .logo img { max-width: 110px; max-height: 40px; object-fit: contain; }
        .header-bar h1 { font-size: 15px; font-weight: bold; color: #ffffff; margin-bottom: 3px; letter-spacing: 1.5px; text-transform: uppercase; }
        .header-bar p { font-size: 9px; color: #d1d5db; line-height: 1.5; }

        .invoice-box {
            border: 1.5px solid #1e3a5f;
            padding: 6px 12px;
            margin: 0 0 8px;
        }
        .invoice-row { display: table; width: 100%; }
        .invoice-row .lbl { display: table-cell; width: 36%; font-size: 10px; font-weight: bold; padding: 2px 0; color: #111827; }
        .invoice-row .val { display: table-cell; width: 64%; font-size: 10px; text-align: right; padding: 2px 0; font-weight: bold; color: #111827; letter-spacing: 0.3px; }

        .info-section { padding: 4px 0; margin-bottom: 6px; border-bottom: 1px solid #d1d5db; }
        .info-row { display: table; width: 100%; }
        .info-row .lbl { display: table-cell; width: 28%; font-size: 9px; font-weight: bold; padding: 2px 0; color: #374151; text-transform: uppercase; letter-spacing: 0.3px; }
        .info-row .val { display: table-cell; width: 72%; font-size: 9.5px; text-align: right; padding: 2px 0; color: #111827; }

        .section-label { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #374151; margin-bottom: 3px; }

        table.items { width: 100%; border-collapse: collapse; margin: 4px 0; }
        table.items thead th {
            font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.5px;
            padding: 5px 4px; text-align: left; font-weight: bold; color: #ffffff; background-color: #1e3a5f;
        }
        table.items thead th.r { text-align: right; }
        table.items tbody td { font-size: 9.5px; padding: 4px 4px; vertical-align: top; border-bottom: 1px solid #e5e7eb; color: #111827; }
        table.items tbody tr:nth-child(even) { background-color: #f0f7ff; }
        table.items tbody td.r { text-align: right; white-space: nowrap; font-weight: 600; }

        .totals-box { border-top: 1.5px solid #1e3a5f; padding: 5px 0; margin: 5px 0; }
        .total-row { display: table; width: 100%; }
        .total-row .lbl { display: table-cell; text-align: left; font-size: 9.5px; padding: 2px 0; color: #374151; }
        .total-row .val { display: table-cell; text-align: right; font-size: 9.5px; padding: 2px 0; white-space: nowrap; color: #111827; font-weight: 600; }
        .total-row.discount .val { color: #dc2626; }

        .grand-total-box {
            background-color: #1e3a5f; padding: 8px 14px; margin: 3px 0 8px; display: table; width: 100%;
        }
        .grand-total-box .lbl { display: table-cell; text-align: left; font-size: 14px; font-weight: bold; color: #ffffff; vertical-align: middle; }
        .grand-total-box .val { display: table-cell; text-align: right; font-size: 14px; font-weight: bold; color: #ffffff; vertical-align: middle; }

        .fbr-box { border: 1.5px solid #1e3a5f; padding: 6px; margin: 5px 0; text-align: center; }
        .fbr-box .title { font-size: 10px; font-weight: bold; color: #1e3a5f; margin-bottom: 2px; letter-spacing: 0.5px; text-transform: uppercase; }
        .fbr-box .num { font-size: 9.5px; font-weight: bold; color: #111827; }
        .fbr-box div { color: #374151; font-size: 9px; }
        .local-box { border: 1px dashed #6b7280; padding: 5px; margin: 5px 0; text-align: center; font-size: 9px; color: #6b7280; }

        .footer { margin-top: 8px; text-align: center; padding-top: 6px; border-top: 1px solid #d1d5db; }
        .footer p { font-size: 8px; color: #9ca3af; line-height: 1.5; }
        .footer .brand { font-size: 9px; font-weight: bold; color: #1e3a5f; margin-top: 2px; }
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
        </div>

        <div class="invoice-box">
            <div class="invoice-row">
                <div class="lbl">FBR POS Invoice #:</div>
                <div class="val">{{ $transaction->invoice_number }}</div>
            </div>
            @if($transaction->fbr_invoice_number)
            <div class="invoice-row">
                <div class="lbl">FBR Invoice #:</div>
                <div class="val">{{ $transaction->fbr_invoice_number }}</div>
            </div>
            @endif
        </div>

        <div class="info-section">
            <div class="info-row">
                <div class="lbl">Date</div>
                <div class="val">{{ $transaction->created_at->format('d/m/Y h:i A') }}</div>
            </div>
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
            @if($transaction->customer_ntn)
            <div class="info-row">
                <div class="lbl">NTN</div>
                <div class="val">{{ $transaction->customer_ntn }}</div>
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
                    <td>{{ $item->item_name }}</td>
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
            <div class="total-row">
                <div class="lbl">Tax ({{ number_format($transaction->tax_rate, 0) }}%)</div>
                <div class="val">PKR {{ number_format($transaction->tax_amount, 2) }}</div>
            </div>
            @if($transaction->fbr_service_charge > 0)
            <div class="total-row">
                <div class="lbl">FBR Service Charge (SRO 1279/2021)</div>
                <div class="val">PKR {{ number_format($transaction->fbr_service_charge, 2) }}</div>
            </div>
            @endif
        </div>

        <div class="grand-total-box">
            <div class="lbl">TOTAL</div>
            <div class="val">PKR {{ number_format($transaction->total_amount, 2) }}</div>
        </div>

        @if($transaction->fbr_status === 'submitted' && $transaction->fbr_invoice_number)
        <div class="fbr-box">
            <div class="title">FBR Verified Invoice</div>
            <div>POS: {{ $transaction->invoice_number }}</div>
            <div class="num">FBR: {{ $transaction->fbr_invoice_number }}</div>
        </div>
        @elseif($transaction->fbr_status === 'local')
        <div class="local-box">
            LOCAL INVOICE (FBR Reporting OFF)<br>
            {{ $transaction->invoice_number }}
        </div>
        @else
        <div class="local-box">
            FBR PENDING — Will retry automatically<br>
            {{ $transaction->invoice_number }}
        </div>
        @endif

        <div class="footer">
            <p>Thank you for your purchase!</p>
            <div class="brand">Powered by TaxNest FBR POS</div>
            <p>{{ now()->format('d/m/Y h:i:s A') }}</p>
        </div>
    </div>
</body>
</html>
