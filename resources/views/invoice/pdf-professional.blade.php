<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number ?? $invoice->id }}</title>
    <style>
        @page {
            margin: 14mm 16mm 12mm 16mm;
            size: A4 portrait;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', 'Helvetica', Arial, sans-serif;
            color: #111111;
            font-size: 11px;
            line-height: 1.45;
            width: 100%;
        }

        .header-row { width: 100%; margin-bottom: 6px; }
        .header-row table { width: 100%; border-collapse: collapse; }
        .header-row td { vertical-align: top; }
        .company-name { font-size: 20px; font-weight: 900; color: #111111; letter-spacing: 0.5px; }
        .company-info { font-size: 10px; color: #333333; margin-top: 2px; line-height: 1.5; }
        .company-info strong { font-weight: 700; }

        .status-pill {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.2px;
        }
        .pill-verified { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .pill-draft { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .pill-production { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .pill-failed { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .pill-pending { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }

        .divider { border: none; border-top: 2px solid #e5e7eb; margin: 10px 0; }
        .divider-dark { border: none; border-top: 2px solid #1f2937; margin: 8px 0; }

        .fbr-block { text-align: center; padding: 10px 0 8px 0; margin-bottom: 8px; }
        .fbr-block table { margin: 0 auto; border-collapse: collapse; }
        .fbr-block td { vertical-align: middle; }
        .fbr-badge {
            display: inline-block;
            width: 48px;
            height: 48px;
            background: #166534;
            border-radius: 6px;
            text-align: center;
            line-height: 48px;
            color: #ffffff;
            font-size: 17px;
            font-weight: 900;
            letter-spacing: 2px;
        }
        .fbr-text { margin-top: 3px; }
        .fbr-text-digital { font-size: 10px; font-weight: 800; color: #166534; letter-spacing: 1px; }
        .fbr-text-sub { font-size: 7px; font-weight: 700; color: #166534; letter-spacing: 0.5px; text-transform: uppercase; }
        .fbr-inv-no { font-size: 11px; color: #111111; font-weight: 800; margin-top: 5px; letter-spacing: 0.2px; }

        .doc-title { font-size: 16px; font-weight: 900; color: #111111; margin-bottom: 10px; letter-spacing: 0.5px; }

        .info-section { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
        .info-section td { vertical-align: top; }
        .info-heading {
            font-size: 9px;
            text-transform: uppercase;
            color: #ffffff;
            background: #1f2937;
            font-weight: 800;
            letter-spacing: 1.5px;
            padding: 4px 8px;
            margin-bottom: 6px;
        }
        .info-row { font-size: 10.5px; color: #111111; padding: 2px 0; line-height: 1.5; }
        .info-row strong { font-weight: 700; }
        .info-row-label { font-size: 10px; color: #555555; font-weight: 600; }
        .info-row-value { font-size: 10.5px; color: #111111; font-weight: 700; }

        .detail-table { width: 100%; border-collapse: collapse; }
        .detail-table td { padding: 3px 6px; font-size: 10px; }
        .detail-table .dt-label { color: #555555; font-weight: 600; text-align: left; }
        .detail-table .dt-value { color: #111111; font-weight: 700; text-align: right; }
        .detail-table tr { border-bottom: 1px solid #f3f4f6; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .items-table thead th {
            background: #1f2937;
            padding: 7px 6px;
            font-size: 9px;
            text-transform: uppercase;
            color: #ffffff;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-align: left;
            border: 1px solid #1f2937;
        }
        .items-table thead th.ar { text-align: right; }
        .items-table thead th.ac { text-align: center; }
        .items-table tbody td {
            padding: 6px;
            border: 1px solid #e5e7eb;
            font-size: 10px;
            color: #111111;
        }
        .items-table tbody tr:nth-child(even) td { background: #f9fafb; }
        .items-table tbody td.ar { text-align: right; font-weight: 700; }
        .items-table tbody td.ac { text-align: center; }
        .items-table tbody td.code {
            font-family: 'DejaVu Sans', 'Helvetica', sans-serif;
            font-size: 10px;
            font-weight: 700;
            color: #111111;
            letter-spacing: 0.3px;
        }
        .items-table tbody td.product { font-weight: 600; }
        .items-table tbody tr { page-break-inside: avoid; }

        .summary-table { width: 100%; border-collapse: collapse; }
        .summary-table td { vertical-align: top; }

        .totals-box { border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden; }
        .totals-box table { width: 100%; border-collapse: collapse; }
        .totals-box td { padding: 5px 10px; font-size: 10.5px; }
        .totals-box .t-label { text-align: right; color: #333333; font-weight: 600; width: 55%; }
        .totals-box .t-value { text-align: right; color: #111111; font-weight: 700; width: 45%; white-space: nowrap; }
        .totals-box tr { border-bottom: 1px solid #f3f4f6; }
        .totals-box tr.total-row { background: #1f2937; }
        .totals-box tr.total-row td { border-bottom: none; padding: 8px 10px; }
        .totals-box tr.total-row .t-label { color: #ffffff; font-size: 12px; font-weight: 800; }
        .totals-box tr.total-row .t-value { color: #ffffff; font-size: 14px; font-weight: 900; }

        .schedule-info { font-size: 9px; color: #555555; line-height: 1.5; margin-top: 6px; }
        .schedule-info strong { font-weight: 700; color: #333333; }

        .footer { margin-top: 14px; padding-top: 8px; border-top: 1.5px solid #d1d5db; text-align: center; }
        .footer-text { font-size: 8px; color: #6b7280; }
        .footer-brand { font-size: 9px; color: #374151; font-weight: 700; margin-top: 2px; }

        .watermark {
            position: fixed;
            top: 40%;
            left: 12%;
            font-size: 60px;
            color: rgba(156, 163, 175, 0.12);
            font-weight: bold;
            text-transform: uppercase;
            transform: rotate(-35deg);
            letter-spacing: 10px;
            z-index: 9999;
            pointer-events: none;
            white-space: nowrap;
        }
    </style>
</head>
<body>

    {{-- ===== COMPANY HEADER ===== --}}
    <div class="header-row">
        <table>
            <tr>
                <td style="width: 45%;">
                    @if($invoice->fbr_invoice_number)
                    <span class="status-pill pill-verified">FBR VERIFIED</span>
                    @elseif($invoice->status === 'draft')
                    <span class="status-pill pill-draft">DRAFT</span>
                    @elseif($invoice->status === 'locked')
                    <span class="status-pill pill-production">PRODUCTION</span>
                    @elseif($invoice->status === 'failed')
                    <span class="status-pill pill-failed">FAILED</span>
                    @elseif($invoice->status === 'pending_verification')
                    <span class="status-pill pill-pending">PENDING</span>
                    @endif
                </td>
                <td style="width: 55%; text-align: right;">
                    <div class="company-name">{{ $invoice->company->name ?? 'TaxNest' }}</div>
                    <div class="company-info">
                        @if($invoice->company->address)
                        {{ $invoice->company->address }}@if($invoice->company->city), {{ $invoice->company->city }}@endif<br>
                        @endif
                        @if($invoice->company->ntn)
                        <strong>NTN: {{ $invoice->company->ntn }}</strong><br>
                        @endif
                        @if($invoice->company->cnic && $invoice->company->cnic !== $invoice->company->ntn && $invoice->company->cnic !== $invoice->company->registration_no)
                        CNIC: {{ $invoice->company->cnic }}<br>
                        @endif
                        @if($invoice->company->registration_no)
                        Reg #: {{ $invoice->company->registration_no }}<br>
                        @endif
                        @if($invoice->company->phone)
                        Phone: {{ $invoice->company->phone }}
                        @endif
                        @if($invoice->company->mobile && $invoice->company->mobile !== $invoice->company->phone)
                        &nbsp;| Mobile: {{ $invoice->company->mobile }}
                        @endif
                        @if($invoice->company->email)
                        <br>{{ $invoice->company->email }}
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <hr class="divider">

    {{-- ===== FBR SECTION ===== --}}
    @if($invoice->fbr_invoice_number && !empty($qrBase64))
    <div class="fbr-block">
        <table>
            <tr>
                <td style="padding: 0 16px 0 0;">
                    @if(!empty($fbrLogoBase64))
                    <img src="{{ $fbrLogoBase64 }}" alt="FBR" style="width: 70px; height: auto;">
                    @else
                    <div style="text-align: center;">
                        <div class="fbr-badge">FBR</div>
                        <div class="fbr-text">
                            <div class="fbr-text-digital">DIGITAL</div>
                            <div class="fbr-text-sub">INVOICING SYSTEM</div>
                        </div>
                    </div>
                    @endif
                </td>
                <td style="padding: 0 0 0 16px;">
                    <img src="{{ $qrBase64 }}" alt="QR Code" style="width: 70px; height: 70px;">
                </td>
            </tr>
        </table>
        <div class="fbr-inv-no">Digital Invoice #: {{ $invoice->fbr_invoice_number }}</div>
    </div>
    <hr class="divider">
    @endif

    {{-- ===== DOCUMENT TITLE ===== --}}
    <div class="doc-title">{{ $invoice->document_type ?? 'Sale Invoice' }}</div>

    {{-- ===== BILL TO + INVOICE DETAILS ===== --}}
    <table class="info-section">
        <tr>
            <td style="width: 52%; padding-right: 14px;">
                <div class="info-heading">Bill To</div>
                <div style="padding: 4px 0;">
                    <div class="info-row" style="font-weight:800; font-size: 11px; text-transform: uppercase; color: #374151; margin-bottom: 2px;">
                        {{ $invoice->buyer_registration_type ?? 'UNREGISTERED' }}
                    </div>
                    <div class="info-row"><strong style="font-size: 12px;">{{ $invoice->buyer_name }}</strong></div>
                    @if($invoice->buyer_ntn)
                    <div class="info-row"><span class="info-row-label">NTN:</span> <strong>{{ $invoice->buyer_ntn }}</strong></div>
                    @endif
                    @if($invoice->buyer_cnic)
                    <div class="info-row"><span class="info-row-label">CNIC:</span> {{ $invoice->buyer_cnic }}</div>
                    @endif
                    @if($invoice->buyer_address)
                    <div class="info-row">{{ $invoice->buyer_address }}</div>
                    @endif
                    @if($invoice->destination_province)
                    <div class="info-row">{{ $invoice->destination_province }}, Pakistan</div>
                    @else
                    <div class="info-row">Pakistan</div>
                    @endif
                </div>
            </td>
            <td style="width: 48%; padding-left: 14px;">
                <div class="info-heading">Invoice Details</div>
                <table class="detail-table" style="margin-top: 4px;">
                    <tr>
                        <td class="dt-label">Invoice No.</td>
                        <td class="dt-value">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id }}</td>
                    </tr>
                    <tr>
                        <td class="dt-label">Date</td>
                        <td class="dt-value">{{ $invoice->created_at->format('d M Y') }}</td>
                    </tr>
                    <tr>
                        <td class="dt-label">Status</td>
                        <td class="dt-value">{{ $invoice->fbr_invoice_number ? 'FBR Verified' : ($invoice->status === 'locked' ? 'Production' : ucfirst($invoice->status)) }}</td>
                    </tr>
                    @if($invoice->document_type && $invoice->document_type !== 'Sale Invoice')
                    <tr>
                        <td class="dt-label">Type</td>
                        <td class="dt-value">{{ $invoice->document_type }}</td>
                    </tr>
                    @endif
                    @if($invoice->reference_invoice_number)
                    <tr>
                        <td class="dt-label">Ref Invoice</td>
                        <td class="dt-value">{{ $invoice->reference_invoice_number }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="dt-label">NTN</td>
                        <td class="dt-value">{{ $invoice->company->ntn ?? 'N/A' }}</td>
                    </tr>
                    @if($invoice->supplier_province)
                    <tr>
                        <td class="dt-label">From</td>
                        <td class="dt-value">{{ $invoice->supplier_province }}</td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- ===== ITEMS TABLE ===== --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 28px;">SR</th>
                <th style="width: 78px;">HS CODE</th>
                <th>DESCRIPTION</th>
                <th class="ac" style="width: 55px;">UNIT</th>
                <th class="ar" style="width: 40px;">QTY</th>
                <th class="ar" style="width: 70px;">RATE</th>
                <th class="ar" style="width: 80px;">AMOUNT</th>
                <th class="ar" style="width: 38px;">TAX</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            <tr>
                <td class="ac">{{ $index + 1 }}</td>
                <td class="code">{{ $item->hs_code }}</td>
                <td class="product">{{ $item->description }}</td>
                <td class="ac" style="font-size: 9px;">{{ $item->default_uom ?? 'PCS' }}</td>
                <td class="ar">{{ number_format($item->quantity, 0) }}</td>
                <td class="ar">{{ number_format($item->price, 2) }}</td>
                <td class="ar" style="font-weight: 800;">{{ number_format($item->price * $item->quantity, 2) }}</td>
                <td class="ar" style="font-size: 9px;">{{ number_format($item->tax_rate ?? 0, 0) }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ===== TOTALS SECTION ===== --}}
    <table class="summary-table">
        <tr>
            <td style="width: 50%; padding-right: 16px;">
                @php
                    $scheduleTypes = $invoice->items->pluck('schedule_type')->unique()->filter();
                    $sroNumbers = $invoice->items->pluck('sro_schedule_no')->unique()->filter();
                    $serialNumbers = $invoice->items->pluck('serial_no')->unique()->filter();
                @endphp
                @if($scheduleTypes->count() > 0 || $sroNumbers->count() > 0)
                <div class="schedule-info">
                    @if($scheduleTypes->count() > 0)
                    <strong>Schedule:</strong> {{ $scheduleTypes->map(fn($t) => ucfirst(str_replace('_', ' ', $t)))->join(', ') }}<br>
                    @endif
                    @if($sroNumbers->count() > 0)
                    <strong>SRO:</strong> {{ $sroNumbers->join(', ') }}<br>
                    @endif
                    @if($serialNumbers->count() > 0)
                    <strong>Serial No:</strong> {{ $serialNumbers->join(', ') }}
                    @endif
                </div>
                @endif
            </td>
            <td style="width: 50%;">
                <div class="totals-box">
                    <table>
                        <tr>
                            <td class="t-label">Sub Total</td>
                            <td class="t-value">PKR {{ number_format($subtotal, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="t-label">Sales Tax (GST)</td>
                            <td class="t-value">PKR {{ number_format($totalTax, 2) }}</td>
                        </tr>
                        @php $totalFurtherTax = $invoice->items->sum('further_tax'); @endphp
                        @if($totalFurtherTax > 0)
                        <tr>
                            <td class="t-label">Further Tax (4%)</td>
                            <td class="t-value" style="color: #ea580c;">PKR {{ number_format($totalFurtherTax, 2) }}</td>
                        </tr>
                        @endif
                        @if(($wht_rate ?? 0) > 0)
                        <tr>
                            <td class="t-label">WHT ({{ $wht_rate }}%)</td>
                            <td class="t-value">PKR {{ number_format($wht_amount ?? 0, 2) }}</td>
                        </tr>
                        @endif
                        <tr class="total-row">
                            <td class="t-label">TOTAL</td>
                            <td class="t-value">PKR {{ number_format(($wht_rate ?? 0) > 0 ? ($net_receivable ?? $invoice->total_amount) : $invoice->total_amount, 2) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    {{-- ===== FOOTER ===== --}}
    <div class="footer">
        <div class="footer-text">This is a computer-generated invoice. | {{ now()->format('d M Y, h:i A') }}</div>
        <div class="footer-brand">TaxNest — Tax &amp; Invoice Management System</div>
    </div>

    {{-- ===== WATERMARKS ===== --}}
    @if(!empty($isDraft) && $isDraft)
    <div class="watermark">DRAFT</div>
    @endif

    @if(!empty($showWatermark) && $showWatermark)
    <div class="watermark" style="color: rgba(239, 68, 68, 0.10); font-size: 44px;">SUBSCRIPTION EXPIRED</div>
    @endif

</body>
</html>