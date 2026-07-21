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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        /* FONT SIMPLIFICATION (customer feedback Jul 2026): plain drafting —
           no italics, bold ONLY on business name / invoice numbers / grand total.
           Sizes trimmed ~1px + tighter spacing so the slip prints shorter while
           staying readable on cheap thermal heads. */
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
        .separator { border-top: 1px dashed #000; margin: 4px 0; }
        .double-separator { border-top: 2px solid #000; margin: 4px 0; }

        .header { margin-bottom: 5px; }
        .header h1 { font-size: 14px; font-weight: bold; margin-bottom: 2px; word-wrap: break-word; color: #000; }
        .header p { font-size: 9px; line-height: 1.35; word-wrap: break-word; color: #000; font-weight: normal; }

        .info-table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .info-table td { font-size: 10px; padding: 1px 0; vertical-align: top; color: #000; font-weight: normal; }
        .info-table .info-label { width: 32%; white-space: nowrap; color: #000; }
        .info-table .info-value { width: 68%; text-align: right; word-wrap: break-word; color: #000; }

        .invoice-numbers { border: 1.5px solid #000; padding: 5px; margin: 5px 0; }
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table td { font-size: 10px; padding: 1px 0; vertical-align: top; color: #000; }
        .inv-table .inv-label { white-space: nowrap; width: 35%; color: #000; }
        .inv-table .inv-value { text-align: right; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 9px; font-weight: bold; color: #000; }

        .items-table { width: 100%; margin: 3px 0; border-collapse: collapse; table-layout: fixed; }
        .items-table th { font-size: 9px; text-transform: uppercase; border-bottom: 1.5px solid #000; border-top: 1.5px solid #000; padding: 3px 1px; text-align: left; font-weight: normal; color: #000; }
        .items-table td { font-size: 10px; padding: 3px 1px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; color: #000; font-weight: normal; }
        .items-table .col-item { width: 44%; text-align: left; }
        .items-table .col-qty { width: 16%; text-align: center; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .items-table .col-rate { width: 18%; text-align: right; }
        .items-table .col-total { width: 22%; text-align: right; }
        .items-table tbody tr { border-bottom: 1px dashed #000; }
        .items-table tbody tr:last-child { border-bottom: none; }
        .exempt-tag { font-size: 8px; font-weight: bold; color: #000; border: 1px solid #000; padding: 0 3px; margin-left: 3px; vertical-align: middle; letter-spacing: 0.3px; }
        .exempt-note { font-size: 9px; font-weight: normal; color: #000; text-align: center; margin: 3px 0 2px; padding: 2px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; letter-spacing: 0.4px; }

        .totals-table { width: 100%; border-collapse: collapse; margin: 3px 0; }
        .totals-table td { font-size: 10px; padding: 2px 0; vertical-align: top; color: #000; font-weight: normal; }
        .totals-table .tot-label { text-align: left; color: #000; }
        .totals-table .tot-value { text-align: right; white-space: nowrap; color: #000; }
        .totals-table .grand-total td { font-size: 15px; font-weight: bold; padding: 6px 3px; color: #000; border-top: 2.5px solid #000; border-bottom: 2.5px solid #000; letter-spacing: 0.3px; }

        .pra-badge { border: 2px solid #000; padding: 5px; margin: 5px 0; text-align: center; font-size: 10px; overflow: hidden; color: #000; font-weight: normal; }
        .pra-badge .pra-title { font-size: 11px; font-weight: bold; margin-bottom: 2px; color: #000; }
        .pra-badge .pra-number { font-size: 9px; font-weight: bold; letter-spacing: 0; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; max-width: 100%; display: block; color: #000; }
        .local-badge { border: 1.5px dashed #000; padding: 5px; margin: 5px 0; text-align: center; font-size: 10px; color: #000; font-weight: normal; }
        .qr-code { text-align: center; margin: 5px 0; }
        .qr-code img { width: 100px; height: 100px; }
        .qr-code p { font-size: 9px; margin-top: 2px; color: #000; font-weight: normal; }

        .footer { margin-top: 6px; font-size: 9px; line-height: 1.4; color: #000; font-weight: normal; }

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
               printers. Side padding raised to 2.5mm both sides (content ~67mm). */
            body { width: auto; max-width: 72mm; padding: 1mm 2.5mm; margin: 0 auto; }
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
    @php $printStyle = $company->posReceiptStyle(); @endphp
    @if($printStyle['bold'])
    <style>
        /* BOLD PRINT STYLE (customer request Jul 2026 — Pizza Master): whole
           receipt in bold like the KOT font — cheap thermal heads print the
           plain weight too thin/light. Opt-in per company (Receipt Settings);
           the text stroke lays down extra ink like the KOT notes fix. */
        body, td, th, p, span, div, h1, strong { font-weight: bold !important; }
        body { -webkit-text-stroke: 0.2px #000; }
    </style>
    @endif
</head>
<body>
    <div class="no-print" id="receiptActions">
        <button onclick="window.print()" style="padding: 10px 30px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; margin-right: 10px;">Print Receipt</button>
        <a href="{{ route('pos.transactions') }}" target="_top" style="padding: 10px 30px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block;">Back to Transactions</a>
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
            setTimeout(function() { window.print(); }, 500);
        });
    </script>

    @php
        // BULLETPROOF LOGO LOADING — embeds logo as base64 data URI when file exists
        // on disk. Works in browser print, PDF render, and share flows without
        // depending on `php artisan storage:link` being run on the server.
        $logoDataUri = null;
        $logoMissing = false;
        if ($company->logo_path) {
            $logoFile = public_path('storage/' . $company->logo_path);
            if (!file_exists($logoFile)) { $logoFile = storage_path('app/public/' . $company->logo_path); }
            if (file_exists($logoFile)) {
                $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
                $mime = $ext === 'jpg' ? 'jpeg' : $ext;
                $logoDataUri = 'data:image/' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
            } else {
                $logoMissing = true;
            }
        }
        $addressLine = trim(($company->address ?? '') . (($company->city) ? ', ' . $company->city : ''));
        $phoneLine = trim(implode(' / ', array_filter([$company->phone ?? null, $company->mobile ?? null])));
        // Owner (Jul 2026): PRA and Local bills each have their OWN display set —
        // resolved per-transaction (PRA = pra mode + non-NULL status; else Local).
        $rp = $company->posReceiptPrefsFor($transaction);
    @endphp
    <div class="header text-center">
        {{-- Logo placement (customer request Jul 2026): logo sits to the RIGHT of the
             business name on one row (was stacked above it) — the header prints
             shorter. Table layout (not flex) so DomPDF renders it identically.
             'center' style (Pizza Master Jul 2026): LARGE centered logo above the
             name, like classic printed bills — opt-in via Receipt Settings. --}}
        @if($logoDataUri)
        @if($printStyle['logo'] === 'center')
        <div style="text-align:center; margin-bottom:3px;">
            <img src="{{ $logoDataUri }}" style="width:42mm; max-height:36mm; object-fit:contain;">
        </div>
        <h1>{{ $company->name }}</h1>
        @else
        <table style="width:100%; border-collapse:collapse; margin-bottom:2px;">
            <tr>
                <td style="text-align:left; vertical-align:middle; width:64%; padding:0;">
                    <h1 style="text-align:left; margin:0;">{{ $company->name }}</h1>
                </td>
                <td style="text-align:right; vertical-align:middle; width:36%; padding:0;">
                    <img src="{{ $logoDataUri }}" style="max-width:80px; max-height:42px; object-fit:contain;">
                </td>
            </tr>
        </table>
        @endif
        @else
        <h1>{{ $company->name }}</h1>
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

    <div class="invoice-numbers">
        <table class="inv-table">
            <tr>
                <td class="inv-label">POS Invoice #:</td>
                <td class="inv-value">{{ $transaction->invoice_number }}</td>
            </tr>
            @if($transaction->pra_invoice_number)
            <tr>
                <td class="inv-label">PRA Fiscal #:</td>
                <td class="inv-value">{{ $transaction->pra_invoice_number }}</td>
            </tr>
            @endif
        </table>
    </div>

    <table class="info-table">
        <tr><td class="info-label">Date:</td><td class="info-value">{{ $transaction->created_at->format('d/m/Y h:i A') }}</td></tr>
        @if($transaction->terminal)
        <tr><td class="info-label">Terminal:</td><td class="info-value">{{ $transaction->terminal->terminal_name }}</td></tr>
        @endif
        @if($transaction->customer_name)
        <tr><td class="info-label">Customer:</td><td class="info-value">{{ $transaction->customer_name }}</td></tr>
        @endif
        @if($transaction->customer_phone)
        <tr><td class="info-label">Phone:</td><td class="info-value">{{ $transaction->customer_phone }}</td></tr>
        @endif
        @if($transaction->delivery_address)
        <tr><td class="info-label">Deliver:</td><td class="info-value">{{ $transaction->delivery_address }}</td></tr>
        @endif
        {{-- Delivery Riders (Jul 2026): assigned rider on delivery receipts (display-only, all branches). --}}
        @if($transaction->rider)
        <tr><td class="info-label">Rider:</td><td class="info-value">{{ $transaction->rider->name }}</td></tr>
        @endif
        <tr><td class="info-label">Payment:</td><td class="info-value">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</td></tr>
        @if($transaction->creator && $rp['show_cashier'])
        <tr><td class="info-label">Cashier:</td><td class="info-value">{{ $transaction->creator->name }}</td></tr>
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
                <th class="col-item">Item</th>
                <th class="col-qty">Qty</th>
                <th class="col-rate">Rate</th>
                <th class="col-total">Amt</th>
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
    <div style="font-size:10px; font-weight:normal; color:#000; padding:2px 0;">Note: {{ $transaction->notes }}</div>
    @endif

    <div class="separator"></div>

    <table class="totals-table">
        @if($showTaxLines || $rcptCardSave)
        <tr>
            <td class="tot-label">{{ $rcptCardSave ? 'Menu Total' : 'Subtotal' }}:</td>
            <td class="tot-value">PKR {{ number_format($rcptSubtotal, 2) }}</td>
        </tr>
        @endif
        @if($transaction->discount_amount > 0)
        <tr>
            <td class="tot-label">Discount{{ $transaction->discount_type === 'percentage' ? ' ('.$transaction->discount_value.'%)' : '' }}:</td>
            <td class="tot-value">-PKR {{ number_format($showTaxLines ? $transaction->discount_amount : round((float) $transaction->discount_amount), 2) }}</td>
        </tr>
        @endif
        @if($rcptCardSave && $rcptCardSaving > 0.009)
        <tr>
            <td class="tot-label">Card Discount:</td>
            <td class="tot-value">-PKR {{ number_format($rcptCardSaving, 2) }}</td>
        </tr>
        @endif
        @if($showTaxLines)
        <tr>
            <td class="tot-label">Tax ({{ number_format($transaction->tax_rate, 0) }}%{{ $rcptInclusive ? ' incl.' : '' }}):</td>
            <td class="tot-value">PKR {{ number_format($transaction->tax_amount, 2) }}</td>
        </tr>
        @endif
    </table>
    <div class="double-separator"></div>
    <table class="totals-table">
        <tr class="grand-total">
            <td class="tot-label">TOTAL:</td>
            <td class="tot-value">PKR {{ number_format($showTaxLines ? $transaction->total_amount : round((float) $transaction->total_amount), 2) }}</td>
        </tr>
    </table>

    <div class="separator"></div>

    @if($transaction->pra_status === 'submitted' && $transaction->pra_invoice_number)
    <div class="pra-badge">
        <div class="pra-title">PRA FISCAL INVOICE</div>
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
        <p>Scan with PRA Sahulat App to verify</p>
    </div>
    @endif
    @elseif($transaction->pra_status === 'offline')
    <div class="local-badge">
        OFFLINE INVOICE<br>
        Will sync to PRA automatically<br>
        {{ $transaction->invoice_number }}
    </div>
    @else
    @php
        // F8: local/provisional receipts carry the PUBLIC profile QR when the
        // company enabled its public page (PRA fiscal branch above is untouched).
        $publicUrl = \App\Http\Controllers\PublicProfileController::publicUrlFor($transaction->company);
        if ($publicUrl) {
            $qrUrl = \App\Support\QrImage::dataUri($publicUrl);
            $qrCaption = 'Scan to view our menu & info';
        } else {
            $qrData = json_encode([
                'type' => 'Provisional Bill',
                'inv' => $transaction->invoice_number,
                'date' => $transaction->created_at->format('d/m/Y H:i'),
                'total' => number_format($transaction->total_amount, 2),
                'business' => $transaction->company->name ?? 'NestPOS',
            ]);
            $qrUrl = \App\Support\QrImage::dataUri($qrData);
            $qrCaption = 'Scan for invoice details';
        }
    @endphp
    <div class="local-badge" style="border: 2px dashed #000; color: #000; padding: 6px;">
        <strong style="font-size: 12px; color: #000;">PROVISIONAL BILL</strong><br>
        <span style="font-weight: bold;">{{ $transaction->invoice_number }}</span><br>
        <span style="font-size: 10px; color: #000;">This is a provisional bill for your reference</span>
    </div>
    @if($qrUrl)
    <div class="qr-code">
        <img src="{{ $qrUrl }}" alt="Invoice QR" style="width: 100px; height: 100px; margin: 4px auto;">
        <p style="font-size: 10px; color: #000;">{{ $qrCaption }}</p>
    </div>
    @endif
    @endif

    <div class="footer text-center">
        @if($rp['show_footer'])<p>{{ $rp['footer_text'] ?? 'Thank you for your purchase!' }}</p>@endif
        <p>Developed by: taxnest.com.pk</p>
        <p>{{ now()->format('d/m/Y h:i:s A') }}</p>
    </div>
</body>
</html>
