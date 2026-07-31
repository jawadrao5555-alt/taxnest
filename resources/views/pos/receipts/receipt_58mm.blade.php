<!DOCTYPE html>
<html lang="en">
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
            var tnFontsReady = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
            tnFontsReady.then(function() { setTimeout(tnFirePrint, 500); });
            setTimeout(tnFirePrint, 2500);
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
    @endphp
    <div class="header text-center">
        {{-- Logo placement (customer request Jul 2026): logo sits to the RIGHT of the
             business name on one row (was stacked above it) — the header prints
             shorter. Table layout (not flex) so DomPDF renders it identically. --}}
        @if($logoDataUri)
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
        @if($phoneLine && $rp['show_mobile'])<p>Tel: {{ $phoneLine }}</p>@endif
        @if($company->email && $rp['show_email'])<p>{{ $company->email }}</p>@endif
        @if($company->ntn && $rp['show_ntn'])<p><strong>NTN:</strong> {{ $company->ntn }}</p>@endif
        @if(!empty($company->fbr_registration_no))<p><strong>STRN:</strong> {{ $company->fbr_registration_no }}</p>@endif
    </div>

    <div class="separator"></div>

    @if($rcptTopBadge)
    <div class="invoice-numbers" style="text-align:center; padding:3px 4px;">
        <strong style="font-size:10px; color:#000;">{{ $rcptTopProvisional ? __('pos.receipt_provisional_bill') : __('pos.receipt_sale_receipt') }}</strong><br>
        <span style="font-size:11px; font-weight:bold; color:#000;">{{ $transaction->invoice_number }}</span>
        @if($rcptTopProvisional)<br><span style="font-size:8px; color:#000;">{{ __('pos.receipt_provisional_note') }}</span>@endif
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
        <tr><td class="info-label">{{ __('pos.receipt_payment_mode') }}:</td><td class="info-value">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</td></tr>
        @if($transaction->creator && $rp['show_cashier'])
        <tr><td class="info-label">{{ __('pos.receipt_cashier') }}:</td><td class="info-value">{{ $transaction->creator->name }}</td></tr>
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
                    {{ $item->item_name }}@if($showTaxLines && $item->is_tax_exempt)<span class="exempt-tag">NT</span>@endif
                </td>
                <td class="col-qty">{{ rtrim(rtrim(number_format($item->quantity, 2, '.', ''), '0'), '.') ?: '0' }}</td>
                <td class="col-rate">{{ number_format($lineRate, 0) }}</td>
                <td class="col-total">{{ number_format($lineAmt, 0) }}</td>
            </tr>
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
    <div style="font-size:9px; font-weight:normal; color:#000; padding:1px 0;">{{ __('pos.receipt_note') }}: {{ $transaction->notes }}</div>
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

    @if(($transaction->order_type ?? '') === 'delivery' || !empty($transaction->delivery_address) || $transaction->rider)
    @php
        // ZFC feedback Jul 2026: riders must see AT A GLANCE cash vs card/online.
        // Owner update (Pizza Master, Jul 2026): boxed method on DELIVERY bills ONLY
        // (mirrors receipt_80mm; keep in sync).
        $rcptPayRaw = strtolower((string) $transaction->payment_method);
        $rcptPayLabel = $rcptPayRaw === 'cash' ? 'CASH'
            : (in_array($rcptPayRaw, ['card', 'debit_card', 'credit_card'], true) ? 'CARD'
            : ($rcptPayRaw === 'qr_payment' ? 'ONLINE / QR' : strtoupper(str_replace('_', ' ', $rcptPayRaw))));
    @endphp
    <div style="border: 2px solid #000; text-align: center; font-weight: bold; font-size: 12px; letter-spacing: 1px; padding: 3px 2px; margin: 3px 0; color: #000;">PAYMENT: {{ $rcptPayLabel }}</div>
    @endif

    <div class="separator"></div>

    @if($transaction->pra_status === 'submitted' && $transaction->pra_invoice_number)
    <div class="pra-badge">
        <div class="pra-title">{{ __('pos.receipt_pra_fiscal_short') }}</div>
        <div class="pra-number">{{ $transaction->pra_invoice_number }}</div>
    </div>
    @php
        $praQr = $transaction->pra_invoice_number
            ? \App\Support\QrImage::dataUri($transaction->pra_invoice_number)
            : ($transaction->pra_qr_code ?: '');
    @endphp
    @if($praQr)
    <div class="qr-code">
        <img src="{{ $praQr }}" alt="PRA QR">
        <p>{{ __('pos.receipt_scan_verify_short') }}</p>
    </div>
    @endif
    @elseif($transaction->pra_status === 'offline')
    <div class="local-badge">{{ __('pos.receipt_offline_sync') }}</div>
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
            // not leak the name either.
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
            $qrCaption = __('pos.receipt_scan_details');
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
        @if($rp['show_footer'])<p>{{ $rp['footer_text'] ?? __('pos.receipt_thank_you') }}</p>@endif
        @if($rp['show_developed_by'] ?? true)<p>Developed by: taxnest.com.pk</p>@endif
        <p>{{ now()->format('d/m/Y h:i A') }}</p>
    </div>
</body>
</html>
