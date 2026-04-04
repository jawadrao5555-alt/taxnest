<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number ?? $invoice->id }}</title>
    <style>
        @page { margin: 12mm 14mm 10mm 14mm; size: A4 portrait; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', 'Helvetica', Arial, sans-serif; color: #000000; font-size: 10px; line-height: 1.4; width: 100%; }

        .page { width: 100%; max-width: 100%; overflow: hidden; }

        .company-header { width: 100%; margin-bottom: 8px; }
        .company-header table { width: 100%; table-layout: fixed; }
        .company-name { font-size: 18px; font-weight: bold; color: #000000; text-align: right; word-wrap: break-word; }
        .company-detail { font-size: 9px; color: #000000; margin-top: 1px; text-align: right; word-wrap: break-word; }

        .fbr-section { text-align: center; padding: 8px 0; margin-bottom: 10px; border-bottom: 2px solid #d1d5db; }
        .fbr-logo-box { display: inline-block; text-align: center; vertical-align: middle; }
        .fbr-shield { display: inline-block; width: 44px; height: 44px; background: #166534; border-radius: 4px; text-align: center; line-height: 44px; color: #ffffff; font-size: 16px; font-weight: 900; letter-spacing: 2px; }
        .fbr-label { margin-top: 2px; }
        .fbr-label-digital { font-size: 9px; font-weight: 800; color: #166534; letter-spacing: 1px; }
        .fbr-label-invoicing { font-size: 7px; font-weight: 700; color: #166534; letter-spacing: 0.5px; text-transform: uppercase; }
        .di-number { font-size: 10px; color: #000000; font-weight: 700; margin-top: 4px; letter-spacing: 0.3px; word-wrap: break-word; }

        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 8px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .status-draft { background: #e5e7eb; color: #374151; }
        .status-locked { background: #d1fae5; color: #065f46; }
        .status-failed { background: #fee2e2; color: #991b1b; }
        .status-pending_verification { background: #fef3c7; color: #92400e; }

        .doc-label { font-size: 14px; font-weight: bold; color: #000000; margin-bottom: 8px; }

        .info-grid { width: 100%; margin-bottom: 10px; table-layout: fixed; }
        .info-grid td { vertical-align: top; }
        .info-label { font-size: 8px; text-transform: uppercase; color: #374151; font-weight: bold; letter-spacing: 1px; margin-bottom: 2px; }
        .info-value { font-size: 10px; color: #000000; margin-top: 1px; word-wrap: break-word; }
        .info-value strong { font-weight: 700; }
        .inv-detail-label { font-size: 9px; color: #374151; font-weight: 700; }
        .inv-detail-value { font-size: 9px; color: #000000; font-weight: 700; word-wrap: break-word; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; table-layout: fixed; }
        .items-table thead th { background: #1f2937; padding: 5px 4px; text-align: left; font-size: 8px; text-transform: uppercase; color: #ffffff; font-weight: 700; letter-spacing: 0.3px; overflow: hidden; }
        .items-table thead th.text-right { text-align: right; }
        .items-table thead th.text-center { text-align: center; }
        .items-table tbody td { padding: 4px; border-bottom: 1px solid #d1d5db; font-size: 9px; color: #000000; overflow: hidden; word-wrap: break-word; }
        .items-table tbody tr:nth-child(even) td { background: #f9fafb; }
        .items-table tbody td.text-right { text-align: right; font-weight: 600; }
        .items-table tbody td.text-center { text-align: center; }
        .items-table tbody td.mono { font-family: 'Courier New', monospace; font-size: 8px; }
        .items-table tbody tr { page-break-inside: avoid; }

        .totals-section { width: 100%; margin-top: 4px; }
        .totals-section > table { width: 100%; table-layout: fixed; }
        .totals-inner { width: 100%; }
        .totals-inner table { width: 100%; border-collapse: collapse; }
        .totals-inner td { padding: 3px 6px; font-size: 10px; }
        .totals-inner td.label { text-align: right; color: #000000; font-weight: 600; }
        .totals-inner td.value { text-align: right; color: #000000; font-weight: 700; white-space: nowrap; }
        .totals-inner tr.grand-total td { border-top: 2px solid #000000; padding-top: 6px; }
        .totals-inner tr.grand-total td.value { font-size: 13px; font-weight: 800; color: #000000; }
        .totals-inner tr.net td { background: #f0fdf4; border-radius: 4px; }

        .watermark { position: fixed; top: 40%; left: 15%; font-size: 60px; color: rgba(156, 163, 175, 0.12); font-weight: bold; text-transform: uppercase; transform: rotate(-35deg); letter-spacing: 10px; z-index: 9999; pointer-events: none; white-space: nowrap; }

        .clearfix::after { content: ""; display: table; clear: both; }

        .footer-note { margin-top: 10px; padding-top: 8px; border-top: 1.5px solid #9ca3af; text-align: center; font-size: 8px; color: #374151; }
    </style>
</head>
<body>
    <div class="page">

        <div class="company-header">
            <table>
                <tr>
                    <td style="width: 50%; vertical-align: top;">
                        @if($invoice->fbr_invoice_number)
                        <span class="status-badge status-locked">FBR VERIFIED</span>
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
                        <div class="company-detail" style="font-weight:700;">NTN: {{ $invoice->company->ntn }}</div>
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

        @if($invoice->fbr_invoice_number && !empty($qrBase64))
        <div class="fbr-section">
            <table style="width: auto; margin: 0 auto; border-collapse: collapse;">
                <tr>
                    <td style="text-align: center; vertical-align: middle; padding: 0 12px 0 0;">
                        @if(!empty($fbrLogoBase64))
                        <img src="{{ $fbrLogoBase64 }}" alt="FBR Digital Invoicing System" style="width: 65px; height: auto;">
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
                    <td style="text-align: center; vertical-align: middle; padding: 0 0 0 12px;">
                        <img src="{{ $qrBase64 }}" alt="QR Code" style="width: 65px; height: 65px;">
                    </td>
                </tr>
            </table>
            <div class="di-number">Digital Invoice #: {{ $invoice->fbr_invoice_number }}</div>
        </div>
        @endif

        <div class="doc-label">{{ $invoice->document_type ?? 'Invoice' }}</div>

        <table class="info-grid">
            <tr>
                <td style="width: 55%; padding-right: 10px;">
                    <div class="info-label" style="margin-bottom:3px;">Bill To</div>
                    <div style="font-weight: 700; font-size: 10px; margin-bottom: 2px; color:#000000;">{{ $invoice->buyer_registration_type ?? 'UNREGISTERED' }}</div>
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
                <td style="width: 45%; padding-left: 10px; vertical-align: top;">
                    <div class="info-label" style="margin-bottom:3px;">Invoice Details</div>
                    <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                        <tr>
                            <td style="padding: 2px 0; width: 35%;"><span class="inv-detail-label">Inv No.</span></td>
                            <td style="padding: 2px 0; text-align: right; width: 65%;"><span class="inv-detail-value">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id }}</span></td>
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

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">Sr</th>
                    <th style="width: 11%;">Code</th>
                    <th style="width: 29%;">Product Name</th>
                    <th class="text-center" style="width: 8%;">Unit</th>
                    <th class="text-right" style="width: 7%;">Qty</th>
                    <th class="text-right" style="width: 13%;">Rate</th>
                    <th class="text-right" style="width: 14%;">Amount</th>
                    <th class="text-right" style="width: 6%;">Tax%</th>
                    <th class="text-right" style="width: 7%;">Disc</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="mono">{{ $item->hs_code }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="text-center">{{ $item->default_uom ?? 'PCS' }}</td>
                    <td class="text-right">{{ number_format($item->quantity, 0) }}</td>
                    <td class="text-right">{{ number_format($item->price, 2) }}</td>
                    <td class="text-right">{{ number_format($item->price * $item->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format($item->tax_rate ?? 0, 0) }}%</td>
                    <td class="text-right">{{ number_format($item->discount ?? 0, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="clearfix">
            <table style="width: 100%; table-layout: fixed;">
                <tr>
                    <td style="width: 55%; vertical-align: top;">
                        @if($invoice->items->count() > 0)
                        <div style="font-size: 8px; color: #6b7280; margin-top: 4px;">
                            @php
                                $scheduleTypes = $invoice->items->pluck('schedule_type')->unique()->filter();
                                $sroNumbers = $invoice->items->pluck('sro_schedule_no')->unique()->filter();
                            @endphp
                            @if($scheduleTypes->count() > 0)
                            <div>Schedule: {{ $scheduleTypes->map(fn($t) => ucfirst(str_replace('_', ' ', $t)))->join(', ') }}</div>
                            @endif
                            @if($sroNumbers->count() > 0)
                            <div>SRO: {{ $sroNumbers->join(', ') }}</div>
                            @endif
                        </div>
                        @endif
                    </td>
                    <td style="width: 45%;">
                        <div class="totals-inner">
                            <table>
                                <tr>
                                    <td class="label">Sub Total:</td>
                                    <td class="value">PKR {{ number_format($subtotal, 2) }}</td>
                                </tr>
                                <tr>
                                    <td class="label">GST:</td>
                                    <td class="value">PKR {{ number_format($totalTax, 2) }}</td>
                                </tr>
                                @php $totalFurtherTax = $invoice->items->sum('further_tax'); @endphp
                                @if($totalFurtherTax > 0)
                                <tr>
                                    <td class="label">Further Tax (4%):</td>
                                    <td class="value" style="color: #ea580c;">PKR {{ number_format($totalFurtherTax, 2) }}</td>
                                </tr>
                                @endif
                                @if(($wht_rate ?? 0) > 0)
                                <tr>
                                    <td class="label">WHT ({{ $wht_rate }}%):</td>
                                    <td class="value">PKR {{ number_format($wht_amount ?? 0, 2) }}</td>
                                </tr>
                                @endif
                                <tr class="grand-total">
                                    <td class="label" style="font-size: 11px;">Total:</td>
                                    <td class="value">PKR {{ number_format(($wht_rate ?? 0) > 0 ? ($net_receivable ?? $invoice->total_amount) : $invoice->total_amount, 2) }}</td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="footer-note">
            Generated by TaxNest — Tax & Invoice Management System | {{ now()->format('d/m/Y h:i A') }}
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