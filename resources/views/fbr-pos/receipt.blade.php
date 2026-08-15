<!DOCTYPE html>
@php $urduScript = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<html lang="{{ $urduScript ? 'ur' : 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $transaction->invoice_number }}</title>
    @php
        $paperSize = $company->print_paper_size ?? 'thermal';
        $is58 = $paperSize === 'thermal58';
        // Receipt Display toggles (owner, 22 Jul 2026) — business-profile Print Settings.
        $rd = $company->displayPrefs('fbrpos');
        // Print style (bold + logo position) — shared with PRA receipts.
        // posReceiptStyle() defaults: bold=true, logo='center'.
        // logo_finals_only is ignored on FBR (no local/provisional bills flow here).
        $printStyle = $company->posReceiptStyle();
    @endphp
    <style>
        @if($paperSize === 'a4')
            /* 📄 A4 mode — thermal-width receipt centered on full A4.
               15mm side + 18mm bottom margins prevent corner-cut on consumer printers. */
            @page { size: A4 portrait; margin: 15mm 15mm 18mm 15mm; }
        @elseif($is58)
            /* 🧾 Small thermal — 58mm continuous roll */
            @page { size: 58mm auto; margin: 0; }
        @else
            /* 🧾 Thermal mode — 80mm continuous roll, auto-cut */
            @page { size: 80mm auto; margin: 0; }
        @endif
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            /* Owner (22 Jul 2026, mirrors PRA receipt): Arial/Helvetica — Courier's
               hairline strokes print faint on laser printers. */
            font-family: Arial, Helvetica, sans-serif;
            font-size: {{ $is58 ? '11px' : '12px' }};
            width: {{ $is58 ? '58mm' : '80mm' }};
            max-width: {{ $is58 ? '58mm' : '80mm' }};
            margin: 0 auto;
            padding: {{ $is58 ? '2mm' : '3mm' }};
            background: #fff;
            color: #000;
            line-height: 1.35;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-weight: 500;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .separator { border-top: 1px dashed #000; margin: 3px 0; }
        .double-separator { border-top: 2px solid #000; margin: 3px 0; }

        .header { margin-bottom: 3px; }
        .header h1 { font-size: 15px; font-weight: bold; margin-bottom: 3px; word-wrap: break-word; color: #000; }
        .header p { font-size: 10px; line-height: 1.4; word-wrap: break-word; color: #000; font-weight: 600; }

        .info-table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .info-table td { font-size: 11px; padding: 1px 0; vertical-align: top; color: #000; font-weight: 600; }
        .info-table .info-label { width: 32%; font-weight: bold; white-space: nowrap; color: #000; }
        .info-table .info-value { width: 68%; text-align: right; word-wrap: break-word; color: #000; }

        .invoice-numbers { border: 1.5px solid #000; padding: 6px; margin: 6px 0; }
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table td { font-size: 10px; padding: 2px 0; vertical-align: top; color: #000; }
        .inv-table .inv-label { font-weight: bold; white-space: nowrap; width: 35%; color: #000; }
        .inv-table .inv-value { text-align: right; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; font-size: 9px; font-weight: bold; color: #000; }

        .top-badge { border: 2px solid #000; padding: 5px 4px; margin: 5px 0 4px; text-align: center; color: #000; }
        .top-badge .tb-title { font-size: 12px; font-weight: bold; letter-spacing: 1px; }
        .top-badge .tb-serial { font-size: 11px; font-weight: bold; margin-top: 2px; word-break: break-all; }

        .items-table { width: 100%; margin: 4px 0; border-collapse: collapse; table-layout: fixed; }
        .items-table th { font-size: 10px; text-transform: uppercase; border-bottom: 1.5px solid #000; border-top: 1.5px solid #000; padding: 3px 1px; text-align: left; font-weight: bold; color: #000; }
        .items-table td { font-size: 11px; padding: 3px 1px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; color: #000; font-weight: 600; }
        .items-table .col-item { width: 38%; text-align: left; }
        .tsch-tag { font-size: 0.75em; font-weight: bold; color: #000; border: 1px solid #000; padding: 0 2px; margin-left: 2px; vertical-align: middle; white-space: nowrap; }
        .items-table .col-uom { width: 10%; text-align: center; }
        .items-table .col-qty { width: 10%; text-align: center; }
        .items-table .col-price { width: 20%; text-align: right; }
        .items-table .col-total { width: 22%; text-align: right; font-weight: bold; }
        .items-table tbody tr { border-bottom: 1px dashed #000; }
        .items-table tbody tr:last-child { border-bottom: none; }

        .totals-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .totals-table td { font-size: 11px; padding: 2px 0; vertical-align: top; color: #000; font-weight: 600; }
        .totals-table .tot-label { text-align: left; color: #000; }
        .totals-table .tot-value { text-align: right; white-space: nowrap; color: #000; font-weight: bold; }
        .totals-table .grand-total td { font-size: {{ $is58 ? '15px' : '17px' }}; font-weight: 900; padding: 6px 4px; color: #000; border-top: 2.5px solid #000; border-bottom: 2.5px solid #000; letter-spacing: 0.3px; }

        {{-- Owner (6 Aug 2026): QR box standard/compact size — chhota padding + patla border. --}}
        .fbr-badge { border: 1.5px solid #000; padding: 4px; margin: 5px 0; text-align: center; font-size: 10px; overflow: hidden; color: #000; font-weight: 600; }
        .fbr-badge .fbr-title { font-size: 12px; font-weight: bold; margin-bottom: 3px; color: #000; }
        .fbr-badge .fbr-number { font-size: 9px; font-weight: bold; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; max-width: 100%; display: block; color: #000; }
        .footer { margin-top: 4px; font-size: 10px; line-height: 1.5; color: #000; font-weight: 600; }

        @media print {
            /* PRINTABLE-WIDTH FIX v2 (Jul 2026): 80mm paper prints only ~72mm — cap at
               the SAFE printable width and center; drivers reporting the full 80mm page
               no longer clip the right edge (58mm prints ~48mm). A4 branch below
               re-fixes width for A4 (centered on the big page, so no clipping there). */
            body { width: auto; max-width: {{ $is58 ? '48mm' : '72mm' }}; padding: 1mm; margin: 0 auto; }
            .no-print { display: none !important; }
            @if($paperSize === 'a4')
                /* A4: centered on page, no page break inside the receipt so it stays intact */
                html, body { background: #fff; }
                body { width: 80mm; margin: 0 auto; page-break-inside: avoid; }
                .receipt-wrap { page-break-inside: avoid; break-inside: avoid; }
            @endif
        }
        @media screen {
            body { padding: 10px; }
            .no-print { margin-bottom: 15px; text-align: center; font-family: Arial, sans-serif; }
        }
        {{-- COMPANY PRINT POSITION (31 Jul 2026, ported from PRA receipt_80mm):
             per-company center / left-margin options to correct a driver-side
             offset from OUR settings. Opt-in; default (all OFF) keeps the
             existing centered behavior untouched. `html body` outranks the
             base `body` print rules above (incl. the A4 branch). --}}
        @php
            // Task 718: keep `?? false` — for fbrpos companies kot_align_center is
            // the RECEIPT print position (receipt-kot-margin-split); NULL (the new
            // Pizza Master KOT default) must stay LEFT here. Never `?? true`.
            $pmAlign = (bool) ($company->kot_align_center ?? false);
            $pmMm    = max(0, min(30, (int) ($company->kot_left_margin_mm ?? 0)));
        @endphp
        @if($pmAlign)
        @media print { html body { margin-left: auto; margin-right: auto; } }
        @elseif($pmMm > 0)
        @media print { html body { margin-left: {{ $pmMm }}mm; margin-right: auto; } }
        @endif

        @if($urduScript)
        /* Urdu script mode (Task 240): browser + Desktop Agent renders are
           Chromium — Arabic shaping & bidi work natively. Layout stays LTR
           (columns/widths untouched, thermal-print-width rules intact);
           Unicode bidi flips each Urdu text run RTL on its own, so mixed
           label/number lines still line up. Naskh-first font stack: Nastaliq
           is too tall/thin for cheap thermal heads at receipt sizes; Tahoma/
           Arial keep full Arabic coverage as fallback on any Windows PC.
           Taller line-height — Urdu glyphs clip at Latin line heights.
           NOTE: DomPDF PDFs never reach here — PosLocale::applyPdfSafeLocale()
           drops 'ur' → 'rur' before every PDF render (DomPDF can't shape). */
        body { font-family: 'Noto Naskh Arabic', 'Urdu Typesetting', Tahoma, Arial, 'Segoe UI', sans-serif; line-height: 1.6; }
        @endif
    </style>
    @if($printStyle['bold'])
    <style>
        /* BOLD PRINT STYLE (ported from PRA receipt_80mm, Task #286): whole
           receipt in bold — cheap thermal heads print the plain weight too
           thin/light. Universal default ON; company can opt out via Receipt
           Settings. Plain bold 700, no text-stroke (owner: "kafi zyada bold"). */
        body, td, th, p, span, div, h1, strong { font-weight: bold !important; }
    </style>
    @endif
</head>
<body>
    <div class="no-print" id="receiptActions">
        <button onclick="window.print()" style="padding: 10px 30px; background: #2563eb; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; margin-right: 10px;">{{ __('pos.receipt_print') }}</button>
        <a href="{{ route('fbrpos.transactions') }}" target="_top" style="padding: 10px 30px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block;">{{ __('pos.receipt_back') }}</a>
    </div>
    <script>
        var isInIframe = (window.self !== window.top);
        if (isInIframe) {
            var actions = document.getElementById('receiptActions');
            if (actions) actions.style.display = 'none';
        }
        window.onafterprint = function() {
            if (isInIframe) return;
            if (window.opener) {
                window.close();
            } else {
                window.location.href = '{{ route('fbrpos.transactions') }}';
            }
        };
    </script>

    <div class="receipt-wrap">
    <div class="header text-center">
        {{-- Logo placement (Task #286, ported from PRA receipt_80mm):
             'center' = large centered logo above business name (default).
             'side'   = compact logo to the right of business name in a table row.
             Companies without a logo get the plain name-only header either way.
             logo_finals_only is ignored on FBR (no local/provisional path).
             Task #292: show_logo master switch respected here too — when off,
             logo never prints on any FBR receipt either. --}}
        @if($company->logo_path && ($printStyle['show_logo'] ?? true))
        @if($printStyle['logo'] === 'center')
        {{-- line-height:0 wrapper + block img: no inline-descender gap under logo --}}
        <div style="text-align:center; margin:0; padding:2mm 0 0; line-height:0;">
            <img src="{{ asset('storage/' . $company->logo_path) }}" alt="{{ $company->name }}" style="width:32mm; max-height:27mm; object-fit:contain; display:block; margin:0 auto;">
        </div>
        <h1>{{ $company->name }}</h1>
        @else
        {{-- Side logo: business name left, logo right — compact header --}}
        <table style="width:100%; border-collapse:collapse; margin-bottom:2px;">
            <tr>
                <td style="text-align:left; vertical-align:middle; width:64%; padding:0;">
                    <h1 style="text-align:left; margin:0;">{{ $company->name }}</h1>
                </td>
                <td style="text-align:right; vertical-align:middle; width:36%; padding:0;">
                    <img src="{{ asset('storage/' . $company->logo_path) }}" alt="{{ $company->name }}" style="max-width:80px; max-height:42px; object-fit:contain;">
                </td>
            </tr>
        </table>
        @endif
        @else
        <h1>{{ $company->name }}</h1>
        @endif
        @if($rd['show_address'] && $company->address)<p>{{ $company->address }}</p>@endif
        @if($rd['show_mobile'] && $company->phone)<p>{{ __('pos.rcpt_tel') }} {{ $company->phone }}</p>@endif
        @if($rd['show_ntn'] && $company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="separator"></div>

    {{-- Order Matching (Aug 2026): same bold-box style as PRA receipt_80mm/58mm.
         Reads directly from transaction columns — covers BOTH paper widths because
         FBR uses one template with $is58 switching font-size / padding. --}}
    @php
        $omRcptStyle = $company->order_match_style ?? 'off';
        $omRcptToken = null;
        $omRcptCode  = null;
        if ($omRcptStyle === 'token'
            && \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'token_no')
            && !empty($transaction->token_no)) {
            $omRcptToken = (int) $transaction->token_no;
        } elseif ($omRcptStyle === 'code'
            && \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'order_code')
            && !empty($transaction->order_code)) {
            $omRcptCode = strtoupper((string) $transaction->order_code);
        }
    @endphp
    @if($omRcptToken)
    <div style="text-align:center; margin:5px 0 3px;">
        <span style="display:inline-block; border:2px solid #000; padding:2px {{ $is58 ? '10px' : '14px' }}; font-size:{{ $is58 ? '14px' : '16px' }}; font-weight:900; color:#000;">{{ __('pos.order_match_token_label') }} {{ $omRcptToken }}</span>
        <div style="font-size:{{ $is58 ? '8px' : '9px' }}; font-weight:400; padding-top:1px;">{{ __('pos.order_match_token_caption') }}</div>
    </div>
    @elseif($omRcptCode)
    <div style="text-align:center; margin:5px 0 3px;">
        <span style="display:inline-block; border:2px solid #000; padding:2px {{ $is58 ? '10px' : '14px' }}; font-size:{{ $is58 ? '13px' : '15px' }}; font-weight:900; letter-spacing:2px; color:#000;">{{ $omRcptCode }}</span>
    </div>
    @endif

    @php
        // Serial box at TOP (owner, 22 Jul 2026 — mirrors PRA receipts):
        // bills NOT going to FBR (reporting-OFF finals fbr/NULL + legacy 'local'
        // + deliberate provisionals local/'local') show a centered SALE RECEIPT /
        // PROVISIONAL BILL badge here. Submitted/pending/failed keep the classic
        // FBR POS # box (their bottom FBR badge stays).
        $fbrRcptTopBadge = ($transaction->fbr_status === null || $transaction->fbr_status === 'local');
        $fbrRcptTopProvisional = $fbrRcptTopBadge && (($transaction->invoice_mode ?? 'fbr') === 'local');
    @endphp
    @if($fbrRcptTopBadge)
    <div class="top-badge" @if($fbrRcptTopProvisional) style="border-style: dashed;" @endif>
        <div class="tb-title">{{ $fbrRcptTopProvisional ? __('pos.receipt_provisional_bill') : __('pos.receipt_sale_receipt') }}</div>
        <div class="tb-serial">{{ $transaction->invoice_number }}</div>
    </div>
    @endif
    {{-- Owner (6 Aug 2026): POS/FBR invoice-numbers ka boxed table hata diya —
         FBR number neeche QR box mein hai, POS invoice number ab details mein. --}}

    <table class="info-table">
        @if(!$fbrRcptTopBadge)
        <tr><td class="info-label">{{ __('pos.receipt_pos_invoice') }}:</td><td class="info-value">{{ $transaction->invoice_number }}</td></tr>
        @endif
        <tr><td class="info-label">{{ __('pos.receipt_date') }}:</td><td class="info-value">{{ $transaction->created_at->format('d/m/Y h:i A') }}</td></tr>
        @if($transaction->customer_name)
        <tr><td class="info-label">{{ __('pos.receipt_customer') }}:</td><td class="info-value">{{ $transaction->customer_name }}</td></tr>
        @endif
        @if($transaction->customer_phone)
        <tr><td class="info-label">{{ __('pos.receipt_phone') }}:</td><td class="info-value">{{ $transaction->customer_phone }}</td></tr>
        @endif
        @if($transaction->customer_ntn)
        <tr><td class="info-label">NTN:</td><td class="info-value">{{ $transaction->customer_ntn }}</td></tr>
        @endif
        <tr><td class="info-label">{{ __('pos.tax_period') }}:</td><td class="info-value">{{ $transaction->created_at->format('M Y') }}</td></tr>
        <tr><td class="info-label">{{ __('pos.receipt_payment_mode') }}:</td><td class="info-value"><strong style="font-weight:bold; text-transform:uppercase;">{{ strtolower((string) $transaction->payment_method) === 'credit' ? 'UDHAAR (Khata)' : ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</strong></td></tr>
        @if($rd['show_cashier'] && $transaction->creator)
        <tr><td class="info-label">{{ __('pos.receipt_cashier') }}:</td><td class="info-value">{{ $transaction->creator->name }}</td></tr>
        @endif
        {{-- Owner (6 Aug 2026): POS Reg # kisi jagah nahi dikhana. --}}
    </table>

    <div class="separator"></div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-item">{{ __('pos.receipt_item') }}</th>
                <th class="col-uom">{{ __('pos.rcpt_uom') }}</th>
                <th class="col-qty">{{ __('pos.receipt_qty') }}</th>
                <th class="col-price">{{ __('pos.receipt_price') }}</th>
                <th class="col-total">{{ __('pos.receipt_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @php
                $fmtQty = function($q) {
                    $f = (float) $q;
                    return $f == (int) $f ? (string) (int) $f : rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
                };
            @endphp
            @foreach($transaction->items as $item)
            <tr>
                <td class="col-item">{{ $item->item_name }}@if($item->is_third_schedule)<span class="tsch-tag">3rd Sch</span>@endif</td>
                <td class="col-uom">{{ $item->uom ?? 'U' }}</td>
                <td class="col-qty">{{ $fmtQty($item->quantity) }}</td>
                <td class="col-price">{{ number_format($item->unit_price, 0) }}</td>
                <td class="col-total">{{ number_format($item->subtotal, 0) }}</td>
            </tr>
            @if(($item->item_discount ?? 0) > 0)
            <tr>
                <td class="col-item" colspan="4" style="font-size: 0.9em; color: #000; padding-left: 8px; font-weight: bold;">&#x21B3; {{ __('pos.receipt_discount') }}</td>
                <td class="col-total" style="font-size: 0.9em; color: #000; font-weight: bold;">-{{ number_format($item->item_discount, 0) }}</td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>

    <div class="separator"></div>

    <table class="totals-table">
        <tr>
            <td class="tot-label">{{ __('pos.receipt_subtotal') }}:</td>
            <td class="tot-value">PKR {{ number_format($transaction->subtotal, 2) }}</td>
        </tr>
        @if($transaction->discount_amount > 0)
        <tr>
            <td class="tot-label">{{ __('pos.receipt_discount') }}{{ $transaction->discount_type === 'percentage' ? ' ('.$transaction->discount_value.'%)' : '' }}:</td>
            <td class="tot-value">-PKR {{ number_format($transaction->discount_amount, 2) }}</td>
        </tr>
        @endif
        <tr>
            <td class="tot-label">{{ __('pos.receipt_tax') }} ({{ number_format($transaction->tax_rate, 0) }}%):</td>
            <td class="tot-value">PKR {{ number_format($transaction->tax_amount, 2) }}</td>
        </tr>
        @if($transaction->fbr_service_charge > 0)
        <tr>
            <td class="tot-label">{{ __('pos.dcp_fbr_pos_fee') }}:</td>
            <td class="tot-value">PKR {{ number_format($transaction->fbr_service_charge, 2) }}</td>
        </tr>
        @endif
    </table>
    <div class="double-separator"></div>
    <table class="totals-table">
        <tr class="grand-total">
            <td class="tot-label">{{ __('pos.receipt_total_caps') }}:</td>
            <td class="tot-value">PKR {{ number_format($transaction->total_amount, 2) }}</td>
        </tr>
        {{-- Cash Received / Wapsi (owner request, Jul 2026) — only when change is due. --}}
        @if(strtolower((string) $transaction->payment_method) === 'cash' && (float) ($transaction->cash_received ?? 0) > 0 && (float) ($transaction->change_due ?? 0) > 0.001)
        <tr>
            <td class="tot-label">{{ __('pos.receipt_cash') }}:</td>
            <td class="tot-value">PKR {{ number_format((float) $transaction->cash_received, 2) }}</td>
        </tr>
        <tr class="grand-total">
            <td class="tot-label">{{ __('pos.receipt_change') }}:</td>
            <td class="tot-value">PKR {{ number_format((float) $transaction->change_due, 2) }}</td>
        </tr>
        @endif
    </table>

    <div class="separator"></div>

    @php
        // TaxAsaan "POS Invoice Verification" QR scan expects ONLY the FBR fiscal
        // invoice number (X-WAY test, 6 Aug 2026: JSON blob in the QR pasted
        // garbage into the app's field and verify failed). Fiscalized bill =
        // bare FBR number; non-fiscalized bills (pending/provisional) keep the
        // details-JSON QR — wahan verify karne ko FBR number hai hi nahi.
        $qrData = $transaction->fbr_invoice_number
            ? $transaction->fbr_invoice_number
            : json_encode([
                'pos' => $transaction->invoice_number,
                'ntn' => $company->ntn ?? '',
                'date' => $transaction->created_at->format('d/m/Y'),
                'total' => number_format($transaction->total_amount, 2, '.', ''),
                'reg' => $company->fbr_pos_id ?? '',
            ]);
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($qrData);
    @endphp

    {{-- Owner (6 Aug 2026): QR box saaf — sirf QR + FBR invoice number + Tax Asaan
         verify line. "Integrated/Verified" headings, POS invoice aur POS Reg #
         yahan se hataye (POS Reg # footer mein pehle se maujood hai). --}}
    @if($transaction->fbr_status === 'submitted' && $transaction->fbr_invoice_number)
    <div class="fbr-badge">
        <div style="margin: 3px 0;">
            <img src="{{ $qrUrl }}" alt="FBR QR Code" style="width:{{ $is58 ? '60px' : '70px' }}; height:{{ $is58 ? '60px' : '70px' }}; margin:0 auto; display:block;">
        </div>
        <div class="fbr-number">FBR: {{ $transaction->fbr_invoice_number }}</div>
        {{-- Task 769: verify-line toggle (Receipt Settings) — default ON when absent. --}}
        @if($rd['show_verify_line'] ?? true)
        <div style="font-size:9px; margin-top:3px;">{{ __('pos.receipt_scan_verify_fbr') }}</div>
        @endif
    </div>
    @elseif(!$fbrRcptTopBadge)
    <div class="fbr-badge" style="border-style: dashed;">
        <div class="fbr-title">⏳ {{ __('pos.rcpt_fbr_pending') }}</div>
        <div style="margin: 3px 0;">
            <img src="{{ $qrUrl }}" alt="QR Code" style="width:{{ $is58 ? '60px' : '70px' }}; height:{{ $is58 ? '60px' : '70px' }}; margin:0 auto; display:block;">
        </div>
        <div>POS: {{ $transaction->invoice_number }}</div>
        <div style="font-size:10px; margin-top:3px;">{{ __('pos.rcpt_will_retry') }}</div>
    </div>
    @else
    {{-- Owner (22 Jul 2026): SALE RECEIPT / PROVISIONAL bills also carry a QR at the
         bottom — same as PRA finals — so every bill is scannable. --}}
    <div style="text-align: center; margin: 4px 0;">
        <img src="{{ $qrUrl }}" alt="QR Code" style="width:{{ $is58 ? '60px' : '70px' }}; height:{{ $is58 ? '60px' : '70px' }}; margin:0 auto; display:block;">
        <div style="font-size:9px; margin-top:2px;">{{ __('pos.receipt_scan_details') }}</div>
    </div>
    @endif

    <div class="footer text-center">
        @if($rd['show_footer'])
        <p>{{ __('pos.receipt_thank_purchase') }}</p>
        @if(!empty($company->receipt_footer_note))
        <p style="font-style: italic; margin-top:2px;">{{ $company->receipt_footer_note }}</p>
        @endif
        @endif
        @if($company->fbr_pos_id)
        <p style="font-weight:bold;">{{ __('pos.rcpt_fbr_integrated') }}</p>
        @endif
        <p>{{ __('pos.dcp_powered_taxnest_fbr') }}</p>
        {{-- Owner (6 Aug 2026): print-time timestamp hataya — Date upar details
             mein pehle se hai (do jaga date/time confusion khatam). --}}
    </div>
    </div>{{-- /.receipt-wrap --}}
</body>
</html>
