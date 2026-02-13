<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number ?? $invoice->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1f2937; font-size: 13px; line-height: 1.5; }
        .page { padding: 30px 40px; position: relative; }

        .top-stripe { height: 4px; background: #059669; margin-bottom: 20px; }

        .header-bar { width: 100%; border-bottom: 2px solid #059669; padding-bottom: 16px; margin-bottom: 20px; }
        .header-bar table { width: 100%; }
        .company-name { font-size: 20px; font-weight: bold; color: #059669; }
        .company-detail { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .status-badge { display: inline-block; padding: 3px 14px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .status-draft { background: #f3f4f6; color: #6b7280; }
        .status-submitted { background: #dbeafe; color: #1e40af; }
        .status-locked { background: #d1fae5; color: #065f46; }

        .info-grid { width: 100%; margin-bottom: 20px; }
        .info-grid td { width: 50%; vertical-align: top; }
        .info-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px 16px; }
        .info-label { font-size: 10px; text-transform: uppercase; color: #9ca3af; font-weight: bold; letter-spacing: 1px; margin-bottom: 6px; }
        .info-value { font-size: 12px; color: #1f2937; margin-top: 2px; }
        .info-value strong { font-weight: 700; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .items-table thead th { background: #f3f4f6; padding: 8px 12px; text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; font-weight: 700; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .items-table thead th.text-right { text-align: right; }
        .items-table tbody td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; font-size: 12px; color: #374151; }
        .items-table tbody tr:nth-child(even) td { background: #fafafa; }
        .items-table tbody td.text-right { text-align: right; }
        .items-table tbody td.mono { font-family: 'Courier New', monospace; font-size: 11px; }

        .totals-inner { width: 300px; float: right; }
        .totals-inner table { width: 100%; border-collapse: collapse; }
        .totals-inner td { padding: 5px 12px; font-size: 12px; }
        .totals-inner td.label { text-align: right; color: #6b7280; font-weight: 600; }
        .totals-inner td.value { text-align: right; color: #1f2937; font-weight: 600; }
        .totals-inner tr.grand-total td { border-top: 2px solid #059669; padding-top: 8px; }
        .totals-inner tr.grand-total td.value { font-size: 15px; font-weight: 800; color: #059669; }
        .totals-inner tr.net td { background: #f0fdf4; border-radius: 4px; }

        .footer { margin-top: 30px; padding-top: 12px; border-top: 2px solid #059669; text-align: center; }
        .footer-title { font-size: 16px; font-weight: bold; color: #059669; letter-spacing: 2px; }

        .watermark { position: fixed; top: 40%; left: 15%; font-size: 60px; color: rgba(156, 163, 175, 0.12); font-weight: bold; text-transform: uppercase; transform: rotate(-35deg); letter-spacing: 10px; z-index: 9999; pointer-events: none; white-space: nowrap; }

        .clearfix::after { content: ""; display: table; clear: both; }
    </style>
</head>
<body>
    <div class="page">

        <div class="top-stripe"></div>

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
                        @if($invoice->fbr_invoice_number)
                        @php
                            $qrData = json_encode([
                                'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $invoice->company->ntn ?? ''),
                                'fbr_invoice_number' => $invoice->fbr_invoice_number,
                                'invoiceDate' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
                                'totalValues' => $invoice->total_amount
                            ]);
                        @endphp
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data={{ urlencode($qrData) }}" alt="QR Code" style="width: 90px; height: 90px; display: inline-block;">
                        <div style="font-size: 9px; color: #059669; font-weight: bold; margin-top: 3px;">FBR Verified</div>
                        @else
                        <div style="margin-top: 6px;">
                            <span class="status-badge status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
                        </div>
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        <table class="info-grid">
            <tr>
                <td style="padding-right: 8px;">
                    <div class="info-box">
                        <div class="info-label">Bill To</div>
                        <div class="info-value"><strong>{{ $invoice->buyer_name }}</strong></div>
                        @if($invoice->buyer_ntn)
                        <div class="info-value">NTN: {{ $invoice->buyer_ntn }}</div>
                        @endif
                        @if($invoice->buyer_cnic)
                        <div class="info-value">CNIC: {{ $invoice->buyer_cnic }}</div>
                        @endif
                        @if($invoice->buyer_address)
                        <div class="info-value">Address: {{ $invoice->buyer_address }}</div>
                        @endif
                        @if($invoice->buyer_registration_type)
                        <div class="info-value">Registration: <strong>{{ $invoice->buyer_registration_type }}</strong></div>
                        @endif
                        @if($invoice->destination_province)
                        <div class="info-value">Destination: <strong>{{ $invoice->destination_province }}</strong></div>
                        @endif
                    </div>
                </td>
                <td style="padding-left: 8px;">
                    <div class="info-box">
                        <div class="info-label">Invoice Details</div>
                        <div class="info-value">Internal #: <strong>{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</strong></div>
                        @if($invoice->fbr_invoice_number)
                        <div class="info-value">FBR #: <strong style="color: #059669;">{{ $invoice->fbr_invoice_number }}</strong></div>
                        @endif
                        <div class="info-value">Date: <strong>{{ $invoice->created_at->format('d M Y') }}</strong></div>
                        <div class="info-value">Status: <strong>{{ ucfirst($invoice->status) }}</strong></div>
                        @if($invoice->document_type && $invoice->document_type !== 'Sale Invoice')
                        <div class="info-value">Type: <strong>{{ $invoice->document_type }}</strong></div>
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
                    <th class="text-right">MRP</th>
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
                    <td class="text-right">{{ ($item->schedule_type === '3rd_schedule' && $item->mrp) ? 'Rs. ' . number_format($item->mrp, 2) : '—' }}</td>
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
                        <td class="label" style="font-size: 13px;">Total</td>
                        <td class="value">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
                    </tr>
                    @if(($wht_rate ?? 0) > 0)
                    <tr>
                        <td class="label">WHT ({{ $wht_rate }}%)</td>
                        <td class="value" style="color: #2563eb;">+ Rs. {{ number_format($wht_amount ?? 0, 2) }}</td>
                    </tr>
                    <tr class="net">
                        <td class="label" style="font-weight: 800;">Total with WHT</td>
                        <td class="value" style="font-weight: 800; color: #059669;">Rs. {{ number_format($net_receivable ?? $invoice->total_amount, 2) }}</td>
                    </tr>
                    @endif
                </table>
            </div>
        </div>

        <div class="footer">
            <div class="footer-title">{{ strtoupper($invoice->document_type ?? 'SALE INVOICE') }}</div>
        </div>

        @if(!empty($isDraft) && $isDraft)
        <div class="watermark">DRAFT</div>
        @endif

        @if(!empty($showWatermark) && $showWatermark)
        <div class="watermark" style="color: rgba(239, 68, 68, 0.10); font-size: 44px;">SUBSCRIPTION EXPIRED</div>
        @endif
    </div>
</body>
</html>
