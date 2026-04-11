<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number ?? $invoice->id }}</title>
    <style>
        @page {
            margin: 12mm 14mm 10mm 14mm;
            size: A4 portrait;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', 'Helvetica', Arial, sans-serif;
            color: #000000;
            font-size: 11px;
            line-height: 1.5;
            width: 100%;
        }

        .top-band {
            background: #0f172a;
            color: #ffffff;
            padding: 16px 20px 14px 20px;
        }
        .top-band table { width: 100%; border-collapse: collapse; }
        .top-band td { vertical-align: middle; }
        .b-name { font-size: 22px; font-weight: 900; letter-spacing: 1px; color: #ffffff; }
        .b-sub { font-size: 9px; color: #cbd5e1; margin-top: 2px; line-height: 1.6; }
        .b-sub strong { color: #ffffff; }
        .inv-label { font-size: 24px; font-weight: 900; color: #38bdf8; letter-spacing: 2px; text-align: right; }
        .inv-num { font-size: 10px; color: #cbd5e1; text-align: right; margin-top: 2px; font-weight: 600; }

        .color-bar { height: 4px; background: #0ea5e9; }

        .section-title {
            font-size: 8px;
            text-transform: uppercase;
            color: #000000;
            font-weight: 800;
            letter-spacing: 1.5px;
            border-bottom: 2px solid #000000;
            padding-bottom: 3px;
            margin-bottom: 5px;
        }

        .info-block { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 8px; }
        .info-block > tbody > tr > td { vertical-align: top; padding: 0; }

        .card {
            background: #ffffff;
            border: 1px solid #000000;
            padding: 8px 10px;
        }
        .card-name { font-size: 12px; font-weight: 800; color: #000000; margin-bottom: 2px; }
        .card-row { font-size: 9.5px; color: #000000; line-height: 1.6; }
        .card-row strong { color: #000000; font-weight: 800; }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            font-size: 7px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #ffffff;
            margin-bottom: 4px;
        }
        .tag-verified { background: #059669; }
        .tag-registered { background: #0ea5e9; }
        .tag-unregistered { background: #64748b; }
        .tag-draft { background: #94a3b8; }
        .tag-production { background: #3b82f6; }
        .tag-failed { background: #ef4444; }
        .tag-pending { background: #f59e0b; }

        .dtable { width: 100%; border-collapse: collapse; }
        .dtable td { padding: 3px 0; font-size: 9.5px; border-bottom: 1px dashed #cccccc; }
        .dtable .dl { color: #000000; font-weight: 700; }
        .dtable .dv { color: #000000; font-weight: 700; text-align: right; }

        .fbr-section {
            text-align: center;
            padding: 8px 0;
            margin-bottom: 6px;
            border-bottom: 1px solid #000000;
        }
        .fbr-section table { margin: 0 auto; border-collapse: collapse; }
        .fbr-section td { vertical-align: middle; }
        .fbr-inv-label { font-size: 10px; color: #000000; font-weight: 800; margin-top: 4px; letter-spacing: 0.2px; }

        .items { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .items thead th {
            background: #ffffff;
            padding: 7px 6px;
            font-size: 8px;
            text-transform: uppercase;
            color: #000000;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-align: left;
            border: 1px solid #000000;
        }
        .items thead th.ar { text-align: right; }
        .items thead th.ac { text-align: center; }
        .items tbody td {
            padding: 6px;
            font-size: 10px;
            color: #000000;
            border: 1px solid #000000;
        }
        .items tbody tr:nth-child(even) td { background: #ffffff; }
        .items tbody td.ar { text-align: right; font-weight: 700; color: #000000; }
        .items tbody td.ac { text-align: center; }
        .items tbody td.hs { font-weight: 700; color: #000000; letter-spacing: 0.3px; font-size: 10px; }
        .items tbody td.desc { font-weight: 600; color: #000000; }

        .totals-wrap { width: 100%; border-collapse: collapse; }
        .totals-wrap td { vertical-align: top; }
        .totals-box { border: 1px solid #000000; overflow: hidden; }
        .totals-box table { width: 100%; border-collapse: collapse; }
        .totals-box td { padding: 5px 10px; font-size: 10px; }
        .totals-box .tl { text-align: right; color: #000000; font-weight: 700; width: 55%; }
        .totals-box .tv { text-align: right; color: #000000; font-weight: 700; width: 45%; white-space: nowrap; }
        .totals-box tr { border-bottom: 1px solid #cccccc; }
        .totals-box tr.grand { background: #ffffff; border-top: 2px solid #000000; }
        .totals-box tr.grand td { border-bottom: none; padding: 9px 10px; }
        .totals-box tr.grand .tl { color: #000000; font-size: 12px; font-weight: 900; }
        .totals-box tr.grand .tv { color: #000000; font-size: 14px; font-weight: 900; }

        .sched { font-size: 9px; color: #000000; line-height: 1.5; margin-top: 4px; }
        .sched strong { color: #000000; font-weight: 800; }

        .sig-section { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .sig-section td { vertical-align: bottom; padding: 0; }
        .sig-line { border-top: 1px solid #000000; width: 160px; margin-top: 30px; }
        .sig-label { font-size: 8px; color: #000000; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-top: 3px; }

        .foot {
            margin-top: 14px;
            padding-top: 8px;
            border-top: 2px solid #000000;
            text-align: center;
        }
        .foot-line { font-size: 8px; color: #000000; }
        .foot-brand { font-size: 9px; color: #000000; font-weight: 800; margin-top: 2px; letter-spacing: 0.5px; }

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

    {{-- ===== TOP HEADER BAR ===== --}}
    <div class="top-band">
        <table>
            <tr>
                <td style="width: 58%;">
                    <div class="b-name">{{ $invoice->company->name ?? 'TaxNest' }}</div>
                    <div class="b-sub">
                        @if($invoice->company->address)
                        {{ $invoice->company->address }}
                        @if($invoice->company->city)
                        , {{ $invoice->company->city }}
                        @endif
                        <br>
                        @endif
                        @if($invoice->company->ntn)
                        <strong>NTN: {{ $invoice->company->ntn }}</strong>
                        @endif
                        @if($invoice->company->registration_no && $invoice->company->registration_no !== $invoice->company->ntn)
                        &nbsp;&bull;&nbsp;Reg #: {{ $invoice->company->registration_no }}
                        @endif
                        @if($invoice->company->cnic && $invoice->company->cnic !== $invoice->company->ntn && $invoice->company->cnic !== ($invoice->company->registration_no ?? ''))
                        <br>CNIC: {{ $invoice->company->cnic }}
                        @endif
                        @if($invoice->company->phone)
                        <br>Phone: {{ $invoice->company->phone }}
                        @endif
                        @if($invoice->company->mobile && $invoice->company->mobile !== ($invoice->company->phone ?? ''))
                        &nbsp;| Mobile: {{ $invoice->company->mobile }}
                        @endif
                        @if($invoice->company->email)
                        <br>{{ $invoice->company->email }}
                        @endif
                    </div>
                </td>
                <td style="width: 42%;">
                    <div class="inv-label">INVOICE</div>
                    @if($invoice->fbr_invoice_number)
                    <div style="text-align: right; margin-top: 6px;">
                        <span class="tag tag-verified">FBR VERIFIED</span>
                    </div>
                    @elseif($invoice->status === 'draft')
                    <div style="text-align: right; margin-top: 6px;">
                        <span class="tag tag-draft">DRAFT</span>
                    </div>
                    @elseif($invoice->status === 'locked')
                    <div style="text-align: right; margin-top: 6px;">
                        <span class="tag tag-production">PRODUCTION</span>
                    </div>
                    @elseif($invoice->status === 'failed')
                    <div style="text-align: right; margin-top: 6px;">
                        <span class="tag tag-failed">FAILED</span>
                    </div>
                    @elseif($invoice->status === 'pending_verification')
                    <div style="text-align: right; margin-top: 6px;">
                        <span class="tag tag-pending">PENDING</span>
                    </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
    <div class="color-bar"></div>

    {{-- ===== FBR VERIFIED SECTION ===== --}}
    @if($invoice->fbr_invoice_number && !empty($qrBase64))
    <div class="fbr-section">
        <table>
            <tr>
                @if(!empty($fbrLogoBase64) && $fbrLogoBase64 !== 'HIDE')
                <td style="padding: 0 14px 0 0;">
                    <img src="{{ $fbrLogoBase64 }}" alt="FBR" style="width: 65px; height: auto;">
                </td>
                @endif
                <td style="padding: 0 0 0 14px;">
                    <img src="{{ $qrBase64 }}" alt="QR Code" style="width: 72px; height: 72px;">
                </td>
            </tr>
        </table>
        <div class="fbr-inv-label">Digital Invoice #: {{ $invoice->fbr_invoice_number }}</div>
    </div>
    @endif

    {{-- ===== DOCUMENT TITLE ===== --}}
    <div style="font-size: 15px; font-weight: 900; color: #000000; margin-bottom: 6px; letter-spacing: 0.5px;">{{ $invoice->document_type ?? 'Sale Invoice' }}</div>

    {{-- ===== 3-COL INFO SECTION ===== --}}
    <table class="info-block">
        <tr>
            <td style="width: 38%; padding-right: 8px;">
                <div class="card">
                    <div class="section-title">Bill To</div>
                    <span class="tag {{ ($invoice->buyer_registration_type ?? '') === 'Registered' ? 'tag-registered' : 'tag-unregistered' }}">
                        {{ $invoice->buyer_registration_type ?? 'UNREGISTERED' }}
                    </span>
                    <div class="card-name">{{ $invoice->buyer_name }}</div>
                    <div class="card-row">
                        @if($invoice->buyer_ntn)
                        <strong>NTN:</strong> {{ $invoice->buyer_ntn }}<br>
                        @endif
                        @if($invoice->buyer_cnic)
                        <strong>CNIC:</strong> {{ $invoice->buyer_cnic }}<br>
                        @endif
                        @if($invoice->buyer_address)
                        {{ $invoice->buyer_address }}<br>
                        @endif
                        @if($invoice->destination_province)
                        {{ $invoice->destination_province }}, Pakistan
                        @else
                        Pakistan
                        @endif
                    </div>
                </div>
            </td>
            <td style="width: 34%; padding-right: 8px;">
                <div class="card">
                    <div class="section-title">Invoice Details</div>
                    <table class="dtable">
                        @if($invoice->fbr_invoice_number)
                        <tr>
                            <td class="dl" style="color: #000000; font-weight: 800;">FBR Invoice #</td>
                            <td class="dv" style="color: #000000; font-size: 8.5px; font-weight: 800;">{{ $invoice->fbr_invoice_number }}</td>
                        </tr>
                        <tr>
                            <td class="dl" style="color: #000000; font-weight: 800;">Internal Ref #</td>
                            <td class="dv" style="color: #000000; font-size: 8.5px; font-weight: 800;">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id }}</td>
                        </tr>
                        @else
                        <tr>
                            <td class="dl" style="color: #000000; font-weight: 800;">Invoice No.</td>
                            <td class="dv" style="color: #000000; font-weight: 800;">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="dl">Date</td>
                            <td class="dv">{{ $invoice->created_at->format('d M Y') }}</td>
                        </tr>
                        <tr>
                            <td class="dl">Status</td>
                            <td class="dv">{{ $invoice->fbr_invoice_number ? 'FBR Verified' : ($invoice->status === 'locked' ? 'Production' : ucfirst($invoice->status)) }}</td>
                        </tr>
                        @if($invoice->document_type && $invoice->document_type !== 'Sale Invoice')
                        <tr>
                            <td class="dl">Type</td>
                            <td class="dv">{{ $invoice->document_type }}</td>
                        </tr>
                        @endif
                        @if($invoice->reference_invoice_number)
                        <tr>
                            <td class="dl">Ref Invoice</td>
                            <td class="dv">{{ $invoice->reference_invoice_number }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="dl">NTN</td>
                            <td class="dv">{{ $invoice->company->ntn ?? 'N/A' }}</td>
                        </tr>
                        @if($invoice->supplier_province)
                        <tr>
                            <td class="dl">From</td>
                            <td class="dv">{{ $invoice->supplier_province }}</td>
                        </tr>
                        @endif
                    </table>
                </div>
            </td>
            <td style="width: 28%;">
                <div class="card">
                    <div class="section-title">Summary</div>
                    <table class="dtable">
                        <tr>
                            <td class="dl">Items</td>
                            <td class="dv">{{ $invoice->items->count() }}</td>
                        </tr>
                        <tr>
                            <td class="dl">Sub Total</td>
                            <td class="dv">{{ number_format($subtotal, 0) }}</td>
                        </tr>
                        <tr>
                            <td class="dl">Tax</td>
                            <td class="dv">{{ number_format($totalTax, 0) }}</td>
                        </tr>
                        <tr>
                            <td class="dl" style="color: #0f172a; font-weight: 800;">Total</td>
                            <td class="dv" style="color: #0ea5e9; font-weight: 800;">{{ number_format($invoice->total_amount, 0) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    {{-- ===== ITEMS TABLE ===== --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width: 26px;">SR</th>
                <th style="width: 76px;">HS CODE</th>
                <th>DESCRIPTION</th>
                <th class="ac" style="width: 48px;">UOM</th>
                <th class="ar" style="width: 48px;">QTY</th>
                <th class="ar" style="width: 68px;">RATE</th>
                <th class="ar" style="width: 78px;">AMOUNT</th>
                <th class="ar" style="width: 36px;">TAX</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            <tr>
                <td class="ac">{{ $index + 1 }}</td>
                <td class="hs">{{ $item->hs_code }}</td>
                <td class="desc">{{ $item->description }}</td>
                <td class="ac" style="font-size: 9px;">{{ $item->default_uom ?? 'PCS' }}</td>
                <td class="ar">{{ number_format($item->quantity, $item->quantity == intval($item->quantity) ? 0 : 2) }}</td>
                <td class="ar">{{ number_format($item->price, 2) }}</td>
                <td class="ar" style="font-weight: 800;">{{ number_format($item->price * $item->quantity, 2) }}</td>
                <td class="ar" style="font-size: 9px;">{{ number_format($item->tax_rate ?? 0, 0) }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ===== TOTALS + SCHEDULE ===== --}}
    <table class="totals-wrap">
        <tr>
            <td style="width: 50%; padding-right: 14px;">
                @php
                    $scheduleTypes = $invoice->items->pluck('schedule_type')->unique()->filter();
                    $sroNumbers = $invoice->items->pluck('sro_schedule_no')->unique()->filter();
                    $serialNumbers = $invoice->items->pluck('serial_no')->unique()->filter();
                @endphp
                @if($scheduleTypes->count() > 0 || $sroNumbers->count() > 0)
                <div class="sched">
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
                            <td class="tl">Sub Total</td>
                            <td class="tv">PKR {{ number_format($subtotal, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="tl">Sales Tax (GST)</td>
                            <td class="tv">PKR {{ number_format($totalTax, 2) }}</td>
                        </tr>
                        @php $totalFurtherTax = $invoice->items->sum('further_tax'); @endphp
                        @if($totalFurtherTax > 0)
                        <tr>
                            <td class="tl">Further Tax (4%)</td>
                            <td class="tv" style="color: #f97316;">PKR {{ number_format($totalFurtherTax, 2) }}</td>
                        </tr>
                        @endif
                        @if(($wht_rate ?? 0) > 0)
                        <tr>
                            <td class="tl">WHT ({{ $wht_rate }}%)</td>
                            <td class="tv">PKR {{ number_format($wht_amount ?? 0, 2) }}</td>
                        </tr>
                        @endif
                        <tr class="grand">
                            <td class="tl">TOTAL</td>
                            <td class="tv">PKR {{ number_format(($wht_rate ?? 0) > 0 ? ($net_receivable ?? $invoice->total_amount) : $invoice->total_amount, 2) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    {{-- ===== SIGNATURE SECTION ===== --}}
    <table class="sig-section">
        <tr>
            <td style="width: 50%;">
                <div class="sig-line"></div>
                <div class="sig-label">Authorized Signatory</div>
            </td>
            <td style="width: 50%; text-align: right;">
                <div class="sig-line" style="margin-left: auto;"></div>
                <div class="sig-label" style="text-align: right;">Receiver's Signature</div>
            </td>
        </tr>
    </table>

    {{-- ===== FOOTER ===== --}}
    <div class="foot">
        <div class="foot-line">This is a computer-generated invoice. | {{ $invoice->created_at->format('d M Y, h:i A') }}</div>
        <div class="foot-brand">TaxNest &mdash; Tax &amp; Invoice Management System</div>
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
