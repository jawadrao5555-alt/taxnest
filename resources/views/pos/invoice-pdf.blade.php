<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $transaction->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 12px; color: #1a1a1a; line-height: 1.5; }
        .page { max-width: 750px; margin: 0 auto; padding: 40px; }
        .header { display: table; width: 100%; margin-bottom: 30px; border-bottom: 3px solid #7c3aed; padding-bottom: 20px; }
        .header-left { display: table-cell; vertical-align: top; width: 60%; }
        .header-right { display: table-cell; vertical-align: top; width: 40%; text-align: right; }
        .company-name { font-size: 22px; font-weight: bold; color: #1a1a1a; margin-bottom: 4px; }
        .company-info { font-size: 10px; color: #666; line-height: 1.6; }
        .invoice-title { font-size: 28px; font-weight: bold; color: #7c3aed; letter-spacing: 2px; }
        .invoice-meta { font-size: 10px; color: #666; margin-top: 6px; line-height: 1.8; }
        .invoice-meta strong { color: #333; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-submitted { background: #d1fae5; color: #065f46; }
        .badge-local { background: #f3f4f6; color: #6b7280; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-failed { background: #fee2e2; color: #991b1b; }
        .info-grid { display: table; width: 100%; margin-bottom: 25px; }
        .info-box { display: table-cell; width: 50%; vertical-align: top; }
        .info-box-right { padding-left: 20px; }
        .info-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #999; margin-bottom: 4px; }
        .info-value { font-size: 12px; color: #333; }
        .dual-invoice { display: table; width: 100%; margin-bottom: 25px; }
        .dual-invoice-cell { display: table-cell; width: 50%; padding: 12px; border: 1px solid #e5e7eb; }
        .dual-invoice-cell:first-child { border-right: none; border-radius: 6px 0 0 6px; }
        .dual-invoice-cell:last-child { border-radius: 0 6px 6px 0; }
        .dual-invoice-cell.pra-verified { background: #f0fdf4; border-color: #86efac; }
        .inv-num-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #999; margin-bottom: 2px; }
        .inv-num-value { font-size: 14px; font-weight: bold; color: #1a1a1a; font-family: 'Courier New', monospace; }
        .inv-num-value.pra { color: #059669; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        table.items thead th { background: #7c3aed; color: white; padding: 10px 12px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        table.items thead th:first-child { border-radius: 6px 0 0 0; }
        table.items thead th:last-child { border-radius: 0 6px 0 0; text-align: right; }
        table.items thead th.right { text-align: right; }
        table.items tbody td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; font-size: 11px; }
        table.items tbody td.right { text-align: right; }
        table.items tbody tr:last-child td { border-bottom: 2px solid #e5e7eb; }
        .exempt-badge { display: inline-block; background: #fef3c7; color: #92400e; font-size: 8px; font-weight: bold; padding: 1px 5px; border-radius: 3px; margin-left: 4px; }
        .summary-section { display: table; width: 100%; margin-bottom: 25px; }
        .summary-spacer { display: table-cell; width: 55%; }
        .summary-box { display: table-cell; width: 45%; }
        .summary-row { display: table; width: 100%; margin-bottom: 4px; }
        .summary-label { display: table-cell; text-align: left; padding: 4px 0; font-size: 11px; color: #666; }
        .summary-value { display: table-cell; text-align: right; padding: 4px 0; font-size: 11px; color: #333; font-weight: 500; }
        .summary-value.discount { color: #dc2626; }
        .summary-value.exempt { color: #d97706; }
        .summary-total { border-top: 2px solid #7c3aed; margin-top: 6px; padding-top: 8px; }
        .summary-total .summary-label { font-size: 14px; font-weight: bold; color: #1a1a1a; }
        .summary-total .summary-value { font-size: 16px; font-weight: bold; color: #7c3aed; }
        .pra-verified-box { background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 15px; text-align: center; margin-bottom: 25px; }
        .pra-verified-box .title { font-size: 12px; font-weight: bold; color: #059669; margin-bottom: 4px; }
        .pra-verified-box .subtitle { font-size: 9px; color: #6b7280; }
        .qr-center { text-align: center; margin: 10px 0; }
        .qr-center img { width: 100px; height: 100px; }
        .footer { border-top: 1px solid #e5e7eb; padding-top: 15px; margin-top: 30px; text-align: center; }
        .footer p { font-size: 9px; color: #999; margin-bottom: 2px; }
        .footer .powered { font-size: 10px; color: #7c3aed; font-weight: bold; margin-top: 8px; }
        .watermark { position: fixed; top: 45%; left: 15%; font-size: 80px; font-weight: bold; color: rgba(124, 58, 237, 0.04); transform: rotate(-35deg); letter-spacing: 15px; z-index: -1; }
    </style>
</head>
<body>
    <div class="watermark">NESTPOS</div>
    <div class="page">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $company->name }}</div>
                <div class="company-info">
                    @if($company->address){{ $company->address }}<br>@endif
                    @if($company->phone)Phone: {{ $company->phone }}@endif
                    @if($company->email) | {{ $company->email }}@endif
                    @if($company->ntn)<br>NTN: {{ $company->ntn }}@endif
                    @if($company->pra_pos_id)<br>POS Registration ID: {{ $company->pra_pos_id }}@endif
                </div>
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-meta">
                    <strong>Date:</strong> {{ $transaction->created_at->format('d M Y') }}<br>
                    <strong>Time:</strong> {{ $transaction->created_at->format('h:i A') }}<br>
                    <strong>PRA Status:</strong>
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

        <div class="dual-invoice">
            <div class="dual-invoice-cell">
                <div class="inv-num-label">POS Invoice Number (USIN)</div>
                <div class="inv-num-value">{{ $transaction->invoice_number }}</div>
            </div>
            <div class="dual-invoice-cell {{ $transaction->pra_invoice_number ? 'pra-verified' : '' }}">
                <div class="inv-num-label">PRA Fiscal Invoice Number</div>
                @if($transaction->pra_invoice_number)
                    <div class="inv-num-value pra">{{ $transaction->pra_invoice_number }}</div>
                @else
                    <div style="font-size: 11px; color: #999; font-style: italic;">Not submitted to PRA</div>
                @endif
            </div>
        </div>

        <div class="info-grid">
            <div class="info-box">
                <div class="info-label">Customer</div>
                <div class="info-value">{{ $transaction->customer_name ?? 'Walk-in Customer' }}</div>
                @if($transaction->customer_phone)
                <div class="info-value" style="color:#666; font-size:11px;">{{ $transaction->customer_phone }}</div>
                @endif
            </div>
            <div class="info-box info-box-right">
                <div class="info-label">Payment Method</div>
                <div class="info-value">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</div>
                @if($transaction->terminal)
                <div style="margin-top:8px;">
                    <div class="info-label">Terminal</div>
                    <div class="info-value">{{ $transaction->terminal->terminal_name }}</div>
                </div>
                @endif
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:40%;">Item</th>
                    <th style="width:10%;">Type</th>
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
                    <div class="summary-label">Tax Exempt Amount</div>
                    <div class="summary-value exempt">PKR {{ number_format($transaction->exempt_amount, 2) }}</div>
                </div>
                @endif
                <div class="summary-row">
                    <div class="summary-label">Tax ({{ number_format($transaction->tax_rate, 0) }}%)</div>
                    <div class="summary-value">PKR {{ number_format($transaction->tax_amount, 2) }}</div>
                </div>
                <div class="summary-row summary-total">
                    <div class="summary-label">Total Amount</div>
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
            <p>This is a computer-generated invoice.</p>
            <div class="powered">Powered by NestPOS — TaxNest Enterprise</div>
        </div>
    </div>
</body>
</html>
