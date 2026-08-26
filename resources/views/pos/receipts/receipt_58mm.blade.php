<!DOCTYPE html>
@php $urduScript = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<html lang="{{ $urduScript ? 'ur' : 'en' }}" dir="{{ $urduScript ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $transaction->invoice_number }}</title>
    <style>
        @page {
            size: 58mm auto;
            margin: 0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-style: normal !important; }
        /* FONT SIMPLIFICATION (customer feedback Jul 2026): plain drafting —
           no italics, bold ONLY on business name / invoice numbers / grand total.
           Tighter spacing so the slip prints shorter; 58mm sizes already minimal
           so only weights and margins are trimmed here.
           font-style hardening (ZFC Jul 2026): some printer drivers substitute an
           oblique face when Arial is missing — force upright on EVERY element. */
        body {
            font-family: Arial, 'Helvetica Neue', Helvetica, 'Segoe UI', sans-serif;
            font-size: 10px;
            width: 58mm;
            max-width: 58mm;
            margin: 0 auto;
            padding: 2mm;
            background: #fff;
            color: #000;
            line-height: 1.3;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-weight: normal;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        /* Paper-waste trim (owner, Jul 2026): tighter separators/margins so the
           slip prints shorter — readability sizes unchanged. */
        .separator { border-top: 1px dashed #000; margin: 2px 0; }
        .double-separator { border-top: 2px solid #000; margin: 2px 0; }

        .header { margin-bottom: 2px; }
        .header h1 { font-size: 13px; font-weight: bold; margin-bottom: 2px; word-wrap: break-word; color: #000; }
        .header p { font-size: 9px; line-height: 1.3; word-wrap: break-word; color: #000; font-weight: normal; }

        .info-table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .info-table td { font-size: 10px; padding: 1px 0; vertical-align: top; color: #000; font-weight: normal; }
        .info-table .info-label { width: 30%; white-space: nowrap; color: #000; }
        .info-table .info-value { width: 70%; text-align: right; word-wrap: break-word; color: #000; }

        .invoice-numbers { border: 1.5px solid #000; padding: 4px; margin: 3px 0; }
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table td { font-size: 10px; padding: 1px 0; vertical-align: top; color: #000; }
        .inv-table .inv-label { white-space: nowrap; width: 30%; color: #000; }
        .inv-table .inv-value { text-align: right; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 9px; font-weight: bold; color: #000; }

        .items-table { width: 100%; margin: 3px 0; border-collapse: collapse; table-layout: fixed; }
        .items-table th { font-size: 9px; text-transform: uppercase; border-bottom: 1.5px solid #000; border-top: 1.5px solid #000; padding: 2px 1px; text-align: left; font-weight: normal; color: #000; }
        .items-table td { font-size: 10px; padding: 2px 1px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; color: #000; font-weight: normal; }
        .items-table .col-item { width: 44%; text-align: left; }
        .items-table .col-qty { width: 16%; text-align: center; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .items-table .col-rate { width: 18%; text-align: right; }
        .items-table .col-total { width: 22%; text-align: right; }
        .items-table tbody tr { border-bottom: 1px dashed #000; }
        .items-table tbody tr:last-child { border-bottom: none; }
        .exempt-tag { font-size: 7px; font-weight: bold; color: #000; border: 1px solid #000; padding: 0 2px; margin-left: 2px; vertical-align: middle; }
        .exempt-note { font-size: 8px; font-weight: normal; color: #000; text-align: center; margin: 3px 0 2px; padding: 2px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; letter-spacing: 0.3px; }

        .totals-table { width: 100%; border-collapse: collapse; margin: 3px 0; }
        .totals-table td { font-size: 10px; padding: 1px 0; vertical-align: top; color: #000; font-weight: normal; }
        .totals-table .tot-label { text-align: left; color: #000; }
        .totals-table .tot-value { text-align: right; white-space: nowrap; color: #000; }
        .totals-table .grand-total td { font-size: 14px; font-weight: bold; padding: 4px 3px; color: #000; border-top: 2px solid #000; border-bottom: 2px solid #000; letter-spacing: 0.3px; }

        .pra-badge { border: 1.5px solid #000; padding: 4px; margin: 3px 0; text-align: center; font-size: 9px; overflow: hidden; color: #000; font-weight: normal; }
        .pra-badge .pra-title { font-size: 11px; font-weight: bold; margin-bottom: 2px; color: #000; }
        .pra-badge .pra-number { font-size: 9px; font-weight: bold; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; max-width: 100%; display: block; color: #000; }
        .local-badge { border: 1.5px dashed #000; padding: 4px; margin: 3px 0; text-align: center; font-size: 9px; color: #000; font-weight: normal; }
        .qr-code { text-align: center; margin: 3px 0; }
        .qr-code img { width: 64px; height: 64px; }
        .qr-code p { font-size: 9px; margin-top: 1px; color: #000; font-weight: normal; }

        .footer { margin-top: 3px; font-size: 9px; line-height: 1.35; color: #000; font-weight: normal; }

        @media print {
            /* PRINTABLE-WIDTH FIX v2 (Jul 2026): 58mm paper prints only ~48mm. Drivers
               that report the FULL 58mm page clip the right edge with width:auto — cap
               content at the SAFE 48mm printable width and center it (also fits 52mm
               rolls, ~48mm printable). Never force physical paper width.
               v3 (Pizza Master Jul 2026): LEFT edge clipped too — side padding
               raised so the first column clears the dead zone.
               v4 (Malik Chicken Broast Jul 2026): sides now 2.5mm; 4mm TOP padding is
               deliberate breathing room so low-starting heads don't cut the logo.
               v5 (ZFC Pizza Point Jul 2026): margin auto → 0 — on misconfigured A4-default
               queues centering pushes the body off the printable window; left-align keeps
               it readable, correct drivers print identically. */
            body { width: auto; max-width: 48mm; padding: 4mm 2.5mm 1mm; margin: 0; }
            .no-print { display: none !important; }
        }
        @media screen {
            body { padding: 8px; }
            .no-print { margin-bottom: 12px; text-align: center; font-family: Arial, sans-serif; }
        }
        {{-- COMPANY PRINT POSITION (31 Jul 2026, Pizza Master): center/left-margin
             options apply to ALL slips now (see receipt_80mm). Opt-in; default OFF
             keeps v5 left-align untouched. --}}
        @php
            // Pizza Master (11 Aug 2026): receipts read their OWN margin columns;
            // NULL = fall back to legacy shared kot_* (old shops unchanged).
            $pmAlign = (bool) ($company->receipt_align_center ?? $company->kot_align_center ?? false);
            $pmMm    = max(0, min(30, (int) ($company->receipt_left_margin_mm ?? $company->kot_left_margin_mm ?? 0)));
        @endphp
        @if($pmAlign)
        @media print { html body { margin-left: auto; margin-right: auto; } }
        @elseif($pmMm > 0)
        @media print { html body { margin-left: {{ $pmMm }}mm; } }
        @endif

        @if($urduScript)
        /* Urdu script mode (Task 240; JNN-first since Task 1287 — owner chose
           Jameel Noori Nastaleeq "everywhere", overriding the Naskh-on-thermal
           decision). Chromium shapes Arabic natively; layout stays LTR
           (columns/widths untouched, thermal-print-width rules intact).
           Line-height 1.9: Nastaleeq stacks taller than Naskh — 1.6 clips.
           NOTE: DomPDF PDFs never reach here — PosLocale::applyPdfSafeLocale()
           drops 'ur' → 'rur' before every PDF render (DomPDF can't shape). */
        @include('partials.urdu-print-font')
        /* mPDF (Urdu PDF path, Task 260) resolves 'Jameel Noori Nastaleeq' via
           its registered fontdata key (MpdfRenderer), 'XB Riyaz' stays as its
           bundled fallback; browsers fall through to Noto Naskh → Tahoma. */
        body { font-family: 'Jameel Noori Nastaleeq', 'XB Riyaz', 'Noto Naskh Arabic', 'Urdu Typesetting', Tahoma, Arial, 'Segoe UI', sans-serif; line-height: 1.9; }
        @endif
    </style>
    @if(!empty($pdfMode))
    <style>
        /* DomPDF-only overrides (see receipt_80mm): body must fill the PDF page,
           never a fixed mm width that overflows and clips the right edge. */
        body { width: auto !important; max-width: none !important; margin: 0 !important; padding: 2mm !important; }
        .no-print { display: none !important; }
    </style>
    @endif
    @if(!empty($pdfMode) && ($pdfPaper ?? 'thermal') === 'a4')
    <style>
        /* A4 PDF PAPER MODE (see receipt_80mm): receipt strip top-left on a real A4
           page so downloaded PDFs print straight on regular office printers.
           Must come AFTER the pdfMode block so these !important rules win. */
        body { width: 54mm !important; max-width: 54mm !important; margin: 8mm 0 0 8mm !important; }
    </style>
    @endif
    @php $printStyle = $company->posReceiptStyle(); @endphp
    @if($printStyle['bold'])
    <style>
        /* BOLD PRINT STYLE (customer request Jul 2026 — Pizza Master): whole
           receipt in bold like the KOT font — cheap thermal heads print the
           plain weight too thin/light. Opt-in per company (Receipt Settings).
           Toned down (owner, 24 Jul 2026 "kafi zyada bold"): 900+text-stroke
           read as blobby/over-inked on decent printers — plain bold 700, no
           stroke. */
        body, td, th, p, span, div, h1, strong { font-weight: bold !important; }
    </style>
    @endif
</head>
<body>
    <div class="no-print" id="receiptActions">
        <button onclick="window.print()" style="padding: 8px 24px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; margin-right: 8px;">{{ __('pos.receipt_print') }}</button>
        <a href="{{ route('pos.transactions') }}" target="_top" style="padding: 8px 24px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-block;">{{ __('pos.receipt_back') }}</a>
    </div>
    <script>
        var isInIframe = (window.self !== window.top);
        if (isInIframe) {
            var actions = document.getElementById('receiptActions');
            if (actions) actions.style.display = 'none';
        }
        // After print: keep the window open so cashier can reprint or take other actions.
        // Auto-close/redirect intentionally removed (user feedback: window was vanishing
        // after print dialog closed, blocking reprint and KOT). The on-page "Print" and
        // "Back" buttons remain visible — user closes manually. Iframe path unaffected.

        // AUTO-PRINT + POSTMESSAGE SIGNAL (Restaurant POS sale-time chain)
        // See receipt_80mm for full rationale.
        window.addEventListener('load', function() {
            var urlParams = new URLSearchParams(window.location.search);
            var frameSignal = urlParams.get('_signal');
            if (urlParams.get('auto_print') !== '1') return;
            if (isInIframe && frameSignal) {
                var signaled = false;
                var signalParent = function() {
                    if (signaled) return;
                    signaled = true;
                    try { window.parent.postMessage({ type: 'pos_print_done', signal: frameSignal }, '*'); } catch (e) {}
                };
                window.addEventListener('afterprint', signalParent, { once: true });
                setTimeout(signalParent, 20000);
            }
            // First-print stutter fix (25 Jul 2026) — see receipt_80mm for rationale.
            var tnPrinted = false;
            var tnFirePrint = function() {
                if (tnPrinted) return;
                tnPrinted = true;
                window.print();
            };
            @if($urduScript)
            // Task 1287: Urdu receipts print in Jameel Noori Nastaleeq (~5.5 MB
            // on a cold cache) — request the face explicitly and allow a longer
            // bounded wait. Failsafe still guarantees the print ALWAYS fires
            // (a Naskh fallback receipt beats a lost receipt).
            var tnFontsReady = (document.fonts && document.fonts.load)
                ? document.fonts.load("16px 'Jameel Noori Nastaleeq'", "\u0627\u0631\u062F\u0648")
                : Promise.resolve();
            tnFontsReady.then(function() { setTimeout(tnFirePrint, 500); }, function() { setTimeout(tnFirePrint, 500); });
            setTimeout(tnFirePrint, 8000);
            @else
            var tnFontsReady = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
            tnFontsReady.then(function() { setTimeout(tnFirePrint, 500); });
            setTimeout(tnFirePrint, 2500);
            @endif
        });
    </script>

    @php
        // Logo via Company::receiptLogoDataUri() — downscales multi-MB uploads to a
        // cached small PNG. NEVER embed the raw file: huge base64 payloads break the
        // Desktop Agent's data-URL silent print (ERR_INVALID_URL) — mirrors receipt_80mm.
        $logoDataUri = $company->receiptLogoDataUri();
        $logoMissing = (bool) ($company->logo_path && !$logoDataUri);
        $addressLine = trim(($company->address ?? '') . (($company->city) ? ', ' . $company->city : ''));
        $phoneLine = trim(implode(' / ', array_filter([$company->phone ?? null, $company->mobile ?? null])));
        // Owner (Jul 2026): PRA and Local bills each have their OWN display set —
        // resolved per-transaction (PRA = pra mode + non-NULL status; else Local).
        $rp = $company->posReceiptPrefsFor($transaction);
        // Owner (Jul 2026, Pizza Master feedback): serial badge moves to the TOP box —
        // mirrors receipt_80mm; keep in sync.
        $rcptPraFiscal = $transaction->pra_status === 'submitted' && $transaction->pra_invoice_number;
        $rcptOffline = $transaction->pra_status === 'offline';
        $rcptTopBadge = !$rcptPraFiscal && !$rcptOffline;
        $rcptTopProvisional = ($transaction->invoice_mode ?? 'pra') === 'local';
        // Logo gate (Task #292) — mirrors receipt_80mm; keep in sync.
        // show_logo is the master switch; logo_finals_only is the sub-gate.
        $showLogo = $logoDataUri
            && ($printStyle['show_logo'] ?? true)
            && (!($printStyle['logo_finals_only'] ?? false) || !$rcptTopProvisional);
        // show_menu_qr gate (Task #292) — mirrors receipt_80mm; keep in sync.
        // When false, suppress both Menu QR and invoice JSON QR. Fiscal QR unaffected.
        $showReceiptQr = (bool) ($printStyle['show_menu_qr'] ?? true);
    @endphp
    <div class="header text-center">
        {{-- Logo placement (customer request Jul 2026): logo sits to the RIGHT of the
             business name on one row (was stacked above it) — the header prints
             shorter. Table layout (not flex) so DomPDF renders it identically. --}}
        @if($showLogo)
        @if($printStyle['logo'] === 'center')
        {{-- 'center' style (Pizza Master Jul 2026): LARGE centered logo above the
             name, like classic printed bills — opt-in via Receipt Settings. --}}
        {{-- display:block kills the inline-image baseline gap under the logo
             (owner report Jul 2026: "remove space under logo"). --}}
        <div style="text-align:center; margin:0; padding:2mm 0 0; line-height:0;">
            <img src="{{ $logoDataUri }}" style="width:24mm; max-height:20mm; object-fit:contain; display:block; margin:0 auto;">
        </div>
        @if($rp['show_business_name'] ?? true)<h1>{{ $company->name }}</h1>@endif
        @else
        <table style="width:100%; border-collapse:collapse; margin-bottom:2px;">
            <tr>
                <td style="text-align:left; vertical-align:middle; width:62%; padding:0;">
                    @if($rp['show_business_name'] ?? true)<h1 style="text-align:left; margin:0;">{{ $company->name }}</h1>@endif
                </td>
                <td style="text-align:right; vertical-align:middle; width:38%; padding:0;">
                    <img src="{{ $logoDataUri }}" style="max-width:60px; max-height:32px; object-fit:contain;">
                </td>
            </tr>
        </table>
        @endif
        @else
        @if($rp['show_business_name'] ?? true)<h1>{{ $company->name }}</h1>@endif
        @endif
        @if($company->business_activity)<p>{{ $company->business_activity }}</p>@endif
        @if(!empty($addressLine) && $rp['show_address'])<p>{{ $addressLine }}</p>@endif
        @if($phoneLine && $rp['show_mobile'])<p>{{ __('pos.rcpt_tel') }} {{ $phoneLine }}</p>@endif
        {{-- email_off wrappers (ZFC 13 Aug 2026): stop Cloudflare Scrape Shield from
             printing "[email protected]" — mirrors receipt_80mm; keep in sync. --}}
        @if($company->email && $rp['show_email'])<p><!--email_off-->{{ $company->email }}<!--/email_off--></p>@endif
        @if($company->ntn && $rp['show_ntn'])<p><strong>NTN:</strong> {{ $company->ntn }}</p>@endif
        @if(!empty($company->fbr_registration_no))<p><strong>STRN:</strong> {{ $company->fbr_registration_no }}</p>@endif
    </div>

    @php
        // Bill Number Style (07 Aug 2026): token = BIG display number; serial
        // stays underneath as reference. Mirrors receipt_80mm exactly.
        $rcptBillToken = null;
        // Task 647: single predicate (PosTransaction helpers) — exempt bills
        // follow the LOCAL number style; mirrors receipt_80mm.
        $rcptIsLocalStream = $transaction->isLocalBill() || $transaction->isExemptStream();
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'bill_token') && $transaction->bill_token) {
                $rcptNumStyle = $rcptIsLocalStream ? ($company->local_number_style ?? 'serial') : ($company->pra_number_style ?? 'serial');
                if ($rcptNumStyle === 'token') { $rcptBillToken = (int) $transaction->bill_token; }
            }
        } catch (\Throwable $e) { $rcptBillToken = null; }
    @endphp

    {{-- Order-number early lookup: for fiscal top box (code-style restaurant bills
         ONLY — style 'off'/'token' and retail bills must never print it).
         Schema-guarded per PROD drift convention. --}}
    @php
        $omRcptFullNum = null;
        try {
            if (($company->order_match_style ?? 'off') === 'code'
                && ($transaction->order_type ?? null)
                && \Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'token_no')) {
                $omEarlyRO = \App\Models\RestaurantOrder::where('company_id', $transaction->company_id)
                    ->where('pos_transaction_id', $transaction->id)
                    ->orderByDesc('id')
                    ->first();
                if ($omEarlyRO) { $omRcptFullNum = $omEarlyRO->order_number; }
            }
        } catch (\Throwable $e) { $omRcptFullNum = null; }

        // Return / credit-note receipt (Task 570): explicit query, never a lazy
        // relation (live has strict lazy loading).
        $rcptIsReturn = ($transaction->transaction_type ?? 'sale') === 'return';
        $rcptReturnParent = null;
        if ($rcptIsReturn && $transaction->parent_transaction_id) {
            try {
                $rcptReturnParent = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $transaction->company_id)
                    ->find($transaction->parent_transaction_id);
            } catch (\Throwable $e) { $rcptReturnParent = null; }
        }
    @endphp
    @if($rcptIsReturn)
    <div class="separator"></div>
    <div style="text-align:center; padding:2px 0;">
        <strong style="font-size:11px; color:#000;">*** {{ __('pos.receipt_credit_note') }} ***</strong>
        @if($rcptReturnParent)
        <br><span style="font-size:8px; color:#000;">{{ __('pos.original_invoice_colon') }} {{ $rcptReturnParent->pra_invoice_number ?: $rcptReturnParent->invoice_number }}</span>
        @endif
    </div>
    @endif
    @if($rcptTopBadge)
    <div class="separator"></div>
    <div class="invoice-numbers" style="text-align:center; padding:3px 4px;">
        <strong style="font-size:10px; color:#000;">{{ $rcptTopProvisional ? __('pos.receipt_provisional_bill') : __('pos.receipt_sale_receipt') }}</strong><br>
        @if($rcptBillToken !== null)
        <span style="font-size:18px; font-weight:bold; color:#000; line-height:1.15;">{{ $rcptBillToken }}</span><br>
        <span style="font-size:8px; font-weight:600; color:#000;">{{ __('pos.bill_ref_label') }}: {{ $transaction->invoice_number }}</span>
        @else
        <span style="font-size:11px; font-weight:bold; color:#000;">{{ $transaction->invoice_number }}</span>
        @endif
        @if($rcptTopProvisional)<br><span style="font-size:8px; color:#000;">{{ __('pos.receipt_provisional_note') }}</span>@endif
        {{-- Task 655: agent-mode bill printed while still 'pending' — chhoti wazahat
             ke yeh bill PRA ko report ho raha hai (taake "local bill" na samjha jaye). --}}
        @if(($transaction->pra_status ?? null) === 'pending')<br><span style="font-size:8px; color:#000;">{{ __('pos.receipt_pending_pra_note') }}</span>@endif
    </div>
    @else
    <div class="invoice-numbers">
        @if($rcptBillToken !== null)
        <div style="text-align:center; padding:2px 0 3px;">
            <span style="font-size:18px; font-weight:bold; color:#000;">{{ $rcptBillToken }}</span>
        </div>
        @endif
        <table class="inv-table">
            @if($omRcptFullNum)
            <tr>
                <td class="inv-label">Order #:</td>
                <td class="inv-value" style="font-weight:900;">{{ $omRcptFullNum }}</td>
            </tr>
            @endif
            {{-- Number rows: PRA Fiscal # when reported; Local Invoice # (own serial)
                 for local-stream bills; POS serial fallback for PRA-stream bills
                 still awaiting fiscal number — no bill ever prints number-less.
                 Task 763 (15 Aug 2026): for submitted fiscal bills, ALSO print the
                 shop's own serial so customers can reference it. Token style shows
                 it as a small Ref row (token already dominant above); serial style
                 prints it as a full POS Invoice row. --}}
            @if($transaction->pra_invoice_number)
            <tr>
                <td class="inv-label">{{ __('pos.receipt_pra_fiscal') }}:</td>
                <td class="inv-value">{{ $transaction->pra_invoice_number }}</td>
            </tr>
            @if($rcptBillToken !== null)
            <tr>
                <td class="inv-label" style="font-size:8px; font-weight:600; color:#000;">{{ __('pos.bill_ref_label') }}:</td>
                <td class="inv-value" style="font-size:8px; font-weight:600; color:#000;">{{ $transaction->invoice_number }}</td>
            </tr>
            @else
            <tr>
                <td class="inv-label">{{ __('pos.receipt_pos_invoice') }}:</td>
                <td class="inv-value">{{ $transaction->invoice_number }}</td>
            </tr>
            @endif
            @elseif($rcptIsLocalStream)
            <tr>
                <td class="inv-label">{{ __('pos.receipt_local_invoice') }}:</td>
                <td class="inv-value">{{ $transaction->invoice_number }}</td>
            </tr>
            @else
            <tr>
                <td class="inv-label">{{ __('pos.receipt_pos_invoice') }}:</td>
                <td class="inv-value">{{ $transaction->invoice_number }}</td>
            </tr>
            @endif
        </table>
    </div>
    @endif

    {{-- Order type (customer feedback, 23 Jul 2026): bold centered badge, restaurant bills only. --}}
    @php
        $rcptOrderType = match ($transaction->order_type ?? null) {
            'dine_in' => 'DINE-IN',
            'takeaway' => 'TAKE AWAY',
            'delivery' => 'DELIVERY',
            default => null,
        };
    @endphp
    @if($rcptOrderType)
    <div style="text-align:center; padding:2px 0 3px;">
        <span style="display:inline-block; border:1.5px solid #000; padding:1px 8px; font-size:10px; font-weight:bold; letter-spacing:1px;">{{ $rcptOrderType }}</span>
    </div>
    @endif

    {{-- Order Matching (Aug 2026): print the SAME identifier the kitchen KOT
         carries so counter staff can pair a ready order with this bill.
         'token' = daily token number; 'code' = short unique ORD code.
         Guarded by Schema::hasColumn (PROD drift self-heal convention) and
         restricted to restaurant bills (order_type present). --}}
    @php
        $omRcptStyle = $company->order_match_style ?? 'off';
        $omRcptToken = null;
        $omRcptCode = null;
        if ($rcptOrderType && in_array($omRcptStyle, ['token', 'code'], true)
            && \Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'token_no')) {
            $omRcptOrder = \App\Models\RestaurantOrder::where('company_id', $transaction->company_id)
                ->where('pos_transaction_id', $transaction->id)
                ->orderByDesc('id')
                ->first();
            if ($omRcptOrder) {
                if ($omRcptStyle === 'token' && !empty($omRcptOrder->token_no)) {
                    $omRcptToken = (int) $omRcptOrder->token_no;
                } elseif ($omRcptStyle === 'code') {
                    $omRcptCode = \App\Services\OrderTokenService::shortCode($omRcptOrder->order_number);
                }
            }
        }
    @endphp
    @if($omRcptToken)
    <div style="text-align:center; padding:2px 0 3px;">
        <span style="display:inline-block; border:2px solid #000; padding:2px 10px; font-size:14px; font-weight:900;">{{ __('pos.order_match_token_label') }} {{ $omRcptToken }}</span>
        <div style="font-size:8px; font-weight:400; padding-top:1px;">{{ __('pos.order_match_token_caption') }}</div>
    </div>
    @elseif($omRcptCode && !$rcptPraFiscal)
    {{-- Short-code box: only for non-fiscal bills; fiscal bills show the full order number in the top invoice box --}}
    <div style="text-align:center; padding:2px 0 3px;">
        <span style="display:inline-block; border:2px solid #000; padding:2px 10px; font-size:12px; font-weight:900; letter-spacing:2px;">{{ $omRcptCode }}</span>
    </div>
    @endif

    <table class="info-table">
        <tr><td class="info-label">{{ __('pos.receipt_date') }}:</td><td class="info-value">{{ $transaction->created_at->format('d/m/Y h:i A') }}</td></tr>
        @if($transaction->terminal)
        <tr><td class="info-label">{{ __('pos.receipt_terminal') }}:</td><td class="info-value">{{ $transaction->terminal->terminal_name }}</td></tr>
        @endif
        @if($transaction->customer_name)
        <tr><td class="info-label">{{ __('pos.receipt_customer') }}:</td><td class="info-value">{{ $transaction->customer_name }}</td></tr>
        @endif
        @if($transaction->delivery_address)
        <tr><td class="info-label">{{ __('pos.receipt_deliver') }}:</td><td class="info-value">{{ $transaction->delivery_address }}</td></tr>
        @endif
        {{-- Delivery Riders (Jul 2026): assigned rider on delivery receipts (display-only, all branches). --}}
        @if($transaction->rider)
        <tr><td class="info-label">{{ __('pos.receipt_rider') }}:</td><td class="info-value">{{ $transaction->rider->name }}</td></tr>
        @endif
        {{-- Task 830: no-rider delivery closed by a staff member (rider_id NULL, delivered_by set).
             Schema guard = PROD drift convention. Explicit relation load — live has strict lazy loading. --}}
        @php
            $rcptClosedBy = null;
            $rcptClosedAt = null;
            try {
                if (($transaction->order_type ?? null) === 'delivery'
                    && empty($transaction->rider_id)
                    && !empty($transaction->delivered_by)
                    && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'delivered_by')) {
                    $rcptClosedByUser = \App\Models\User::find($transaction->delivered_by);
                    if ($rcptClosedByUser) {
                        $rcptClosedBy = $rcptClosedByUser->name;
                        $rcptClosedAt = !empty($transaction->delivered_at)
                            ? \Carbon\Carbon::parse($transaction->delivered_at)->format('d M H:i')
                            : null;
                    }
                }
            } catch (\Throwable $e) { $rcptClosedBy = null; }
        @endphp
        @if($rcptClosedBy)
        <tr><td class="info-label">{{ __('pos.receipt_closed_by') }}:</td><td class="info-value">{{ $rcptClosedBy }}@if($rcptClosedAt) · {{ $rcptClosedAt }}@endif</td></tr>
        @endif
        {{-- ONE wording everywhere (owner, 26 Aug 2026) — mirrors receipt_80mm. --}}
        <tr><td class="info-label">{{ __('pos.receipt_payment_mode') }}:</td><td class="info-value"><strong style="font-weight:bold; text-transform:uppercase;">{{ \App\Support\PosPaymentLabels::label($transaction->payment_method) }}</strong></td></tr>
        @if($transaction->creator && $rp['show_cashier'])
        <tr><td class="info-label">{{ __('pos.receipt_cashier') }}:</td><td class="info-value">{{ $transaction->creator->name }}</td></tr>
        @endif
        {{-- Task 646 (Aug 2026): waiter-originated bills also print the WAITER's
             name (KOT already carries it since Task 620). Explicit guarded query —
             live has strict lazy loading + PROD schema drift convention; only
             source='waiter' orders qualify, cashier-punched bills add no line. --}}
        @php
            // hasColumn guard = PROD schema-drift convention only; any other
            // failure must surface (no blanket catch — a silently missing
            // waiter line is invisible to everyone).
            $rcptWaiterName = null;
            if (($transaction->order_type ?? null)
                && \Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'source')) {
                $rcptWaiterRO = \App\Models\RestaurantOrder::where('company_id', $transaction->company_id)
                    ->where('pos_transaction_id', $transaction->id)
                    ->where('source', 'waiter')
                    ->with('creator')
                    ->orderByDesc('id')
                    ->first();
                $rcptWaiterName = $rcptWaiterRO?->creator?->name;
            }
        @endphp
        @if($rcptWaiterName)
        <tr><td class="info-label">{{ __('pos.receipt_waiter') }}:</td><td class="info-value">{{ $rcptWaiterName }}</td></tr>
        @endif
        {{-- Table name (Aug 2026): dine-in receipts show the table label so staff
             can match a printout to the right table instantly. Floor name appended
             when the company uses multiple floors. Schema guard = PROD drift convention. --}}
        @php
            $rcptTableLabel = null;
            if (($transaction->order_type ?? null) === 'dine_in'
                && \Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'table_id')) {
                $rcptTableRO = \App\Models\RestaurantOrder::where('company_id', $transaction->company_id)
                    ->where('pos_transaction_id', $transaction->id)
                    ->with('table.floor')
                    ->orderByDesc('id')
                    ->first();
                if ($rcptTableRO && $rcptTableRO->table) {
                    $rcptTableLabel = 'T-' . $rcptTableRO->table->table_number;
                    if ($rcptTableRO->table->floor && $rcptTableRO->table->floor->name) {
                        $rcptTableLabel .= ' — ' . $rcptTableRO->table->floor->name;
                    }
                }
            }
        @endphp
        @if($rcptTableLabel)
        <tr><td class="info-label">{{ __('pos.receipt_table') }}:</td><td class="info-value">{{ $rcptTableLabel }}</td></tr>
        @endif
    </table>

    <div class="separator"></div>

    @php
        // Owner decision (Jul 2026): toggle OFF hides subtotal + tax on ALL receipts
        // (incl. PRA fiscal) — customer copy shows grand total only. Item Rate/Amt
        // show the ORIGINAL as-entered (ex-tax) prices (owner update Jul 2026) even
        // though lines then intentionally do not sum to the grand total. Tax is
        // always submitted to PRA; details visible via Sahulat app QR scan.
        // Per-type since Jul 2026: PRA receipts read the PRA set (pos_receipt_show_tax
        // column), Local receipts read the Local set — both resolved in $rp above.
        $showTaxLines = (bool) ($rp['show_tax'] ?? true);
        // Tax-Inclusive Pricing (Menu-Rate-Final): item lines are MENU (tax-in)
        // prices; header subtotal is ex-tax-consistent, so the receipt Subtotal
        // re-adds the included tax to read as the menu sum.
        $rcptInclusive = (bool) ($transaction->tax_inclusive ?? false);
        // Card-save (mode 3) card/digital bills: "Menu Total" = item sum + explicit
        // "Card Disc" saving line (visible even when Show-Tax is OFF).
        $rcptMenuRate = $rcptInclusive ? ($transaction->tax_menu_rate ?? null) : null;
        $rcptCardSave = $rcptMenuRate !== null && (float) $rcptMenuRate > 0
            && abs((float) $rcptMenuRate - (float) $transaction->tax_rate) >= 0.005;
        $rcptSubtotal = $rcptCardSave
            ? (float) $transaction->items->sum('subtotal')
            : ($rcptInclusive
                ? round((float) $transaction->subtotal + (float) $transaction->tax_amount, 2)
                : (float) $transaction->subtotal);
        $rcptCardSaving = $rcptCardSave
            ? max(0.0, round($rcptSubtotal - (float) $transaction->discount_amount - (float) $transaction->total_amount, 2))
            : 0.0;
    @endphp
    <table class="items-table">
        <thead>
            <tr>
                <th class="col-item">{{ __('pos.receipt_item') }}</th>
                <th class="col-qty">{{ __('pos.receipt_qty') }}</th>
                <th class="col-rate">{{ __('pos.receipt_rate') }}</th>
                <th class="col-total">{{ __('pos.receipt_amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transaction->items as $item)
            @php
                $lineAmt = (float) $item->subtotal;
                $lineRate = (float) $item->unit_price;
            @endphp
            <tr>
                <td class="col-item">
                    {{ $item->item_name }}@if($showTaxLines && $item->is_third_schedule)<span class="exempt-tag">3rd Sch</span>@elseif($showTaxLines && $item->is_tax_exempt)<span class="exempt-tag">NT</span>@endif
                </td>
                <td class="col-qty">{{ rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') ?: '0' }}</td>
                <td class="col-rate">{{ number_format($lineRate, 0) }}</td>
                <td class="col-total">{{ number_format($lineAmt, 0) }}</td>
            </tr>
            {{-- Per-item comment (owner voice note, 23 Aug 2026) — 80mm ke saath parity. --}}
            @if(!empty($item->special_notes))
            <tr>
                <td class="col-item" colspan="4" style="padding-left:6px; font-size:8px; font-weight:normal;">&nbsp;&nbsp;* {{ $item->special_notes }}</td>
            </tr>
            @endif
            {{-- Deal components (frozen snapshot) — indented, NO price columns. --}}
            @if($item->item_type === 'deal' && is_array($item->deal_snapshot))
            @foreach($item->deal_snapshot as $comp)
            <tr>
                <td class="col-item" style="padding-left:6px; font-size:8px;">&nbsp;&nbsp;• {{ (int)($comp['qty'] ?? 1) }}x {{ $comp['name'] ?? 'Item' }}</td>
                <td class="col-qty"></td>
                <td class="col-rate"></td>
                <td class="col-total"></td>
            </tr>
            @endforeach
            @endif
            @endforeach
        </tbody>
    </table>

    @if(!empty($transaction->notes))
    <div class="separator"></div>
    <div style="font-size:9px; font-weight:normal; color:#000; padding:1px 0;">{{ __('pos.receipt_note') }}: {!! nl2br(e($transaction->notes)) !!}</div>
    @endif

    <div class="separator"></div>

    <table class="totals-table">
        @if($showTaxLines || $rcptCardSave)
        <tr>
            <td class="tot-label">{{ $rcptCardSave ? __('pos.receipt_menu_total') : __('pos.receipt_subtotal') }}:</td>
            <td class="tot-value">{{ number_format($rcptSubtotal, 2) }}</td>
        </tr>
        @endif
        @if($transaction->discount_amount > 0)
        <tr>
            <td class="tot-label">{{ __('pos.receipt_discount') }}:</td>
            <td class="tot-value">-{{ number_format($showTaxLines ? $transaction->discount_amount : round((float) $transaction->discount_amount), 2) }}</td>
        </tr>
        @endif
        @if($rcptCardSave && $rcptCardSaving > 0.009)
        <tr>
            <td class="tot-label">{{ __('pos.receipt_card_discount') }}:</td>
            <td class="tot-value">-{{ number_format($rcptCardSaving, 2) }}</td>
        </tr>
        @endif
        @if($showTaxLines)
        <tr>
            <td class="tot-label">{{ __('pos.receipt_tax') }} ({{ number_format($transaction->tax_rate, 0) }}%{{ $rcptInclusive ? ' incl.' : '' }}):</td>
            <td class="tot-value">{{ number_format($transaction->tax_amount, 2) }}</td>
        </tr>
        @endif
    </table>
    <div class="double-separator"></div>
    <table class="totals-table">
        <tr class="grand-total">
            <td class="tot-label">{{ __('pos.receipt_total_caps') }}:</td>
            <td class="tot-value">PKR {{ number_format($showTaxLines ? $transaction->total_amount : round((float) $transaction->total_amount), 2) }}</td>
        </tr>
        {{-- Cash Received / Wapsi (owner request, Jul 2026) — mirrors receipt_80mm; keep in sync. --}}
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

    {{-- Task 816: split-payment breakdown — printed receipt mirrors the public
         /bill/{token} page (Task 803). Shown ONLY when ≥2 raw pos_payments rows
         exist (single-method bills unchanged). relationLoaded guard: render
         paths that don't eager-load 'payments' simply skip the section — prod
         strict lazy-loading never throws. Mirrors receipt_80mm; keep in sync. --}}
    @php
        $rcptPayBreakdown = [];
        try {
            if ($transaction->relationLoaded('payments') && ($transaction->payments ?? collect())->count() >= 2) {
                // Bucket aliases so the customer sees a friendly label; legacy
                // 'card' + 'debit_card' + 'credit_card' collapse into one Card row.
                $rcptPayAliases = ['card', 'debit_card', 'credit_card'];
                $rcptPayGrouped = [];
                foreach ($transaction->payments as $rcptPayRow) {
                    $rcptPayMethod = strtolower((string) $rcptPayRow->payment_method);
                    $rcptPayBucket = $rcptPayMethod === 'cash' ? 'cash'
                        : (in_array($rcptPayMethod, $rcptPayAliases, true) ? 'card' : 'other:' . $rcptPayMethod);
                    $rcptPayGrouped[$rcptPayBucket] = ($rcptPayGrouped[$rcptPayBucket] ?? 0) + (float) $rcptPayRow->amount;
                }
                foreach ($rcptPayGrouped as $rcptPayBucket => $rcptPayAmount) {
                    $rcptPayBreakdown[] = [
                        'label'  => $rcptPayBucket === 'cash' ? __('pos.receipt_pay_cash')
                            : ($rcptPayBucket === 'card' ? __('pos.receipt_pay_card') : __('pos.receipt_pay_other')),
                        'amount' => $rcptPayAmount,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $rcptPayBreakdown = [];
        }
    @endphp
    @if(count($rcptPayBreakdown) >= 1)
    <div class="separator"></div>
    <table class="totals-table">
        <tr>
            <td class="tot-label" colspan="2" style="font-weight:bold; text-transform:uppercase;">{{ __('pos.payment_breakdown') }}</td>
        </tr>
        @foreach($rcptPayBreakdown as $rcptPayLine)
        <tr>
            <td class="tot-label">{{ $rcptPayLine['label'] }}:</td>
            <td class="tot-value">PKR {{ number_format($rcptPayLine['amount'], 2) }}</td>
        </tr>
        @endforeach
    </table>
    @endif

    {{-- Task 647: exempt-bill clarifier — approved neutral wording, small/plain,
         no box, render-time locale. Mirrors receipt_80mm. --}}
    @if($transaction->isExemptStream())
    <div style="font-size:8px; font-weight:normal; color:#000; text-align:center; margin:2px 0; padding:1px 0;">{{ __('pos.receipt_exempt_clarifier') }}</div>
    @endif

    @if(($transaction->order_type ?? '') === 'delivery' || !empty($transaction->delivery_address) || $transaction->rider)
    @php
        // ZFC feedback Jul 2026: riders must see AT A GLANCE cash vs card/online.
        // Owner update (Pizza Master, Jul 2026): boxed method on DELIVERY bills ONLY
        // (mirrors receipt_80mm; keep in sync).
        $rcptPayRaw = strtolower((string) $transaction->payment_method);
        $rcptPayLabel = \App\Support\PosPaymentLabels::upper($transaction->payment_method);
    @endphp
    @if($rcptPayRaw === 'qr_payment')
    {{-- PREPAID marker (Task 291): prominent stamp for riders/customers so no cash is mistakenly collected. --}}
    <div style="border: 3px solid #000; text-align: center; font-weight: bold; font-size: 14px; letter-spacing: 1px; padding: 4px 2px; margin: 4px 0; margin-left: 0.5mm; margin-right: 0.5mm; color: #000;">&#10003; {{ __('pos.rcpt_prepaid_marker') }}</div>
    @endif
    <div style="border: 2px solid #000; text-align: center; font-weight: bold; font-size: 12px; letter-spacing: 1px; padding: 3px 2px; margin: 3px 0; color: #000;">{{ __('pos.rcpt_payment_banner') }} {{ $rcptPayLabel }}</div>
    @endif

    @if($transaction->pra_status === 'submitted' && $transaction->pra_invoice_number)
    @php
        // minVersion 4 (ZFC 13 Aug 2026): same module grid as the local invoice
        // QR — visually consistent QRs, content untouched. Mirrors receipt_80mm.
        $praQr = $transaction->pra_invoice_number
            ? \App\Support\QrImage::dataUri($transaction->pra_invoice_number, 5, 4)
            : ($transaction->pra_qr_code ?: '');
    @endphp
    @if($praQr)
    <div class="qr-code">
        <img src="{{ $praQr }}" alt="PRA QR">
        @if($rp['show_verify_line'] ?? true)
        <p>{{ __('pos.receipt_scan_verify_short') }}</p>
        @endif
    </div>
    @endif
    @elseif($transaction->pra_status === 'offline')
    <div class="local-badge">
        {{ __('pos.receipt_offline_invoice') }}<br>
        {{ __('pos.receipt_offline_sync_auto') }}<br>
        {{ $transaction->invoice_number }}
    </div>
    @else
    @php
        // Reporting-OFF FINALS vs provisionals (client report Jul 2026 — ZFC):
        // finals with PRA reporting OFF are invoice_mode 'pra' + NULL pra_status —
        // they are REAL completed sales, NOT provisionals. Only deliberate
        // provisionals (invoice_mode 'local') may carry the PROVISIONAL badge.
        $rcptIsProvisional = ($transaction->invoice_mode ?? 'pra') === 'local';
        // F8: local/provisional receipts carry the PUBLIC profile QR when the
        // company enabled its public page (PRA fiscal branch above is untouched).
        // Task #292: show_menu_qr=false suppresses BOTH QR types. $showReceiptQr set above.
        if ($showReceiptQr) {
            // Task 777 — URL QR opens the public bill page (priority over the
            // menu QR; the bill page carries the menu link). Mirrors
            // receipt_80mm, keep in sync.
            $billPageTok = $transaction->publicBillToken();
            $publicUrl = $billPageTok ? null : \App\Http\Controllers\PublicProfileController::publicUrlFor($transaction->company);
            if ($billPageTok) {
                $qrUrl = \App\Support\QrImage::dataUri(url('/bill/' . $billPageTok), 5, 4);
                $qrCaption = __('pos.receipt_scan_bill');
            } elseif ($publicUrl) {
                // share_token column missing (PROD drift) — menu QR as before.
                $qrUrl = \App\Support\QrImage::dataUri($publicUrl, 5, 4);
                $qrCaption = __('pos.receipt_scan_menu');
            } else {
                // Task 777 — URL QR opens the public bill page; mirrors
                // receipt_80mm, keep in sync.
                $billPageTok = $transaction->publicBillToken();
                if ($billPageTok) {
                    $qrUrl = \App\Support\QrImage::dataUri(url('/bill/' . $billPageTok), 5, 4);
                    $qrCaption = __('pos.receipt_scan_bill');
                } else {
                    // share_token column missing (PROD drift) — legacy text payload.
                    // ZFC issue #9 (28 Jul 2026): business name OFF => no name leak.
                    $qrLines = [
                        $rcptIsProvisional ? 'Provisional Bill' : 'Sale Receipt',
                        $transaction->invoice_number,
                        $transaction->created_at->format('d/m/Y H:i'),
                        'Total: ' . number_format($transaction->total_amount, 2),
                    ];
                    if ($rp['show_business_name'] ?? true) {
                        $qrLines[] = $transaction->company->name ?? 'NestPOS';
                    }
                    $qrUrl = \App\Support\QrImage::dataUri(implode("\n", $qrLines), 5, 4);
                    $qrCaption = __('pos.receipt_scan_details');
                }
            }
        } else {
            $qrUrl = null;
            $qrCaption = '';
        }
    @endphp
    {{-- Bottom SALE RECEIPT / PROVISIONAL badge removed (owner, Jul 2026) — the
         serial badge now prints in the TOP box; only the QR remains down here. --}}
    @if($qrUrl)
    <div class="qr-code">
        <img src="{{ $qrUrl }}" alt="Invoice QR" style="width: 64px; height: 64px; margin: 3px auto;">
        <p style="font-size: 8px; color: #000;">{{ $qrCaption }}</p>
    </div>
    @endif
    @endif

    <div class="footer text-center">
        @if($rp['show_footer'])<p>{{ $rp['footer_text'] ?? __('pos.receipt_thank_purchase') }}</p>@endif
        @if($rp['show_developed_by'] ?? true)<p>{{ __('pos.brand_developed_by') }}</p>@endif
        <p>{{ now()->format('d/m/Y h:i A') }}</p>
    </div>
</body>
</html>
