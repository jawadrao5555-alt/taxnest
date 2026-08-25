@php
    // Task 140: DI Premium white-label branding — cosmetic zones ONLY
    // (header logo, accent color, footer lines, platform credit).
    // COMPLIANCE: the FBR QR block, FBR invoice number and tax breakdown
    // below are never gated or altered by any branding choice.
    $diBrand = $diBrand ?? \App\Services\DiBrandingService::forCompany($invoice->company ?? null);

    // One accent drives every coloured band on the page. Default is the
    // TaxNest teal; a white-label shop's own colour simply replaces it.
    // Solid bands are deliberate: the earlier tinted-background version
    // (#F1F6F7 on white) printed washed out on both screen and paper.
    // Null-coalesced: a caller may hand in a partial branding array.
    $accent = ($diBrand['accent'] ?? null) ?: '#0A4D5C';
    $accentText = ($diBrand['accent_text'] ?? null) ?: '#ffffff';

    $dp = $invoice->company?->displayPrefs('di') ?? \App\Models\Company::defaultDisplayPrefs();

    $pdfWhtAmount = round(floatval($wht_amount ?? 0), 2);
    $pdfWhtRate = floatval($wht_rate ?? 0);
    $pdfWhtRateLabel = $pdfWhtRate > 0 ? ' (' . rtrim(rtrim(number_format($pdfWhtRate, 4, '.', ''), '0'), '.') . '%)' : '';
    $grandTotal = $pdfWhtAmount > 0 ? ($net_receivable ?? $invoice->total_amount) : $invoice->total_amount;

    $statusLabel = $invoice->fbr_invoice_number ? 'FBR VERIFIED' : strtoupper(str_replace('_', ' ', $invoice->status));
    // Status colours are fixed, never white-labelled: a buyer must be able to
    // tell a verified invoice from a draft at a glance on any shop's paper.
    $statusColor = match (true) {
        (bool) $invoice->fbr_invoice_number => '#0F7A46',
        $invoice->status === 'draft' => '#8A6100',
        $invoice->status === 'failed' => '#A3211B',
        $invoice->status === 'pending_verification' => '#7A5A00',
        default => '#0A4D5C',
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</title>
    <style>
        @page {
            /* Safer margins — most consumer printers can't print to within 12mm of
               paper edge. 14mm sides + 20mm bottom (feed roller + page footer). */
            margin: 13mm 14mm 20mm 14mm;
            size: A4 portrait;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', 'Helvetica', Arial, sans-serif;
            color: #16262B;
            background: #ffffff;
            font-size: 10.5px;
            line-height: 1.42;
            width: 100%;
        }

        /* ── Title band ──────────────────────────────────────────────── */
        .title-band { width: 100%; border-collapse: collapse; background: {{ $accent }}; }
        .title-band td { padding: 9px 12px; vertical-align: middle; color: {{ $accentText }}; }
        .title-band .doc-title { font-size: 17px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; }
        .title-band .doc-meta { font-size: 10px; text-align: right; line-height: 1.5; }
        .title-band .doc-meta .big { font-size: 12.5px; font-weight: 700; letter-spacing: 0.3px; }

        .status-pill {
            display: inline-block;
            padding: 3px 9px;
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: {{ $statusColor }};
            color: #ffffff;
        }

        /* ── Seller / FBR strip ──────────────────────────────────────── */
        .top-strip { width: 100%; border-collapse: collapse; margin-top: 9px; }
        .top-strip > tbody > tr > td { vertical-align: top; }
        .seller-name { font-size: 17px; font-weight: 700; color: {{ $accent }}; letter-spacing: 0.3px; }
        .seller-branch { font-size: 10px; font-weight: 700; color: {{ $accent }}; margin-top: 1px; }
        .seller-info { font-size: 9.5px; color: #33474C; margin-top: 3px; line-height: 1.5; }
        .seller-info strong { font-weight: 700; color: #16262B; }

        /* COMPLIANCE: the whole FBR block is fixed platform colour. A shop's
           white-label accent must never recolour the FBR mark, its frame or
           its caption — the monogram and QR keep their own colours. */
        .fbr-box { border: 1.5px solid #0A4D5C; }
        .fbr-box .fbr-inner { padding: 7px 8px 6px; text-align: center; }
        .fbr-box .fbr-no {
            font-size: 8.5px; font-weight: 700; color: #16262B; letter-spacing: 0.2px;
            word-wrap: break-word; margin-top: 4px; line-height: 1.35;
        }
        .fbr-box .fbr-cap {
            background: #0A4D5C; color: #ffffff;
            font-size: 7.5px; font-weight: 700; letter-spacing: 1px;
            text-transform: uppercase; text-align: center; padding: 3px 4px;
        }
        .fbr-box .qr-missing {
            width: 92px; height: 92px; border: 1px dashed #8FA8AD; color: #4A6167;
            font-size: 7.5px; font-weight: 700; text-align: center; padding-top: 38px;
        }
        .qr-img { width: 92px; height: 92px; }
        .not-filed {
            border: 1.5px dashed #A3211B; color: #A3211B; font-size: 9px; font-weight: 700;
            text-align: center; padding: 14px 8px; text-transform: uppercase; letter-spacing: 0.8px;
        }

        /* ── Panels (Bill To / Invoice Details) ──────────────────────── */
        .panels { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .panels > tbody > tr > td { vertical-align: top; }
        .panel-head {
            background: {{ $accent }}; color: {{ $accentText }};
            font-size: 8.5px; text-transform: uppercase; font-weight: 700;
            letter-spacing: 1.3px; padding: 5px 9px; display: block;
        }
        .panel-body { padding: 7px 9px; border: 1px solid {{ $accent }}; border-top: none; }
        .panel-row { font-size: 10px; color: #16262B; padding: 1px 0; line-height: 1.5; }
        .panel-label { font-size: 9.5px; color: #4A6167; font-weight: 600; }
        .buyer-type { font-size: 8.5px; font-weight: 700; letter-spacing: 0.9px; color: #4A6167; text-transform: uppercase; }
        .buyer-name { font-size: 12.5px; font-weight: 700; color: #16262B; }

        .detail-table { width: 100%; border-collapse: collapse; border: 1px solid {{ $accent }}; border-top: none; }
        .detail-table td { padding: 4px 9px; font-size: 9.5px; border-bottom: 1px solid #DCE6E8; }
        .detail-table tr:last-child td { border-bottom: none; }
        .detail-table .dt-label { color: #4A6167; font-weight: 600; width: 42%; }
        .detail-table .dt-value { color: #16262B; font-weight: 700; text-align: right; width: 58%; }

        /* ── Items ───────────────────────────────────────────────────── */
        .items-table { width: 100%; border-collapse: collapse; margin-top: 11px; }
        /* A long item list must carry its column headings onto every page. */
        .items-table thead { display: table-header-group; }
        .items-table thead th {
            background: {{ $accent }}; color: {{ $accentText }};
            padding: 7px 6px; font-size: 8.5px; text-transform: uppercase;
            font-weight: 700; letter-spacing: 0.6px; text-align: left;
            border-right: 1px solid rgba(255,255,255,0.28);
        }
        .items-table thead th:last-child { border-right: none; }
        .items-table thead th.ar { text-align: right; }
        .items-table thead th.ac { text-align: center; }
        .items-table tbody td {
            padding: 6px; font-size: 10px; color: #16262B;
            border-bottom: 1px solid #DCE6E8;
            border-left: 1px solid #DCE6E8;
        }
        .items-table tbody td:first-child { border-left: 1px solid {{ $accent }}; }
        .items-table tbody td:last-child { border-right: 1px solid {{ $accent }}; }
        .items-table tbody tr.alt td { background: #F3F8F9; }
        .items-table tbody tr:last-child td { border-bottom: 1.5px solid {{ $accent }}; }
        .items-table tbody td.ar { text-align: right; font-weight: 700; }
        .items-table tbody td.ac { text-align: center; }
        .items-table tbody td.code { font-weight: 700; color: {{ $accent }}; letter-spacing: 0.2px; }
        .items-table tbody td.unit { font-size: 8.5px; text-align: center; color: #4A6167; }
        .items-table tbody td.product { font-weight: 600; }
        .items-table tbody tr { page-break-inside: avoid; }

        /* ── Totals ──────────────────────────────────────────────────── */
        .summary-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .summary-table > tbody > tr > td { vertical-align: top; }

        .words-box { border: 1px solid #C4D5D8; border-left: 3px solid {{ $accent }}; padding: 7px 9px; }
        .words-label { font-size: 8px; text-transform: uppercase; letter-spacing: 1.1px; color: #4A6167; font-weight: 700; }
        .words-value { font-size: 10px; font-weight: 700; color: #16262B; margin-top: 2px; line-height: 1.45; }

        .schedule-info { font-size: 9px; color: #33474C; line-height: 1.55; margin-top: 8px; }
        .schedule-info strong { font-weight: 700; color: {{ $accent }}; }

        .totals-box { border: 1px solid {{ $accent }}; }
        .totals-box table { width: 100%; border-collapse: collapse; }
        .totals-box td { padding: 5px 10px; font-size: 10px; border-bottom: 1px solid #DCE6E8; }
        .totals-box .t-label { text-align: right; color: #4A6167; font-weight: 600; width: 54%; }
        .totals-box .t-value { text-align: right; color: #16262B; font-weight: 700; width: 46%; white-space: nowrap; }
        .totals-box tr.total-row td { background: {{ $accent }}; border-bottom: none; padding: 8px 10px; }
        .totals-box tr.total-row .t-label { color: {{ $accentText }}; font-size: 11px; font-weight: 700; letter-spacing: 0.6px; }
        .totals-box tr.total-row .t-value { color: {{ $accentText }}; font-size: 13.5px; font-weight: 700; }

        /* ── Sign-off + footer ───────────────────────────────────────── */
        .signoff { width: 100%; border-collapse: collapse; margin-top: 26px; }
        .signoff td { vertical-align: bottom; font-size: 8.5px; color: #4A6167; }
        .sign-line { border-top: 1px solid #8FA8AD; padding-top: 3px; text-align: center; font-weight: 700; letter-spacing: 0.5px; }

        .footer { margin-top: 14px; padding-top: 7px; border-top: 2px solid {{ $accent }}; text-align: center; }
        .footer-text { font-size: 8px; color: #4A6167; }
        .footer-brand { font-size: 9px; color: {{ $accent }}; font-weight: 700; margin-top: 2px; }

        /* Repeated on every page inside the bottom margin. */
        .page-foot {
            /* dompdf places fixed elements against the PAGE edge, not the
               content box — a negative offset lands off-paper. 7mm keeps it
               inside the 20mm bottom margin, clear of the last table row. */
            position: fixed; bottom: 7mm; left: 0; right: 0;
            font-size: 7.5px; color: #6B8085; text-align: center;
        }
        /* counter(page) works; counter(pages) resolves to 0 in this dompdf
           build because the total is not known when a fixed box is laid out,
           so the footer prints the page number without a total. */
        .page-foot .pno:before { content: counter(page); }

        .watermark {
            position: fixed; top: 38%; left: 10%;
            font-size: 68px; color: rgba(10, 77, 92, 0.10);
            font-weight: bold; text-transform: uppercase;
            transform: rotate(-35deg); letter-spacing: 10px;
            z-index: 9999; white-space: nowrap;
        }
    </style>
</head>
<body>

    <div class="page-foot">
        {{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}
        &nbsp;&middot;&nbsp; Page <span class="pno"></span>
    </div>

    {{-- ===== TITLE BAND ===== --}}
    <table class="title-band">
        <tr>
            <td style="width: 55%;">
                <div class="doc-title">{{ $invoice->document_type ?? 'Sale Invoice' }}</div>
            </td>
            <td style="width: 45%;" class="doc-meta">
                <div class="big">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</div>
                <div>{{ $invoice->created_at->format('d M Y') }}</div>
            </td>
        </tr>
    </table>

    {{-- ===== SELLER + FBR DIGITAL INVOICE MARK ===== --}}
    <table class="top-strip">
        <tr>
            <td style="width: 62%; padding-right: 14px;">
                @if($diBrand['logo_data_uri'])
                <div style="margin-bottom: 5px;"><img src="{{ $diBrand['logo_data_uri'] }}" alt="Logo" style="height: 42px; width: auto;"></div>
                @endif
                @php
                    // A distributor may trade under a different name at each
                    // address. The branch on the invoice IS that trading
                    // identity, so it headlines the bill it was sold from —
                    // head office included, because a head office can carry its
                    // own trading name too. The registered (legal) name still
                    // prints underneath, since the NTN belongs to it.
                    $invBranch = $invoice->branch;
                    $legalName = $invoice->company->name ?: 'TaxNest';
                    $branchName = trim((string) ($invBranch->name ?? ''));
                    $tidy = static fn($v) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $v)));
                    $branchIsOwnName = $branchName !== '' && $tidy($branchName) !== $tidy($legalName);
                    $sellerName = $branchIsOwnName ? $branchName : $legalName;
                    $branchAddress = ($invBranch?->address ?: null) ?: $invoice->company->address;
                    $branchCity = ($invBranch?->city ?: null) ?: $invoice->company->city;
                @endphp
                <div class="seller-name">{{ $sellerName }}</div>
                @if($branchIsOwnName)
                <div class="seller-branch">{{ $legalName }}</div>
                @endif
                <div class="seller-info">
                    @if($branchAddress && $dp['show_address'])
                    {{ $branchAddress }}@if($branchCity), {{ $branchCity }}@endif<br>
                    @endif
                    @if($invoice->company->ntn && $dp['show_ntn'])
                    <strong>NTN: {{ $invoice->company->ntn }}</strong>
                    @endif
                    @if($invoice->company->cnic && $invoice->company->cnic !== $invoice->company->ntn && $invoice->company->cnic !== $invoice->company->registration_no)
                    &nbsp;|&nbsp; CNIC: {{ $invoice->company->cnic }}
                    @endif
                    @if($invoice->company->registration_no)
                    &nbsp;|&nbsp; Reg #: {{ $invoice->company->registration_no }}
                    @endif
                    @if(($invoice->company->ntn && $dp['show_ntn']) || $invoice->company->registration_no)
                    <br>
                    @endif
                    @php
                        // Built as a list so a missing phone can't leave the
                        // line starting with a stray separator.
                        $contactBits = [];
                        if ($dp['show_mobile'] && $invoice->company->phone) {
                            $contactBits[] = 'Phone: ' . $invoice->company->phone;
                        }
                        if ($dp['show_mobile'] && $invoice->company->mobile && $invoice->company->mobile !== $invoice->company->phone) {
                            $contactBits[] = 'Mobile: ' . $invoice->company->mobile;
                        }
                    @endphp
                    @if($contactBits)
                    {{ implode(' | ', $contactBits) }}
                    @endif
                    @if($invoice->company->email && $dp['show_email'])
                    <br>{{ $invoice->company->email }}
                    @endif
                </div>
                <div style="margin-top: 7px;"><span class="status-pill">{{ $statusLabel }}</span></div>
            </td>
            <td style="width: 38%;">
                {{-- Filed state is decided by the FBR number alone. A missing QR
                     image must never make a filed invoice look unfiled. --}}
                @if($invoice->fbr_invoice_number)
                <div class="fbr-box">
                    <div class="fbr-cap">FBR Digital Invoice</div>
                    <div class="fbr-inner">
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="text-align: center; vertical-align: middle; padding-right: 6px;">
                                    @if(!empty($fbrLogoBase64))
                                    <img src="{{ $fbrLogoBase64 }}" alt="FBR Digital Invoicing System" style="height: 62px; width: auto;">
                                    @endif
                                </td>
                                <td style="width: 100px; text-align: center; vertical-align: middle;">
                                    @if(!empty($qrBase64))
                                    <img src="{{ $qrBase64 }}" alt="QR Code" class="qr-img">
                                    @else
                                    <div class="qr-missing">QR unavailable</div>
                                    @endif
                                </td>
                            </tr>
                        </table>
                        <div class="fbr-no">FBR Inv #: {{ $invoice->fbr_invoice_number }}</div>
                    </div>
                </div>
                @else
                <div class="not-filed">Not submitted to FBR</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ===== BILL TO + INVOICE DETAILS ===== --}}
    <table class="panels">
        <tr>
            <td style="width: 52%; padding-right: 8px;">
                <div class="panel-head">Bill To</div>
                <div class="panel-body">
                    <div class="buyer-type">{{ $invoice->buyer_registration_type ?? 'UNREGISTERED' }}</div>
                    <div class="buyer-name">{{ $invoice->buyer_name }}</div>
                    @if($invoice->buyer_ntn)
                    <div class="panel-row"><span class="panel-label">NTN:</span> <strong>{{ $invoice->buyer_ntn }}</strong></div>
                    @endif
                    @if($invoice->buyer_cnic)
                    <div class="panel-row"><span class="panel-label">CNIC:</span> {{ $invoice->buyer_cnic }}</div>
                    @endif
                    @if($invoice->buyer_address)
                    <div class="panel-row">{{ $invoice->buyer_address }}</div>
                    @endif
                    @if($invoice->destination_province)
                    <div class="panel-row">{{ $invoice->destination_province }}, Pakistan</div>
                    @else
                    <div class="panel-row">Pakistan</div>
                    @endif
                </div>
            </td>
            <td style="width: 48%;">
                <div class="panel-head">Invoice Details</div>
                <table class="detail-table">
                    <tr>
                        <td class="dt-label">Invoice No.</td>
                        <td class="dt-value">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</td>
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
                        <td class="dt-label">Seller NTN</td>
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
                <th class="ac" style="width: 26px;">#</th>
                <th style="width: 74px;">HS Code</th>
                <th>Description</th>
                <th class="ac" style="width: 58px;">Unit</th>
                <th class="ar" style="width: 46px;">Qty</th>
                <th class="ar" style="width: 68px;">Rate</th>
                <th class="ar" style="width: 82px;">Amount</th>
                <th class="ar" style="width: 44px;">Tax</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            <tr class="{{ $index % 2 ? 'alt' : '' }}">
                <td class="ac">{{ $index + 1 }}</td>
                <td class="code">{{ $item->hs_code }}</td>
                <td class="product">{{ $item->description }}</td>
                <td class="unit">{{ $item->default_uom ?? 'PCS' }}</td>
                <td class="ar">{{ number_format($item->quantity, $item->quantity == intval($item->quantity) ? 0 : 2) }}</td>
                <td class="ar">{{ number_format($item->price, 2) }}</td>
                <td class="ar" style="font-weight: 700;">{{ number_format($item->price * $item->quantity, 2) }}</td>
                <td class="ar" style="font-size: 9px;">{{ number_format($item->tax_rate ?? 0, 0) }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ===== TOTALS SECTION ===== --}}
    <table class="summary-table">
        <tr>
            <td style="width: 52%; padding-right: 14px;">
                <div class="words-box">
                    <div class="words-label">Amount in Words</div>
                    <div class="words-value">{{ \App\Services\InvoicePdfService::amountInWords((float) $grandTotal) }}</div>
                </div>
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
            <td style="width: 48%;">
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
                            <td class="t-value">PKR {{ number_format($totalFurtherTax, 2) }}</td>
                        </tr>
                        @endif
                        {{-- WHT / advance income tax: shown only when this invoice actually
                             has a WHT amount applied (per-invoice data, not a session toggle).
                             Invoices without WHT keep the clean PDF with no empty WHT row. --}}
                        @if($pdfWhtAmount > 0)
                        <tr>
                            <td class="t-label">WHT / Advance Tax{{ $pdfWhtRateLabel }}</td>
                            <td class="t-value">PKR {{ number_format($pdfWhtAmount, 2) }}</td>
                        </tr>
                        @endif
                        <tr class="total-row">
                            <td class="t-label">TOTAL</td>
                            <td class="t-value">PKR {{ number_format($grandTotal, 2) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    {{-- ===== SIGN-OFF ===== --}}
    <table class="signoff">
        <tr>
            <td style="width: 38%;"><div class="sign-line">Receiver's Signature</div></td>
            <td style="width: 24%;">&nbsp;</td>
            <td style="width: 38%;"><div class="sign-line">For {{ $sellerName ?? ($invoice->company->name ?? 'TaxNest') }}</div></td>
        </tr>
    </table>

    {{-- ===== FOOTER ===== --}}
    <div class="footer">
        @if($dp['show_footer'] && !empty($dp['footer_text']))
        <div class="footer-text" style="font-weight: 700;">{{ $dp['footer_text'] }}</div>
        @endif
        @foreach($diBrand['footer_lines'] as $brandLine)
        <div class="footer-text" style="font-weight: 700;">{{ $brandLine }}</div>
        @endforeach
        <div class="footer-text">This is a computer-generated invoice. | {{ $invoice->created_at->format('d M Y, h:i A') }}</div>
        @unless($diBrand['hide_platform'])
        <div class="footer-brand">TaxNest — Tax &amp; Invoice Management System</div>
        @endunless
    </div>

    {{-- ===== WATERMARKS ===== --}}
    @if(!empty($isDraft) && $isDraft)
    <div class="watermark">DRAFT</div>
    @endif

    @if(!empty($showWatermark))
    <div class="watermark" style="top: 58%; left: 16%;">UNPAID</div>
    @endif

</body>
</html>
