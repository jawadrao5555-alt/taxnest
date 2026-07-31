<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $transaction->invoice_number }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-style: normal !important; }
        /* FONT SIMPLIFICATION (customer feedback Jul 2026): plain drafting —
           no italics, bold ONLY on business name / invoice numbers / grand total.
           Sizes trimmed ~1px + tighter spacing so the slip prints shorter while
           staying readable on cheap thermal heads.
           font-style hardening (ZFC Jul 2026): some printer drivers substitute an
           oblique face when Arial is missing — force upright on EVERY element. */
        body {
            font-family: Arial, 'Helvetica Neue', Helvetica, 'Segoe UI', sans-serif;
            font-size: 11px;
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 3mm;
            background: #fff;
            color: #000;
            line-height: 1.35;
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
        .header h1 { font-size: 15px; font-weight: bold; margin-bottom: 2px; word-wrap: break-word; color: #000; }
        .header p { font-size: 11px; line-height: 1.35; word-wrap: break-word; color: #000; font-weight: normal; }

        .info-table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .info-table td { font-size: 12px; padding: 1px 0; vertical-align: top; color: #000; font-weight: normal; }
        .info-table .info-label { width: 32%; white-space: nowrap; color: #000; }
        .info-table .info-value { width: 68%; text-align: right; word-wrap: break-word; color: #000; }

        .invoice-numbers { border: 1.5px solid #000; padding: 4px; margin: 3px 0; }
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table td { font-size: 11px; padding: 1px 0; vertical-align: top; color: #000; }
        .inv-table .inv-label { white-space: nowrap; width: 35%; color: #000; }
        .inv-table .inv-value { text-align: right; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 10px; font-weight: bold; color: #000; }

        .items-table { width: 100%; margin: 3px 0; border-collapse: collapse; table-layout: fixed; }
        .items-table th { font-size: 10px; text-transform: uppercase; border-bottom: 1.5px solid #000; border-top: 1.5px solid #000; padding: 2px 1px; text-align: left; font-weight: normal; color: #000; }
        .items-table td { font-size: 12px; padding: 2px 1px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; color: #000; font-weight: normal; }
        .items-table .col-item { width: 44%; text-align: left; }
        .items-table .col-qty { width: 16%; text-align: center; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .items-table .col-rate { width: 18%; text-align: right; }
        .items-table .col-total { width: 22%; text-align: right; }
        .items-table tbody tr { border-bottom: 1px dashed #000; }
        .items-table tbody tr:last-child { border-bottom: none; }
        .exempt-tag { font-size: 8px; font-weight: bold; color: #000; border: 1px solid #000; padding: 0 3px; margin-left: 3px; vertical-align: middle; letter-spacing: 0.3px; }
        .exempt-note { font-size: 9px; font-weight: normal; color: #000; text-align: center; margin: 3px 0 2px; padding: 2px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; letter-spacing: 0.4px; }

        .totals-table { width: 100%; border-collapse: collapse; margin: 3px 0; }
        .totals-table td { font-size: 12px; padding: 1px 0; vertical-align: top; color: #000; font-weight: normal; }
        .totals-table .tot-label { text-align: left; color: #000; }
        .totals-table .tot-value { text-align: right; white-space: nowrap; color: #000; }
        .totals-table .grand-total td { font-size: 16px; font-weight: bold; padding: 4px 3px; color: #000; border-top: 2.5px solid #000; border-bottom: 2.5px solid #000; letter-spacing: 0.3px; }

        .pra-badge { border: 2px solid #000; padding: 4px; margin: 3px 0; text-align: center; font-size: 10px; overflow: hidden; color: #000; font-weight: normal; }
        .pra-badge .pra-title { font-size: 12px; font-weight: bold; margin-bottom: 2px; color: #000; }
        .pra-badge .pra-number { font-size: 10px; font-weight: bold; letter-spacing: 0; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; max-width: 100%; display: block; color: #000; }
        .local-badge { border: 1.5px dashed #000; padding: 4px; margin: 3px 0; text-align: center; font-size: 10px; color: #000; font-weight: normal; }
        .qr-code { text-align: center; margin: 3px 0; }
        .qr-code img { width: 84px; height: 84px; }
        .qr-code p { font-size: 10px; margin-top: 2px; color: #000; font-weight: normal; }

        .footer { margin-top: 3px; font-size: 11px; line-height: 1.35; color: #000; font-weight: normal; }

        @media print {
            /* PRINTABLE-WIDTH FIX v2 (owner report Jul 2026 — right edge STILL cut):
               80mm paper has only ~72mm printable width (hardware margins both sides).
               Some drivers report the FULL 80mm as the page size — width:auto then fills
               80mm and the right ~8mm falls in the unprintable strip. Cap content at the
               SAFE printable width (72mm) and center it: drivers reporting 72mm get an
               exact fit; drivers reporting 80mm get 4mm side margins that line up with
               the hardware margins. Never force physical paper width.
               v3 (Pizza Master Jul 2026): LEFT edge clipped too ("Customer" lost its
               'C') — 1mm side padding sat inside the head's dead zone on some
               printers. Side padding raised.
               v4 (Malik Chicken Broast Jul 2026): sides now 3mm (content ~66mm); 4mm TOP
               padding is deliberate breathing room so low-starting heads don't cut the logo.
               v5 (ZFC Pizza Point Jul 2026): margin auto → 0. When the Windows queue's
               default paper is A4 (misconfigured driver), auto-centering parks the body
               at ~65mm from the left — the 72mm print head then shows only the first
               1-2 letters of each line at the paper's right edge ("blank" receipts).
               LEFT-align instead: correct-size drivers print identically (3mm padding
               still clears the head's dead zone), misconfigured A4 queues stay readable.
               v6 (Pizza Master Jul 2026): v5's left-align regressed the LEFT edge on
               drivers whose page size is the FULL 80mm roll ("Roll Paper 80 x 297mm"):
               body sits at paper x=0, so 3mm side padding < the head's ~4mm dead zone —
               thin first glyphs vanish ("ITEM" → "TEM"). Sides raised to 4mm: full-width
               drivers clear the dead zone, 72mm-reporting drivers lose only 2mm content. */
            body { width: auto; max-width: 72mm; padding: 4mm 4mm 1mm; margin: 0; }
            /* v6 companion (Pizza Master Jul 2026): boxed elements draw their
               VERTICAL border lines at the extreme content edges — even with 4mm
               body padding those 1.5-2px lines sit exactly on the dead-zone
               boundary and are the first thing a misaligned head eats (video
               showed the SALE RECEIPT / TOTAL / PAYMENT boxes missing their left
               border). Inset every full-width bordered box 1mm per side so the
               corners always print; text tables keep the full content width. */
            .invoice-numbers, .pra-badge, .local-badge, .edge-box { margin-left: 1mm; margin-right: 1mm; }
            .no-print { display: none !important; }
        }
        @media screen {
            body { padding: 10px; }
            .no-print { margin-bottom: 15px; text-align: center; font-family: Arial, sans-serif; }
        }
    </style>
    @if(!empty($pdfMode))
    <style>
        /* DomPDF-only overrides: DomPDF's default media type is "screen", so the
           @media print rules above never apply and the fixed 80mm body width
           (plus padding) overflows the 226.77pt page — clipping the right edge.
           Let the body fill the PDF page instead, and hide screen-only chrome. */
        body { width: auto !important; max-width: none !important; margin: 0 !important; padding: 3mm !important; }
        .no-print { display: none !important; }
    </style>
    @endif
    @if(!empty($pdfMode) && ($pdfPaper ?? 'thermal') === 'a4')
    <style>
        /* A4 PDF PAPER MODE (customer video Jul 2026): the downloaded 80mm-wide PDF
           printed on a regular (non-thermal) printer came out shifted to the paper's
           right edge and clipped — desktop PDF viewers CENTER a small page on the
           driver's A4 canvas, and narrow tray paper only catches the left part.
           Opt-in per company (Receipt Settings → PDF Download Paper): the PDF page
           becomes real A4 and the receipt strip sits at the TOP-LEFT, so any office
           printer prints it straight. Must come AFTER the pdfMode block above so
           these !important rules win the cascade. */
        body { width: 72mm !important; max-width: 72mm !important; margin: 8mm 0 0 8mm !important; }
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
        <button onclick="window.print()" style="padding: 10px 30px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; margin-right: 10px;">{{ __('pos.receipt_print') }}</button>
        <a href="{{ route('pos.transactions') }}" target="_top" style="padding: 10px 30px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block;">{{ __('pos.receipt_back') }}</a>
    </div>
    <script>
        var isInIframe = (window.self !== window.top);
        if (isInIframe) {
            var actions = document.getElementById('receiptActions');
            if (actions) actions.style.display = 'none';
        }
        // After print: keep the window open so cashier can reprint or take other actions.
        // Auto-close/redirect intentionally removed (user feedback: window was vanishing
        // after print dialog closed, blocking reprint and KOT). The on-page "Print Receipt"
        // and "Back to Transactions" buttons remain visible — user closes manually.
        // Iframe path is unaffected; the iframe self-signals afterprint via postMessage below.

        // AUTO-PRINT + POSTMESSAGE SIGNAL (Restaurant POS sale-time chain)
        // ----------------------------------------------------------------
        // When loaded with ?auto_print=1, automatically fire window.print().
        // When loaded with ?_signal=<token> inside the parent's hidden print
        // iframe, attach `afterprint` here (where it's reliable per spec) and
        // signal the parent via postMessage when the print dialog actually
        // closes. Parent then chains the next print (e.g. KOT) only AFTER
        // receiving this signal — eliminates the "KOT pops up before receipt"
        // race. Mirrors the wiring previously used in pos.restaurant.receipt.
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
                // Safety net inside the iframe — if afterprint never fires
                // (silent printer drivers), signal the parent after a generous
                // wait so the chain still advances.
                setTimeout(signalParent, 20000);
            }
            // First-print stutter fix (customer report, 25 Jul 2026): fire print
            // only once fonts/rendering are settled — cheap Windows thermal drivers
            // truncate jobs rasterized while the page is still busy. 2.5s failsafe
            // guarantees print ALWAYS fires (hidden iframes may throttle promises).
            var tnPrinted = false;
            var tnFirePrint = function() {
                if (tnPrinted) return;
                tnPrinted = true;
                window.print();
            };
            var tnFontsReady = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
            tnFontsReady.then(function() { setTimeout(tnFirePrint, 500); });
            setTimeout(tnFirePrint, 2500);
        });
    </script>

    @php
        // BULLETPROOF LOGO LOADING — Company::receiptLogoDataUri() embeds the logo
        // as a base64 data URI, downscaling multi-MB uploads to a cached small PNG.
        // NEVER embed the raw file here: huge base64 payloads break the Desktop
        // Agent's data-URL silent print (ERR_INVALID_URL) and bloat every receipt.
        $logoDataUri = $company->receiptLogoDataUri();
        $logoMissing = (bool) ($company->logo_path && !$logoDataUri);
        $addressLine = trim(($company->address ?? '') . (($company->city) ? ', ' . $company->city : ''));
        $phoneLine = trim(implode(' / ', array_filter([$company->phone ?? null, $company->mobile ?? null])));
        // Owner (Jul 2026): PRA and Local bills each have their OWN display set —
        // resolved per-transaction (PRA = pra mode + non-NULL status; else Local).
        $rp = $company->posReceiptPrefsFor($transaction);
        // Owner (Jul 2026, Pizza Master feedback): serial badge moves to the TOP box —
        // non-fiscal receipts merge "SALE RECEIPT / PROVISIONAL BILL" + serial into the
        // top invoice box (no duplicate badge at the bottom = shorter slip).
        // PRA fiscal + offline bills keep the classic POS/PRA number box.
        $rcptPraFiscal = $transaction->pra_status === 'submitted' && $transaction->pra_invoice_number;
        $rcptOffline = $transaction->pra_status === 'offline';
        $rcptTopBadge = !$rcptPraFiscal && !$rcptOffline;
        $rcptTopProvisional = ($transaction->invoice_mode ?? 'pra') === 'local';
    @endphp
    <div class="header text-center">
        {{-- Logo placement (customer request Jul 2026): logo sits to the RIGHT of the
             business name on one row (was stacked above it) — the header prints
             shorter. Table layout (not flex) so DomPDF renders it identically.
             'center' style (Pizza Master Jul 2026): LARGE centered logo above the
             name, like classic printed bills — opt-in via Receipt Settings. --}}
        @if($logoDataUri)
        @if($printStyle['logo'] === 'center')
        {{-- display:block kills the inline-image baseline gap under the logo
             (owner report Jul 2026: "remove space under logo"). --}}
        <div style="text-align:center; margin:0; padding:2mm 0 0; line-height:0;">
            <img src="{{ $logoDataUri }}" style="width:32mm; max-height:27mm; object-fit:contain; display:block; margin:0 auto;">
        </div>
        @if($rp['show_business_name'] ?? true)<h1>{{ $company->name }}</h1>@endif
        @else
        <table style="width:100%; border-collapse:collapse; margin-bottom:2px;">
            <tr>
                <td style="text-align:left; vertical-align:middle; width:64%; padding:0;">
                    @if($rp['show_business_name'] ?? true)<h1 style="text-align:left; margin:0;">{{ $company->name }}</h1>@endif
                </td>
                <td style="text-align:right; vertical-align:middle; width:36%; padding:0;">
                    <img src="{{ $logoDataUri }}" style="max-width:80px; max-height:42px; object-fit:contain;">
                </td>
            </tr>
        </table>
        @endif
        @else
        @if($rp['show_business_name'] ?? true)<h1>{{ $company->name }}</h1>@endif
        @endif
        @if($company->business_activity)<p>{{ $company->business_activity }}</p>@endif
        @if(!empty($addressLine) && $rp['show_address'])<p>{{ $addressLine }}</p>@endif
        @if($phoneLine && $rp['show_mobile'])<p>Tel: {{ $phoneLine }}</p>@endif
        @if($company->email && $rp['show_email'])<p>{{ $company->email }}</p>@endif
        @if($company->website)<p>{{ $company->website }}</p>@endif
        @if($company->ntn && $rp['show_ntn'])<p><strong>NTN:</strong> {{ $company->ntn }}</p>@endif
        @if(!empty($company->fbr_registration_no))<p><strong>STRN:</strong> {{ $company->fbr_registration_no }}</p>@endif
    </div>

    <div class="separator"></div>

    @if($rcptTopBadge)
    <div class="invoice-numbers" style="text-align:center; padding:4px 5px;">
        <strong style="font-size:12px; color:#000;">{{ $rcptTopProvisional ? __('pos.receipt_provisional_bill') : __('pos.receipt_sale_receipt') }}</strong><br>
        <span style="font-size:13px; font-weight:bold; color:#000;">{{ $transaction->invoice_number }}</span>
        @if($rcptTopProvisional)<br><span style="font-size:9px; color:#000;">{{ __('pos.receipt_provisional_note') }}</span>@endif
    </div>
    @else
    <div class="invoice-numbers">
        <table class="inv-table">
            <tr>
                <td class="inv-label">{{ __('pos.receipt_pos_invoice') }}:</td>
                <td class="inv-value">{{ $transaction->invoice_number }}</td>
            </tr>
            @if($transaction->pra_invoice_number)
            <tr>
                <td class="inv-label">{{ __('pos.receipt_pra_fiscal') }}:</td>
                <td class="inv-value">{{ $transaction->pra_invoice_number }}</td>
            </tr>
            @endif
        </table>
    </div>
    @endif

    {{-- Order type (customer feedback, 23 Jul 2026): Dine-In / Take Away / Delivery
         printed bold + centered so counter staff spot it instantly. Only when the
         bill actually carries an order_type (restaurant flow) — retail bills skip. --}}
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
        <span style="display:inline-block; border:1.5px solid #000; padding:1px 10px; font-size:11px; font-weight:bold; letter-spacing:1px;">{{ $rcptOrderType }}</span>
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
        @if($transaction->customer_phone)
        <tr><td class="info-label">{{ __('pos.receipt_phone') }}:</td><td class="info-value">{{ $transaction->customer_phone }}</td></tr>
        @endif
        @if($transaction->delivery_address)
        <tr><td class="info-label">{{ __('pos.receipt_deliver') }}:</td><td class="info-value">{{ $transaction->delivery_address }}</td></tr>
        @endif
        {{-- Delivery Riders (Jul 2026): assigned rider on delivery receipts (display-only, all branches). --}}
        @if($transaction->rider)
        <tr><td class="info-label">{{ __('pos.receipt_rider') }}:</td><td class="info-value">{{ $transaction->rider->name }}</td></tr>
        @endif
        <tr><td class="info-label">{{ __('pos.receipt_payment_mode') }}:</td><td class="info-value">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</td></tr>
        @if($transaction->creator && $rp['show_cashier'])
        <tr><td class="info-label">{{ __('pos.receipt_cashier') }}:</td><td class="info-value">{{ $transaction->creator->name }}</td></tr>
        @endif
    </table>

    <div class="separator"></div>

    @php
        // Owner decision (Jul 2026): the "Show Tax on Receipt" toggle applies to ALL
        // receipts, including PRA fiscal ones. When OFF the customer copy shows only
        // the grand total (subtotal + tax lines hidden — showing subtotal alone would
        // reveal the tax gap). Item Rate/Amt show the ORIGINAL as-entered (ex-tax)
        // prices — owner update Jul 2026: shelf prices must read exactly as typed,
        // even though lines then intentionally do NOT sum to the grand total.
        // Tax is ALWAYS submitted to PRA in full regardless; full details remain
        // visible via PRA Sahulat app QR scan.
        // Per-type since Jul 2026: PRA receipts read the PRA set (pos_receipt_show_tax
        // column), Local receipts read the Local set — both resolved in $rp above.
        $showTaxLines = (bool) ($rp['show_tax'] ?? true);
        // Tax-Inclusive Pricing (Menu-Rate-Final): item lines are MENU (tax-in)
        // prices; the header subtotal column is stored ex-tax-consistent, so the
        // receipt Subtotal re-adds the included tax to read as the menu sum
        // (lines then sum exactly to Subtotal; TOTAL = Subtotal − Discount).
        $rcptInclusive = (bool) ($transaction->tax_inclusive ?? false);
        // Card-save (mode 3) bills where the bill's rate differs from the MENU rate
        // (i.e. card/digital): items stay at menu prices, "Menu Total" = item sum,
        // and the customer's saving shows as an explicit "Card Discount" line
        // (stays visible even when Show-Tax is OFF — it explains the cheaper total).
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
                    {{ $item->item_name }}@if($showTaxLines && $item->is_tax_exempt)<span class="exempt-tag">NT</span>@endif
                </td>
                <td class="col-qty">{{ rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') ?: '0' }}</td>
                <td class="col-rate">{{ number_format($lineRate, 0) }}</td>
                <td class="col-total">{{ number_format($lineAmt, 0) }}</td>
            </tr>
            {{-- Deal components (frozen snapshot) — indented, NO price columns: the
                 deal line above carries the money; these are informational only. --}}
            @if($item->item_type === 'deal' && is_array($item->deal_snapshot))
            @foreach($item->deal_snapshot as $comp)
            <tr>
                <td class="col-item" style="padding-left:8px; font-size:9px;">&nbsp;&nbsp;• {{ (int)($comp['qty'] ?? 1) }}x {{ $comp['name'] ?? 'Item' }}</td>
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
    <div style="font-size:10px; font-weight:normal; color:#000; padding:2px 0;">{{ __('pos.receipt_note') }}: {{ $transaction->notes }}</div>
    @endif

    <div class="separator"></div>

    <table class="totals-table">
        @if($showTaxLines || $rcptCardSave)
        <tr>
            <td class="tot-label">{{ $rcptCardSave ? __('pos.receipt_menu_total') : __('pos.receipt_subtotal') }}:</td>
            <td class="tot-value">PKR {{ number_format($rcptSubtotal, 2) }}</td>
        </tr>
        @endif
        @if($transaction->discount_amount > 0)
        <tr>
            <td class="tot-label">{{ __('pos.receipt_discount') }}{{ $transaction->discount_type === 'percentage' ? ' ('.$transaction->discount_value.'%)' : '' }}:</td>
            <td class="tot-value">-PKR {{ number_format($showTaxLines ? $transaction->discount_amount : round((float) $transaction->discount_amount), 2) }}</td>
        </tr>
        @endif
        @if($rcptCardSave && $rcptCardSaving > 0.009)
        <tr>
            <td class="tot-label">{{ __('pos.receipt_card_discount') }}:</td>
            <td class="tot-value">-PKR {{ number_format($rcptCardSaving, 2) }}</td>
        </tr>
        @endif
        @if($showTaxLines)
        <tr>
            <td class="tot-label">{{ __('pos.receipt_tax') }} ({{ number_format($transaction->tax_rate, 0) }}%{{ $rcptInclusive ? ' incl.' : '' }}):</td>
            <td class="tot-value">PKR {{ number_format($transaction->tax_amount, 2) }}</td>
        </tr>
        @endif
    </table>
    <div class="double-separator"></div>
    <table class="totals-table">
        <tr class="grand-total">
            <td class="tot-label">{{ __('pos.receipt_total_caps') }}:</td>
            <td class="tot-value">PKR {{ number_format($showTaxLines ? $transaction->total_amount : round((float) $transaction->total_amount), 2) }}</td>
        </tr>
        {{-- Cash Received / Wapsi (owner request, Jul 2026): printed only when the
             cashier actually typed the received cash AND change is due. --}}
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

    @if(($transaction->order_type ?? '') === 'delivery' || !empty($transaction->delivery_address) || $transaction->rider)
    @php
        // ZFC feedback Jul 2026: delivery riders must see AT A GLANCE whether the
        // bill is cash-to-collect or already paid by card/online. Owner update
        // (Pizza Master, Jul 2026): boxed method prints on DELIVERY bills ONLY —
        // counter/takeaway bills drop it (paper saving; "Payment:" info line stays).
        // Stored card bucket = 'debit_card' (+aliases); QR = 'qr_payment'.
        $rcptPayRaw = strtolower((string) $transaction->payment_method);
        $rcptPayLabel = $rcptPayRaw === 'cash' ? 'CASH'
            : (in_array($rcptPayRaw, ['card', 'debit_card', 'credit_card'], true) ? 'CARD'
            : ($rcptPayRaw === 'qr_payment' ? 'ONLINE / QR' : strtoupper(str_replace('_', ' ', $rcptPayRaw))));
    @endphp
    <div class="edge-box" style="border: 2px solid #000; text-align: center; font-weight: bold; font-size: 14px; letter-spacing: 1px; padding: 3px 2px; margin: 3px 0; color: #000;">PAYMENT: {{ $rcptPayLabel }}</div>
    @endif

    <div class="separator"></div>

    @if($transaction->pra_status === 'submitted' && $transaction->pra_invoice_number)
    <div class="pra-badge">
        <div class="pra-title">{{ __('pos.receipt_pra_fiscal_invoice') }}</div>
        <div>POS: {{ $transaction->invoice_number }}</div>
        <div class="pra-number">PRA: {{ $transaction->pra_invoice_number }}</div>
    </div>
    @php
        // QR carries the RAW PRA invoice number (PRA Sahulat app format).
        $praQr = $transaction->pra_invoice_number
            ? \App\Support\QrImage::dataUri($transaction->pra_invoice_number)
            : ($transaction->pra_qr_code ?: '');
    @endphp
    @if($praQr)
    <div class="qr-code">
        <img src="{{ $praQr }}" alt="PRA Verification QR">
        <p>{{ __('pos.receipt_scan_verify') }}</p>
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
        $publicUrl = \App\Http\Controllers\PublicProfileController::publicUrlFor($transaction->company);
        if ($publicUrl) {
            $qrUrl = \App\Support\QrImage::dataUri($publicUrl);
            $qrCaption = __('pos.receipt_scan_menu');
        } else {
            // ZFC issue #9 (28 Jul 2026): business name OFF => QR payload must
            // not leak the name either (owner scanned QR, saw "business" field).
            $qrPayload = [
                'type' => $rcptIsProvisional ? 'Provisional Bill' : 'Sale Receipt',
                'inv' => $transaction->invoice_number,
                'date' => $transaction->created_at->format('d/m/Y H:i'),
                'total' => number_format($transaction->total_amount, 2),
            ];
            if ($rp['show_business_name'] ?? true) {
                $qrPayload['business'] = $transaction->company->name ?? 'NestPOS';
            }
            $qrData = json_encode($qrPayload);
            $qrUrl = \App\Support\QrImage::dataUri($qrData);
            $qrCaption = __('pos.receipt_scan_invoice');
        }
    @endphp
    {{-- Bottom SALE RECEIPT / PROVISIONAL badge removed (owner, Jul 2026) — the
         serial badge now prints in the TOP box; only the QR remains down here. --}}
    @if($qrUrl)
    <div class="qr-code">
        <img src="{{ $qrUrl }}" alt="Invoice QR" style="width: 84px; height: 84px; margin: 3px auto;">
        <p style="font-size: 10px; color: #000;">{{ $qrCaption }}</p>
    </div>
    @endif
    @endif

    <div class="footer text-center">
        @if($rp['show_footer'])<p>{{ $rp['footer_text'] ?? __('pos.receipt_thank_purchase') }}</p>@endif
        @if($rp['show_developed_by'] ?? true)<p>Developed by: taxnest.com.pk</p>@endif
        <p>{{ now()->format('d/m/Y h:i:s A') }}</p>
    </div>
</body>
</html>
