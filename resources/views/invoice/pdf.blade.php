<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number ?? $invoice->id }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; color: #333; font-size: 13px; }
        .header { border-bottom: 3px solid #10b981; padding-bottom: 16px; margin-bottom: 24px; }
        .header table { width: 100%; }
        .company-name { font-size: 22px; font-weight: bold; color: #10b981; }
        .invoice-title { font-size: 26px; font-weight: bold; color: #333; text-align: right; letter-spacing: 2px; }
        .info-grid { width: 100%; margin-bottom: 24px; }
        .info-grid td { width: 50%; vertical-align: top; }
        .info-box { background: #f9fafb; padding: 14px; border-radius: 8px; border: 1px solid #e5e7eb; }
        .info-box h4 { font-size: 10px; text-transform: uppercase; color: #6b7280; margin: 0 0 8px 0; letter-spacing: 1px; font-weight: 700; }
        .info-box p { margin: 3px 0; font-size: 12px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.items th { background: #f3f4f6; padding: 10px; text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; font-weight: 700; border-bottom: 2px solid #e5e7eb; }
        table.items td { padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 12px; }
        table.items tr:nth-child(even) td { background: #fafafa; }
        .text-right { text-align: right; }
        .total-row td { background: #f0fdf4; font-size: 14px; font-weight: bold; color: #059669; border-top: 2px solid #059669; }
        .status { display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; }
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-submitted { background: #dbeafe; color: #1e40af; }
        .status-locked { background: #d1fae5; color: #065f46; }
        .fbr-section { border: 2px solid #059669; border-radius: 8px; padding: 16px; margin-bottom: 20px; page-break-inside: avoid; }
        .fbr-section table { width: 100%; border: none; }
        .fbr-section td { border: none; padding: 3px 8px; font-size: 12px; }
        .footer { margin-top: 30px; padding-top: 16px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width: 60%; vertical-align: top;">
                    <div class="company-name">{{ $invoice->company->name ?? 'TaxNest' }}</div>
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">NTN: {{ $invoice->company->ntn ?? 'N/A' }}</p>
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">{{ $invoice->company->address ?? '' }}</p>
                    <p style="margin: 3px 0; font-size: 12px; color: #6b7280;">{{ $invoice->company->phone ?? '' }}</p>
                </td>
                <td style="width: 40%; vertical-align: top; text-align: right;">
                    <div class="invoice-title">{{ strtoupper($invoice->document_type ?? 'SALE INVOICE') }}</div>
                    <p style="font-size: 13px; margin: 3px 0;">#{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</p>
                    <p style="font-size: 12px; color: #6b7280;">{{ $invoice->created_at->format('d M Y') }}</p>
                    <span class="status status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
                </td>
            </tr>
        </table>
    </div>

    <table class="info-grid">
        <tr>
            <td style="padding-right: 8px;">
                <div class="info-box">
                    <h4>Bill To</h4>
                    <p><strong>{{ $invoice->buyer_name }}</strong></p>
                    @if($invoice->buyer_ntn)
                    <p>NTN: {{ $invoice->buyer_ntn }}</p>
                    @endif
                    @if($invoice->buyer_cnic)
                    <p>CNIC: {{ $invoice->buyer_cnic }}</p>
                    @endif
                    @if($invoice->buyer_address)
                    <p>Address: {{ $invoice->buyer_address }}</p>
                    @endif
                </div>
            </td>
            <td style="padding-left: 8px;">
                <div class="info-box">
                    <h4>Invoice Details</h4>
                    <p>Internal #: <strong>{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</strong></p>
                    <p>Date: <strong>{{ $invoice->created_at->format('d M Y') }}</strong></p>
                    @if($invoice->submission_mode)
                    <p>Mode: <strong>{{ ucfirst($invoice->submission_mode) }}</strong></p>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
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
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td style="font-family: monospace; font-size: 11px;">{{ $item->hs_code }}</td>
                <td class="text-right">{{ $item->quantity }}</td>
                <td class="text-right">Rs. {{ number_format($item->price, 2) }}</td>
                <td class="text-right">Rs. {{ number_format($item->tax, 2) }}</td>
                <td class="text-right" style="font-weight: 600;">Rs. {{ number_format(($item->price * $item->quantity) + $item->tax, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="6" class="text-right"><strong>Grand Total</strong></td>
                <td class="text-right">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if($invoice->fbr_invoice_number)
    <div class="fbr-section">
        <table>
            <tr>
                <td colspan="2" style="text-align: center; padding-bottom: 10px;">
                    <div style="font-size: 14px; font-weight: bold; color: #065f46; letter-spacing: 1px;">FBR VERIFIED INVOICE</div>
                    <div style="font-size: 10px; color: #047857; margin-top: 2px;">Federal Board of Revenue &mdash; Government of Pakistan</div>
                </td>
            </tr>
            <tr>
                <td style="vertical-align: top; width: 55%;">
                    <table style="width: 100%; border: none;">
                        <tr><td style="border:none; padding:3px 8px; color:#6b7280; font-size:11px; width:35%;">Seller NTN</td><td style="border:none; padding:3px 8px; font-weight:bold; font-size:11px;">{{ $invoice->company->ntn ?? '' }}</td></tr>
                        <tr><td style="border:none; padding:3px 8px; color:#6b7280; font-size:11px;">FBR Invoice #</td><td style="border:none; padding:3px 8px; font-weight:bold; font-size:11px;">{{ $invoice->fbr_invoice_number }}</td></tr>
                        <tr><td style="border:none; padding:3px 8px; color:#6b7280; font-size:11px;">Invoice Date</td><td style="border:none; padding:3px 8px; font-weight:bold; font-size:11px;">{{ $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d') }}</td></tr>
                        <tr><td style="border:none; padding:3px 8px; color:#6b7280; font-size:11px;">Total Amount</td><td style="border:none; padding:3px 8px; font-weight:bold; font-size:11px;">Rs. {{ number_format($invoice->total_amount, 2) }}</td></tr>
                    </table>
                </td>
                <td style="vertical-align: top; width: 45%; text-align: center;">
                    @php
                        $qrData = json_encode([
                            'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $invoice->company->ntn ?? ''),
                            'fbr_invoice_number' => $invoice->fbr_invoice_number,
                            'invoiceDate' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
                            'totalValues' => $invoice->total_amount
                        ]);
                    @endphp
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data={{ urlencode($qrData) }}" alt="QR Code" style="width: 120px; height: 120px;">
                    <div style="font-size: 9px; color: #6b7280; margin-top: 4px;">Scan to verify</div>
                </td>
            </tr>
        </table>
        @if($invoice->integrity_hash)
        <div style="margin-top: 8px; padding-top: 6px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; word-break: break-all; font-family: monospace;">
            Hash: {{ $invoice->integrity_hash }}
        </div>
        @endif
    </div>
    @endif

    <div class="footer">
        <p>This is a system generated invoice. No signature is required.</p>
        <p>Generated by TaxNest &mdash; Pakistan's Smart FBR Compliance Platform</p>
    </div>

    @if(!empty($showWatermark) && $showWatermark)
    <div style="position: fixed; top: 40%; left: 15%; font-size: 48px; color: rgba(239, 68, 68, 0.10); font-weight: bold; text-transform: uppercase; transform: rotate(-35deg); pointer-events: none; z-index: 9999; white-space: nowrap; letter-spacing: 6px;">
        Subscription Expired
    </div>
    @endif

    @if(!empty($isDraft) && $isDraft)
    <div style="position: fixed; top: 40%; left: 20%; font-size: 60px; color: rgba(156, 163, 175, 0.12); font-weight: bold; text-transform: uppercase; transform: rotate(-35deg); pointer-events: none; z-index: 9999; white-space: nowrap; letter-spacing: 8px;">
        DRAFT
    </div>
    @endif
</body>
</html>
