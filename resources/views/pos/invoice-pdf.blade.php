<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $transaction->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 12px; color: #1a1a1a; line-height: 1.5; background: #fff; }
        .page { max-width: 750px; margin: 0 auto; padding: 30px 40px; }

        .header { display: table; width: 100%; margin-bottom: 20px; }
        .header-left { display: table-cell; vertical-align: middle; width: 55%; }
        .header-right { display: table-cell; vertical-align: middle; width: 45%; text-align: right; }
        .company-name { font-size: 20px; font-weight: bold; color: #1a1a1a; margin-bottom: 3px; }
        .company-info { font-size: 10px; color: #555; line-height: 1.7; }
        .invoice-title { font-size: 26px; font-weight: 800; color: #7c3aed; letter-spacing: 3px; text-transform: uppercase; }
        .invoice-number { font-size: 11px; color: #444; margin-top: 4px; font-family: 'Courier New', monospace; font-weight: 600; }
        .invoice-date { font-size: 10px; color: #777; margin-top: 2px; }

        .divider { height: 3px; background: linear-gradient(90deg, #7c3aed, #a855f7, #c084fc); margin: 0 0 20px 0; border-radius: 2px; }

        .meta-grid { display: table; width: 100%; margin-bottom: 20px; }
        .meta-col { display: table-cell; width: 33.33%; vertical-align: top; padding-right: 15px; }
        .meta-col:last-child { padding-right: 0; }
        .meta-heading { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: #7c3aed; margin-bottom: 5px; }
        .meta-text { font-size: 11px; color: #333; line-height: 1.6; }
        .meta-text-light { font-size: 10px; color: #777; }

        .dual-invoice { display: table; width: 100%; margin-bottom: 20px; }
        .dual-invoice-cell { display: table-cell; width: 50%; padding: 10px 14px; border: 1px solid #e5e7eb; background: #fafafa; }
        .dual-invoice-cell:first-child { border-right: none; border-radius: 8px 0 0 8px; }
        .dual-invoice-cell:last-child { border-radius: 0 8px 8px 0; }
        .dual-invoice-cell.pra-verified { background: #f0fdf4; border-color: #86efac; }
        .inv-num-label { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #999; margin-bottom: 3px; }
        .inv-num-value { font-size: 13px; font-weight: bold; color: #1a1a1a; font-family: 'Courier New', monospace; letter-spacing: 0.5px; }
        .inv-num-value.pra { color: #059669; }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; }
        .badge-submitted { background: #d1fae5; color: #065f46; }
        .badge-local { background: #f3f4f6; color: #6b7280; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-failed { background: #fee2e2; color: #991b1b; }

        table.items { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 20px; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
        table.items thead th { background: #7c3aed; color: white; padding: 10px 12px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 600; }
        table.items thead th:last-child { text-align: right; }
        table.items thead th.right { text-align: right; }
        table.items tbody td { padding: 9px 12px; border-bottom: 1px solid #f3f4f6; font-size: 11px; color: #333; }
        table.items tbody td.right { text-align: right; font-family: 'Courier New', monospace; font-size: 10.5px; }
        table.items tbody tr:last-child td { border-bottom: none; }
        table.items tbody tr:nth-child(even) td { background: #fafafa; }
        .exempt-badge { display: inline-block; background: #fef3c7; color: #92400e; font-size: 7px; font-weight: bold; padding: 1px 5px; border-radius: 3px; margin-left: 4px; vertical-align: middle; }

        .summary-section { display: table; width: 100%; margin-bottom: 20px; }
        .summary-spacer { display: table-cell; width: 55%; }
        .summary-box { display: table-cell; width: 45%; background: #fafafa; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; }
        .summary-row { display: table; width: 100%; margin-bottom: 3px; }
        .summary-label { display: table-cell; text-align: left; padding: 4px 0; font-size: 10px; color: #666; }
        .summary-value { display: table-cell; text-align: right; padding: 4px 0; font-size: 10px; color: #333; font-weight: 500; font-family: 'Courier New', monospace; }
        .summary-value.discount { color: #dc2626; }
        .summary-value.exempt { color: #d97706; }
        .summary-total { border-top: 2px solid #7c3aed; margin-top: 8px; padding-top: 8px; }
        .summary-total .summary-label { font-size: 13px; font-weight: 800; color: #1a1a1a; }
        .summary-total .summary-value { font-size: 14px; font-weight: 800; color: #7c3aed; }

        .pra-verified-box { background: linear-gradient(135deg, #f0fdf4, #ecfdf5); border: 1px solid #86efac; border-radius: 8px; padding: 15px; text-align: center; margin-bottom: 20px; }
        .pra-verified-box .title { font-size: 11px; font-weight: 700; color: #059669; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 3px; }
        .pra-verified-box .subtitle { font-size: 9px; color: #6b7280; }
        .qr-center { text-align: center; margin: 10px 0 0 0; }
        .qr-center img { width: 90px; height: 90px; }

        .footer { border-top: 2px solid #e5e7eb; padding-top: 15px; margin-top: 25px; text-align: center; }
        .footer p { font-size: 9px; color: #999; margin-bottom: 2px; }
        .footer .powered { font-size: 10px; color: #7c3aed; font-weight: 700; margin-top: 8px; letter-spacing: 0.5px; }
        .watermark { position: fixed; top: 45%; left: 15%; font-size: 80px; font-weight: bold; color: rgba(124, 58, 237, 0.03); transform: rotate(-35deg); letter-spacing: 15px; z-index: -1; }
    </style>
</head>
<body>
    <div class="watermark">NESTPOS</div>
    <div class="page">
        <div class="header">
            <div class="header-left">
                @if($company->logo_path)
                <div style="margin-bottom: 8px;">
                    <img src="{{ public_path('storage/' . $company->logo_path) }}" alt="{{ $company->name }}" style="max-width: 150px; max-height: 55px; object-fit: contain;">
                </div>
                @endif
                <div class="company-name">{{ $company->name }}</div>
                <div class="company-info">
                    @if($company->address){{ $company->address }}<br>@endif
                    @if($company->phone)Phone: {{ $company->phone }}@endif
                    @if($company->email) | {{ $company->email }}@endif
                    @if($company->ntn)<br>NTN: {{ $company->ntn }}@endif
                    @if($company->pra_pos_id)<br>POS Registration: {{ $company->pra_pos_id }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">{{ $transaction->invoice_number }}</div>
                <div class="invoice-date">{{ $transaction->created_at->format('d M Y — h:i A') }}</div>
                <div style="margin-top: 6px;">
                    @if($transaction->pra_status === 'submitted')
                        <span class="badge badge-submitted">PRA Verified</span>
                    @elseif($transaction->pra_status === 'pending')
                        <span class="badge badge-pending">Pending</span>
                    @elseif($transaction->pra_status === 'failed')
                        <span class="badge badge-failed">Failed</span>
                    @else
                        <span class="badge badge-local">Local</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="divider"></div>

        @if($transaction->pra_invoice_number || $transaction->invoice_number)
        <div class="dual-invoice">
            <div class="dual-invoice-cell">
                <div class="inv-num-label">POS Invoice (USIN)</div>
                <div class="inv-num-value">{{ $transaction->invoice_number }}</div>
            </div>
            <div class="dual-invoice-cell {{ $transaction->pra_invoice_number ? 'pra-verified' : '' }}">
                <div class="inv-num-label">PRA Fiscal Invoice</div>
                @if($transaction->pra_invoice_number)
                    <div class="inv-num-value pra">{{ $transaction->pra_invoice_number }}</div>
                @else
                    <div style="font-size: 10px; color: #bbb; font-style: italic;">Not submitted to PRA</div>
                @endif
            </div>
        </div>
        @endif

        <div class="meta-grid">
            <div class="meta-col">
                <div class="meta-heading">Customer</div>
                <div class="meta-text">{{ $transaction->customer_name ?? 'Walk-in Customer' }}</div>
                @if($transaction->customer_phone)
                <div class="meta-text-light">{{ $transaction->customer_phone }}</div>
                @endif
            </div>
            <div class="meta-col">
                <div class="meta-heading">Payment</div>
                <div class="meta-text">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</div>
                @if($transaction->terminal)
                <div class="meta-text-light">Terminal: {{ $transaction->terminal->terminal_name }}</div>
                @endif
            </div>
            <div class="meta-col">
                <div class="meta-heading">Served By</div>
                @if($transaction->creator)
                <div class="meta-text">{{ $transaction->creator->name }}</div>
                @else
                <div class="meta-text-light">—</div>
                @endif
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:38%;">Description</th>
                    <th style="width:12%;">Type</th>
                    <th class="right" style="width:10%;">Qty</th>
                    <th class="right" style="width:15%;">Unit Price</th>
                    <th class="right" style="width:20%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transaction->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>
                        {{ $item->item_name }}
                        @if($item->is_tax_exempt)
                        <span class="exempt-badge">EXEMPT</span>
                        @endif
                    </td>
                    <td>{{ ucfirst($item->item_type) }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="right">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="summary-section">
            <div class="summary-spacer"></div>
            <div class="summary-box">
                <div class="summary-row">
                    <div class="summary-label">Subtotal</div>
                    <div class="summary-value">PKR {{ number_format($transaction->subtotal, 2) }}</div>
                </div>
                @if($transaction->discount_amount > 0)
                <div class="summary-row">
                    <div class="summary-label">Discount {{ $transaction->discount_type === 'percentage' ? '(' . $transaction->discount_value . '%)' : '(Fixed)' }}</div>
                    <div class="summary-value discount">-PKR {{ number_format($transaction->discount_amount, 2) }}</div>
                </div>
                @endif
                @if(($transaction->exempt_amount ?? 0) > 0)
                <div class="summary-row">
                    <div class="summary-label">Tax Exempt</div>
                    <div class="summary-value exempt">PKR {{ number_format($transaction->exempt_amount, 2) }}</div>
                </div>
                @endif
                <div class="summary-row">
                    <div class="summary-label">Tax ({{ number_format($transaction->tax_rate, 0) }}%)</div>
                    <div class="summary-value">PKR {{ number_format($transaction->tax_amount, 2) }}</div>
                </div>
                <div class="summary-row summary-total">
                    <div class="summary-label">Total</div>
                    <div class="summary-value">PKR {{ number_format($transaction->total_amount, 2) }}</div>
                </div>
            </div>
        </div>

        @if($transaction->pra_invoice_number)
        <div class="pra-verified-box">
            <div class="title">PRA Verified Invoice</div>
            <div class="subtitle">This invoice has been reported to Punjab Revenue Authority</div>
            @if($transaction->pra_qr_code)
            <div class="qr-center">
                <img src="{{ $transaction->pra_qr_code }}" alt="PRA QR">
            </div>
            @endif
        </div>
        @endif

        <div class="footer">
            <p>Thank you for your business!</p>
            <p>This is a computer-generated invoice and does not require a signature.</p>
            <div class="powered">Powered by NestPOS — TaxNest Enterprise</div>
        </div>
    </div>
</body>
</html>
