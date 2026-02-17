<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number ?? $invoice->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1f2937; font-size: 13px; line-height: 1.5; }
        .page { padding: 30px 40px; position: relative; }

        .company-header { width: 100%; margin-bottom: 12px; }
        .company-header table { width: 100%; }
        .company-name { font-size: 22px; font-weight: bold; color: #1f2937; text-align: right; }
        .company-province { font-size: 12px; color: #6b7280; text-align: right; margin-top: 2px; }

        .fbr-section { text-align: center; padding: 14px 0; margin-bottom: 16px; border-bottom: 1px solid #e5e7eb; }
        .fbr-badge-row { display: inline-block; }
        .fbr-badge-row table { margin: 0 auto; }
        .fbr-logo-box { display: inline-block; text-align: center; vertical-align: middle; }
        .fbr-shield { display: inline-block; width: 52px; height: 52px; background: #166534; border-radius: 6px; text-align: center; line-height: 52px; color: #ffffff; font-size: 20px; font-weight: 900; letter-spacing: 2px; }
        .fbr-label { margin-top: 2px; }
        .fbr-label-digital { font-size: 11px; font-weight: 800; color: #166534; letter-spacing: 1px; }
        .fbr-label-invoicing { font-size: 8px; font-weight: 700; color: #166534; letter-spacing: 0.5px; text-transform: uppercase; }
        .di-number { font-size: 11px; color: #374151; font-weight: 600; margin-top: 8px; letter-spacing: 0.3px; }

        .header-details { width: 100%; margin-bottom: 18px; }
        .header-details table { width: 100%; }
        .company-detail { font-size: 11px; color: #6b7280; margin-top: 2px; }

        .doc-label { font-size: 16px; font-weight: bold; color: #1f2937; margin-bottom: 14px; }

        .info-grid { width: 100%; margin-bottom: 20px; }
        .info-grid td { vertical-align: top; }
        .info-label { font-size: 10px; text-transform: uppercase; color: #9ca3af; font-weight: bold; letter-spacing: 1px; margin-bottom: 6px; }
        .info-value { font-size: 12px; color: #1f2937; margin-top: 2px; }
        .info-value strong { font-weight: 700; }
        .inv-detail-label { font-size: 11px; color: #6b7280; font-weight: 600; }
        .inv-detail-value { font-size: 11px; color: #1f2937; font-weight: 700; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .items-table thead th { background: #f3f4f6; padding: 8px 10px; text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; font-weight: 700; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; }
        .items-table thead th.text-right { text-align: right; }
        .items-table tbody td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; font-size: 11px; color: #374151; }
        .items-table tbody tr:nth-child(even) td { background: #fafafa; }
        .items-table tbody td.text-right { text-align: right; }
        .items-table tbody td.mono { font-family: 'Courier New', monospace; font-size: 10px; }

        .totals-section { width: 100%; }
        .totals-section table { width: 100%; }
        .totals-inner { width: 280px; }
        .totals-inner table { width: 100%; border-collapse: collapse; }
        .totals-inner td { padding: 4px 10px; font-size: 12px; }
        .totals-inner td.label { text-align: right; color: #6b7280; font-weight: 600; }
        .totals-inner td.value { text-align: right; color: #1f2937; font-weight: 600; }
        .totals-inner tr.grand-total td { border-top: 2px solid #1f2937; padding-top: 8px; }
        .totals-inner tr.grand-total td.value { font-size: 14px; font-weight: 800; color: #1f2937; }
        .totals-inner tr.net td { background: #f0fdf4; border-radius: 4px; }

        .status-badge { display: inline-block; padding: 3px 14px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .status-draft { background: #f3f4f6; color: #6b7280; }

        .status-locked { background: #d1fae5; color: #065f46; }

        .watermark { position: fixed; top: 40%; left: 15%; font-size: 60px; color: rgba(156, 163, 175, 0.12); font-weight: bold; text-transform: uppercase; transform: rotate(-35deg); letter-spacing: 10px; z-index: 9999; pointer-events: none; white-space: nowrap; }

        .clearfix::after { content: ""; display: table; clear: both; }
    </style>
</head>
<body>
    <div class="page">

        {{-- Company Name - Top Right like fastaccounts --}}
        <div class="company-header">
            <table>
                <tr>
                    <td style="width: 50%; vertical-align: top;">
                        @if($invoice->fbr_invoice_number)
                        <span class="status-badge status-locked" style="background: #d1fae5; color: #065f46;">FBR VERIFIED</span>
                        @else
                        <span class="status-badge status-{{ $invoice->status }}">{{ strtoupper($invoice->status === 'locked' ? 'PRODUCTION' : $invoice->status) }}</span>
                        @endif
                    </td>
                    <td style="width: 50%; vertical-align: top; text-align: right;">
                        <div class="company-name">{{ $invoice->company->name ?? 'TaxNest' }}</div>
                        @if($invoice->company->address)
                        <div class="company-detail">{{ $invoice->company->address }}@if($invoice->company->city), {{ $invoice->company->city }}@endif</div>
                        @endif
                        @if($invoice->company->ntn)
                        <div class="company-detail">NTN: {{ $invoice->company->ntn }}</div>
                        @endif
                        @if($invoice->company->cnic && $invoice->company->cnic !== $invoice->company->ntn && $invoice->company->cnic !== $invoice->company->registration_no)
                        <div class="company-detail">CNIC: {{ $invoice->company->cnic }}</div>
                        @endif
                        @if($invoice->company->registration_no)
                        <div class="company-detail">Reg #: {{ $invoice->company->registration_no }}</div>
                        @endif
                        @if($invoice->company->phone)
                        <div class="company-detail">Phone: {{ $invoice->company->phone }}</div>
                        @endif
                        @if($invoice->company->mobile && $invoice->company->mobile !== $invoice->company->phone)
                        <div class="company-detail">Mobile: {{ $invoice->company->mobile }}</div>
                        @endif
                        @if($invoice->company->email)
                        <div class="company-detail">Email: {{ $invoice->company->email }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        {{-- FBR Digital Invoicing Section - Logo Left, QR Right, DI# Below --}}
        @if($invoice->fbr_invoice_number && !empty($qrBase64))
        <div class="fbr-section">
            <table style="width: auto; margin: 0 auto; border-collapse: collapse;">
                <tr>
                    <td style="text-align: center; vertical-align: middle; padding: 0 16px 0 0;">
                        @if(!empty($fbrLogoBase64))
                        <img src="{{ $fbrLogoBase64 }}" alt="FBR Digital Invoicing System" style="width: 85px; height: auto;">
                        @else
                        <div class="fbr-logo-box">
                            <div class="fbr-shield">FBR</div>
                            <div class="fbr-label">
                                <div class="fbr-label-digital">DIGITAL</div>
                                <div class="fbr-label-invoicing">INVOICING SYSTEM</div>
                            </div>
                        </div>
                        @endif
                    </td>
                    <td style="text-align: center; vertical-align: middle; padding: 0 0 0 16px;">
                        <img src="{{ $qrBase64 }}" alt="QR Code" style="width: 85px; height: 85px;">
                    </td>
                </tr>
            </table>
            <div class="di-number">Digital Invoice #: {{ $invoice->fbr_invoice_number }}</div>
        </div>
        @endif

        {{-- Invoice label + Details Row --}}
        <div class="doc-label">{{ $invoice->document_type ?? 'Invoice' }}</div>

        <table class="info-grid">
            <tr>
                <td style="width: 55%; padding-right: 12px;">
                    <div style="font-weight: 700; font-size: 13px; margin-bottom: 2px;">{{ $invoice->buyer_registration_type ?? 'UNREGISTERED' }}</div>
                    <div class="info-value"><strong>{{ $invoice->buyer_name }}</strong></div>
                    @if($invoice->buyer_ntn)
                    <div class="info-value">NTN: {{ $invoice->buyer_ntn }}</div>
                    @endif
                    @if($invoice->buyer_cnic)
                    <div class="info-value">CNIC: {{ $invoice->buyer_cnic }}</div>
                    @endif
                    @if($invoice->buyer_address)
                    <div class="info-value">{{ $invoice->buyer_address }}</div>
                    @endif
                    @if($invoice->destination_province)
                    <div class="info-value">{{ $invoice->destination_province }}</div>
                    @endif
                    <div class="info-value">Pakistan</div>
                </td>
                <td style="width: 45%; padding-left: 12px; vertical-align: top;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="padding: 2px 0;"><span class="inv-detail-label">Inv No.</span></td>
                            <td style="padding: 2px 0; text-align: right;"><span class="inv-detail-value">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id }}</span></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><span class="inv-detail-label">Date</span></td>
                            <td style="padding: 2px 0; text-align: right;"><span class="inv-detail-value">{{ $invoice->created_at->format('d/m/Y') }}</span></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;"><span class="inv-detail-label">Status</span></td>
                            <td style="padding: 2px 0; text-align: right;"><span class="inv-detail-value">{{ $invoice->fbr_invoice_number ? 'FBR Verified' : ($invoice->status === 'locked' ? 'Production' : ucfirst($invoice->status)) }}</span></td>
                        </tr>
                        @if($invoice->document_type && $invoice->document_type !== 'Sale Invoice')
                        <tr>
                            <td style="padding: 2px 0;"><span class="inv-detail-label">Type</span></td>
                            <td style="padding: 2px 0; text-align: right;"><span class="inv-detail-value">{{ $invoice->document_type }}</span></td>
                        </tr>
                        @endif
                        @if($invoice->reference_invoice_number)
                        <tr>
                            <td style="padding: 2px 0;"><span class="inv-detail-label">Ref Invoice</span></td>
                            <td style="padding: 2px 0; text-align: right;"><span class="inv-detail-value">{{ $invoice->reference_invoice_number }}</span></td>
                        </tr>
                        @endif
                        <tr>
                            <td style="padding: 2px 0;"><span class="inv-detail-label">NTN</span></td>
                            <td style="padding: 2px 0; text-align: right;"><span class="inv-detail-value">{{ $invoice->company->ntn ?? 'N/A' }}</span></td>
                        </tr>
                        @if($invoice->supplier_province)
                        <tr>
                            <td style="padding: 2px 0;"><span class="inv-detail-label">From</span></td>
                            <td style="padding: 2px 0; text-align: right;"><span class="inv-detail-value">{{ $invoice->supplier_province }}</span></td>
                        </tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        {{-- Items Table --}}
        <table class="items-table">
            <thead>
                <tr>
                    <th>SrNo</th>
                    <th>Code</th>
                    <th>Product Name</th>
                    <th>UM Unit</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Rate</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Discount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="mono">{{ $item->hs_code }}</td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->default_uom ?? 'PCS' }}</td>
                    <td class="text-right">{{ number_format($item->quantity, 0) }}</td>
                    <td class="text-right">Rs. {{ number_format($item->price, 4) }}</td>
                    <td class="text-right">Rs. {{ number_format($item->price * $item->quantity, 2) }}</td>
                    <td class="text-right">Rs. {{ number_format($item->discount ?? 0, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Totals Section --}}
        <div class="clearfix">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 55%;"></td>
                    <td style="width: 45%;">
                        <div class="totals-inner" style="width: 100%;">
                            <table>
                                <tr>
                                    <td class="label">Sub Total:</td>
                                    <td class="value">Rs. {{ number_format($subtotal, 2) }}</td>
                                </tr>
                                <tr>
                                    <td class="label">GST:</td>
                                    <td class="value">Rs. {{ number_format($totalTax, 2) }}</td>
                                </tr>
                                @if(($wht_rate ?? 0) > 0)
                                <tr>
                                    <td class="label">WHT ({{ $wht_rate }}%):</td>
                                    <td class="value">Rs. {{ number_format($wht_amount ?? 0, 2) }}</td>
                                </tr>
                                @endif
                                <tr class="grand-total">
                                    <td class="label" style="font-size: 13px;">Total:</td>
                                    <td class="value">Rs. {{ number_format(($wht_rate ?? 0) > 0 ? ($net_receivable ?? $invoice->total_amount) : $invoice->total_amount, 2) }}</td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>
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
