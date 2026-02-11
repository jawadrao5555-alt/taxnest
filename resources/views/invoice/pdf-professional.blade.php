<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number ?? $invoice->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1f2937; font-size: 13px; line-height: 1.5; }
        .page { padding: 40px 50px; position: relative; }

        .header-bar { width: 100%; border-bottom: 3px solid #059669; padding-bottom: 20px; margin-bottom: 25px; }
        .header-bar table { width: 100%; }
        .company-name { font-size: 22px; font-weight: bold; color: #059669; letter-spacing: -0.5px; }
        .company-detail { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .invoice-title { font-size: 26px; font-weight: bold; color: #1f2937; text-align: right; letter-spacing: 2px; }
        .invoice-meta { font-size: 12px; color: #6b7280; text-align: right; margin-top: 3px; }
        .status-badge { display: inline-block; padding: 3px 14px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .status-draft { background: #f3f4f6; color: #6b7280; }
        .status-submitted { background: #dbeafe; color: #1e40af; }
        .status-locked { background: #d1fae5; color: #065f46; }

        .fbr-header { background: #f0fdf4; border: 2px solid #059669; border-radius: 6px; padding: 12px 20px; margin-bottom: 20px; text-align: center; }
        .fbr-header-title { font-size: 16px; font-weight: bold; color: #065f46; letter-spacing: 2px; }
        .fbr-header-sub { font-size: 11px; color: #047857; margin-top: 3px; }
        .fbr-number { font-size: 14px; font-weight: bold; color: #065f46; margin-top: 6px; }

        .info-grid { width: 100%; margin-bottom: 25px; }
        .info-grid td { width: 50%; vertical-align: top; }
        .info-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px 18px; }
        .info-label { font-size: 10px; text-transform: uppercase; color: #9ca3af; font-weight: bold; letter-spacing: 1px; margin-bottom: 6px; }
        .info-value { font-size: 13px; color: #1f2937; }
        .info-value strong { font-weight: 700; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table thead th { background: #f3f4f6; padding: 10px 14px; text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; font-weight: 700; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .items-table thead th.text-right { text-align: right; }
        .items-table tbody td { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; font-size: 12px; color: #374151; }
        .items-table tbody td.text-right { text-align: right; }
        .items-table tbody td.mono { font-family: 'Courier New', monospace; font-size: 11px; }

        .totals-table { width: 100%; margin-bottom: 25px; }
        .totals-inner { width: 320px; float: right; }
        .totals-inner table { width: 100%; border-collapse: collapse; }
        .totals-inner td { padding: 6px 14px; font-size: 12px; }
        .totals-inner td.label { text-align: right; color: #6b7280; font-weight: 600; }
        .totals-inner td.value { text-align: right; color: #1f2937; font-weight: 600; }
        .totals-inner tr.grand-total td { border-top: 2px solid #059669; padding-top: 10px; }
        .totals-inner tr.grand-total td.value { font-size: 16px; font-weight: 800; color: #059669; }
        .totals-inner tr.net td { background: #f0fdf4; border-radius: 4px; }

        .qr-section { border: 2px solid #059669; border-radius: 6px; padding: 15px; margin-bottom: 20px; text-align: center; page-break-inside: avoid; }
        .qr-badge { display: inline-block; background: #d1fae5; color: #065f46; padding: 4px 16px; border-radius: 12px; font-size: 11px; font-weight: bold; margin-bottom: 8px; }
        .qr-details { width: 100%; margin-top: 8px; }
        .qr-details td { padding: 3px 8px; font-size: 11px; }
        .qr-details td.qlabel { color: #6b7280; text-align: left; width: 30%; }
        .qr-details td.qvalue { color: #1f2937; font-weight: bold; text-align: left; }
        .hash-bar { margin-top: 10px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; word-break: break-all; font-family: 'Courier New', monospace; }

        .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 10px; color: #9ca3af; }
        .footer p { margin-bottom: 3px; }

        .watermark { position: fixed; top: 40%; left: 15%; font-size: 60px; color: rgba(156, 163, 175, 0.15); font-weight: bold; text-transform: uppercase; transform: rotate(-35deg); letter-spacing: 10px; z-index: 9999; pointer-events: none; white-space: nowrap; }
        .watermark-expired { color: rgba(239, 68, 68, 0.12); font-size: 44px; }

        .clearfix::after { content: ""; display: table; clear: both; }
    </style>
</head>
<body>
    <div class="page">

        @if($invoice->status === 'locked' && $invoice->fbr_invoice_id)
        <div class="fbr-header">
            <div class="fbr-header-title">FBR VERIFIED INVOICE</div>
            <div class="fbr-header-sub">Federal Board of Revenue &mdash; Government of Pakistan</div>
            <div class="fbr-number">FBR Invoice #: {{ $invoice->fbr_invoice_number ?? $invoice->fbr_invoice_id }}</div>
        </div>
        @endif

        <div class="header-bar">
            <table>
                <tr>
                    <td style="width: 60%; vertical-align: top;">
                        <div class="company-name">{{ $invoice->company->name ?? 'TaxNest' }}</div>
                        <div class="company-detail">NTN: {{ $invoice->company->ntn ?? 'N/A' }}</div>
                        @if($invoice->company->address)
                        <div class="company-detail">{{ $invoice->company->address }}</div>
                        @endif
                        @if($invoice->company->phone)
                        <div class="company-detail">{{ $invoice->company->phone }}</div>
                        @endif
                        @if($invoice->company->email)
                        <div class="company-detail">{{ $invoice->company->email }}</div>
                        @endif
                    </td>
                    <td style="width: 40%; vertical-align: top; text-align: right;">
                        <div class="invoice-title">{{ strtoupper($invoice->document_type ?? 'INVOICE') }}</div>
                        <div class="invoice-meta">#{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</div>
@if($invoice->fbr_invoice_number)
<div class="invoice-meta" style="color: #059669; font-weight: bold;">FBR: {{ $invoice->fbr_invoice_number }}</div>
@endif
                        <div class="invoice-meta">{{ $invoice->created_at->format('d M Y') }}</div>
                        <div style="margin-top: 8px;">
                            <span class="status-badge status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <table class="info-grid">
            <tr>
                <td style="padding-right: 10px;">
                    <div class="info-box">
                        <div class="info-label">Bill To</div>
                        <div class="info-value"><strong>{{ $invoice->buyer_name }}</strong></div>
                        <div class="info-value">NTN: {{ $invoice->buyer_ntn }}</div>
                        @if($invoice->buyer_registration_type)
                        <div class="info-value">Registration: <strong>{{ $invoice->buyer_registration_type }}</strong></div>
                        @endif
                        @if($invoice->destination_province)
                        <div class="info-value">Destination: <strong>{{ $invoice->destination_province }}</strong></div>
                        @endif
                    </div>
                </td>
                <td style="padding-left: 10px;">
                    <div class="info-box">
                        <div class="info-label">Invoice Details</div>
                        <div class="info-value">Internal #: <strong>{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</strong></div>
@if($invoice->fbr_invoice_number)
<div class="info-value">FBR #: <strong style="color: #059669;">{{ $invoice->fbr_invoice_number }}</strong></div>
@endif
                        <div class="info-value">Date: <strong>{{ $invoice->created_at->format('d M Y') }}</strong></div>
                        @if($invoice->document_type && $invoice->document_type !== 'Sale Invoice')
                        <div class="info-value">Type: <strong style="color: #d97706;">{{ $invoice->document_type }}</strong></div>
                        @endif
                        @if($invoice->reference_invoice_number)
                        <div class="info-value">Ref Invoice: <strong>{{ $invoice->reference_invoice_number }}</strong></div>
                        @endif
                        @if($invoice->supplier_province)
                        <div class="info-value">From: <strong>{{ $invoice->supplier_province }}</strong></div>
                        @endif
                        @if($invoice->submission_mode)
                        <div class="info-value">Mode: <strong>{{ ucfirst($invoice->submission_mode) }}</strong></div>
                        @endif
                    </div>
                </td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>HS Code</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Tax</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $index => $item)
                @php $lineTotal = ($item->price * $item->quantity) + $item->tax; @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="mono">{{ $item->hs_code }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">Rs. {{ number_format($item->price, 2) }}</td>
                    <td class="text-right">Rs. {{ number_format($item->tax, 2) }}</td>
                    <td class="text-right" style="font-weight: 600;">Rs. {{ number_format($lineTotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="clearfix">
            <div class="totals-inner">
                <table>
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value">Rs. {{ number_format($subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Sales Tax</td>
                        <td class="value">Rs. {{ number_format($totalTax, 2) }}</td>
                    </tr>
                    <tr class="grand-total">
                        <td class="label" style="font-size: 14px;">Total</td>
                        <td class="value">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="label">WHT {{ $invoice->wht_rate ? '(' . $invoice->wht_rate . '%)' : '' }}</td>
                        <td class="value">Rs. {{ number_format($invoice->wht_amount ?? 0, 2) }}</td>
                    </tr>
                    <tr class="net">
                        <td class="label" style="font-weight: 800;">Net Receivable</td>
                        <td class="value" style="font-weight: 800; color: #059669;">Rs. {{ number_format($invoice->net_receivable ?? $invoice->total_amount, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        @if($invoice->qr_data)
        @php $qrInfo = json_decode($invoice->qr_data, true); @endphp
        <div class="qr-section">
            <div class="qr-badge">FBR Verified</div>
            @if($invoice->qr_image_url)
            <div style="margin: 8px 0;">
                <img src="{{ $invoice->qr_image_url }}" alt="QR Code" style="width: 120px; height: 120px; display: inline-block;">
            </div>
            @endif
            <table class="qr-details">
                <tr><td class="qlabel">NTN</td><td class="qvalue">{{ $qrInfo['ntn'] ?? '' }}</td></tr>
                <tr><td class="qlabel">Invoice #</td><td class="qvalue">{{ $qrInfo['invoice_number'] ?? '' }}</td></tr>
                <tr><td class="qlabel">FBR ID</td><td class="qvalue">{{ $qrInfo['fbr_invoice_id'] ?? '' }}</td></tr>
                <tr><td class="qlabel">Date</td><td class="qvalue">{{ $qrInfo['date'] ?? '' }}</td></tr>
                <tr><td class="qlabel">Total</td><td class="qvalue">Rs. {{ number_format($qrInfo['total'] ?? 0, 2) }}</td></tr>
            </table>
            @if($invoice->integrity_hash)
            <div class="hash-bar">SHA256 Hash: {{ $invoice->integrity_hash }}</div>
            @endif
        </div>
        @endif

        <div class="footer">
            <p>This is a system generated invoice. No signature is required.</p>
            <p>Generated by TaxNest &mdash; Pakistan's Smart FBR Compliance Platform</p>
        </div>

        @if(!empty($isDraft) && $isDraft)
        <div class="watermark">DRAFT COPY</div>
        @endif

        @if(!empty($showWatermark) && $showWatermark)
        <div class="watermark watermark-expired">Subscription Expired</div>
        @endif
    </div>
</body>
</html>
