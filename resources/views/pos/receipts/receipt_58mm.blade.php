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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 10px;
            width: 58mm;
            max-width: 58mm;
            margin: 0 auto;
            padding: 2mm;
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
        .separator { border-top: 1px dashed #000; margin: 5px 0; }
        .double-separator { border-top: 2px solid #000; margin: 5px 0; }

        .header { margin-bottom: 5px; }
        .header h1 { font-size: 12px; font-weight: bold; margin-bottom: 2px; word-wrap: break-word; color: #000; }
        .header p { font-size: 9px; line-height: 1.3; word-wrap: break-word; color: #000; font-weight: 600; }

        .info-table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .info-table td { font-size: 9px; padding: 2px 0; vertical-align: top; color: #000; font-weight: 600; }
        .info-table .info-label { width: 30%; font-weight: bold; white-space: nowrap; color: #000; }
        .info-table .info-value { width: 70%; text-align: right; word-wrap: break-word; color: #000; }

        .invoice-numbers { border: 1.5px solid #000; padding: 4px; margin: 5px 0; }
        .inv-table { width: 100%; border-collapse: collapse; }
        .inv-table td { font-size: 9px; padding: 2px 0; vertical-align: top; color: #000; }
        .inv-table .inv-label { font-weight: bold; white-space: nowrap; width: 30%; color: #000; }
        .inv-table .inv-value { text-align: right; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; font-family: 'Courier New', monospace; font-size: 8px; font-weight: bold; color: #000; }

        .items-table { width: 100%; margin: 3px 0; border-collapse: collapse; table-layout: fixed; }
        .items-table th { font-size: 8px; text-transform: uppercase; border-bottom: 1.5px solid #000; border-top: 1.5px solid #000; padding: 3px 1px; text-align: left; font-weight: bold; color: #000; }
        .items-table td { font-size: 9px; padding: 3px 1px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; color: #000; font-weight: 600; }
        .items-table .col-item { width: 44%; text-align: left; }
        .items-table .col-qty { width: 16%; text-align: center; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .items-table .col-rate { width: 18%; text-align: right; }
        .items-table .col-total { width: 22%; text-align: right; font-weight: bold; }
        .items-table tbody tr { border-bottom: 1px dashed #000; }
        .items-table tbody tr:last-child { border-bottom: none; }
        .exempt-tag { font-size: 7px; font-weight: 800; color: #000; border: 1px solid #000; padding: 0 2px; margin-left: 2px; vertical-align: middle; }
        .exempt-note { font-size: 8px; font-weight: 700; color: #000; text-align: center; margin: 3px 0 2px; padding: 2px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; letter-spacing: 0.3px; }

        .totals-table { width: 100%; border-collapse: collapse; margin: 3px 0; }
        .totals-table td { font-size: 9px; padding: 2px 0; vertical-align: top; color: #000; font-weight: 600; }
        .totals-table .tot-label { text-align: left; color: #000; }
        .totals-table .tot-value { text-align: right; white-space: nowrap; color: #000; font-weight: bold; }
        .totals-table .grand-total td { font-size: 14px; font-weight: 900; padding: 6px 3px; color: #000; border-top: 2px solid #000; border-bottom: 2px solid #000; letter-spacing: 0.3px; }

        .pra-badge { border: 1.5px solid #000; padding: 4px; margin: 5px 0; text-align: center; font-size: 9px; overflow: hidden; color: #000; font-weight: 600; }
        .pra-badge .pra-title { font-size: 10px; font-weight: bold; margin-bottom: 2px; color: #000; }
        .pra-badge .pra-number { font-size: 8px; font-weight: bold; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all; max-width: 100%; display: block; color: #000; }
        .local-badge { border: 1.5px dashed #000; padding: 4px; margin: 5px 0; text-align: center; font-size: 9px; color: #000; font-weight: 700; }
        .qr-code { text-align: center; margin: 5px 0; }
        .qr-code img { width: 75px; height: 75px; }
        .qr-code p { font-size: 8px; margin-top: 1px; color: #000; font-weight: 600; }

        .footer { margin-top: 6px; font-size: 9px; line-height: 1.4; color: #000; font-weight: 600; }

        @media print {
            /* PRINTABLE-WIDTH FIX v2 (Jul 2026): 58mm paper prints only ~48mm. Drivers
               that report the FULL 58mm page clip the right edge with width:auto — cap
               content at the SAFE 48mm printable width and center it (also fits 52mm
               rolls, ~48mm printable). Never force physical paper width. */
            body { width: auto; max-width: 48mm; padding: 1mm; margin: 0 auto; }
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
</head>
<body>
    <div class="no-print" id="receiptActions">
        <button onclick="window.print()" style="padding: 8px 24px; background: #059669; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; margin-right: 8px;">Print</button>
        <a href="{{ route('pos.transactions') }}" target="_top" style="padding: 8px 24px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-block;">Back</a>
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
            setTimeout(function() { window.print(); }, 500);
        });
    </script>

    @php
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
        @if($logoDataUri)
        <div style="margin-bottom: 3px;">
            <img src="{{ $logoDataUri }}" style="max-width: 110px; max-height: 40px; margin: 0 auto; display: block; object-fit: contain;">
        </div>
        @endif
        <h1>{{ $company->name }}</h1>
        @if($company->business_activity)<p style="font-style:italic;">{{ $company->business_activity }}</p>@endif
        @if(!empty($addressLine) && $rp['show_address'])<p>{{ $addressLine }}</p>@endif
        @if($phoneLine && $rp['show_mobile'])<p>Tel: {{ $phoneLine }}</p>@endif
        @if($company->email && $rp['show_email'])<p>{{ $company->email }}</p>@endif
        @if($company->ntn && $rp['show_ntn'])<p><strong>NTN:</strong> {{ $company->ntn }}</p>@endif
        @if(!empty($company->fbr_registration_no))<p><strong>STRN:</strong> {{ $company->fbr_registration_no }}</p>@endif
    </div>

    <div class="separator"></div>

    <div class="invoice-numbers">
        <table class="inv-table">
            <tr>
                <td class="inv-label">POS#:</td>
                <td class="inv-value">{{ $transaction->invoice_number }}</td>
            </tr>
            @if($transaction->pra_invoice_number)
            <tr>
                <td class="inv-label">PRA#:</td>
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
        <tr><td class="info-label">Cust:</td><td class="info-value">{{ $transaction->customer_name }}</td></tr>
        @endif
        @if($transaction->delivery_address)
        <tr><td class="info-label">Deliver:</td><td class="info-value">{{ $transaction->delivery_address }}</td></tr>
        @endif
        <tr><td class="info-label">Pay:</td><td class="info-value">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</td></tr>
        @if($transaction->creator && $rp['show_cashier'])
        <tr><td class="info-label">Cashier:</td><td class="info-value">{{ $transaction->creator->name }}</td></tr>
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
    <div style="font-size:9px; font-weight:700; color:#000; padding:1px 0; font-style:italic;">Note: {{ $transaction->notes }}</div>
    @endif

    <div class="separator"></div>

    <table class="totals-table">
        @if($showTaxLines)
        <tr>
            <td class="tot-label">Subtotal:</td>
            <td class="tot-value">{{ number_format($transaction->subtotal, 2) }}</td>
        </tr>
        @endif
        @if($transaction->discount_amount > 0)
        <tr>
            <td class="tot-label">Disc:</td>
            <td class="tot-value">-{{ number_format($showTaxLines ? $transaction->discount_amount : round((float) $transaction->discount_amount), 2) }}</td>
        </tr>
        @endif
        @if($showTaxLines)
        <tr>
            <td class="tot-label">Tax ({{ number_format($transaction->tax_rate, 0) }}%):</td>
            <td class="tot-value">{{ number_format($transaction->tax_amount, 2) }}</td>
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
        <div class="pra-title">PRA FISCAL</div>
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
        <p>Scan with PRA Sahulat App</p>
    </div>
    @endif
    @elseif($transaction->pra_status === 'offline')
    <div class="local-badge">OFFLINE - Will sync to PRA</div>
    @else
    @php
        // F8: local/provisional receipts carry the PUBLIC profile QR when the
        // company enabled its public page (PRA fiscal branch above is untouched).
        $publicUrl = \App\Http\Controllers\PublicProfileController::publicUrlFor($transaction->company);
        if ($publicUrl) {
            $qrUrl = \App\Support\QrImage::dataUri($publicUrl);
            $qrCaption = 'Scan for menu & info';
        } else {
            $qrData = json_encode([
                'type' => 'Provisional Bill',
                'inv' => $transaction->invoice_number,
                'date' => $transaction->created_at->format('d/m/Y H:i'),
                'total' => number_format($transaction->total_amount, 2),
                'business' => $transaction->company->name ?? 'NestPOS',
            ]);
            $qrUrl = \App\Support\QrImage::dataUri($qrData);
            $qrCaption = 'Scan for details';
        }
    @endphp
    <div class="local-badge" style="border: 1.5px dashed #000; color: #000; padding: 6px; font-weight: 700;">
        <strong style="font-size: 10px; color: #000;">PROVISIONAL BILL</strong><br>
        <span style="font-size: 9px; font-weight: bold;">{{ $transaction->invoice_number }}</span>
    </div>
    @if($qrUrl)
    <div class="qr-code">
        <img src="{{ $qrUrl }}" alt="Invoice QR" style="width: 80px; height: 80px; margin: 3px auto;">
        <p style="font-size: 8px; color: #000; font-weight: 600;">{{ $qrCaption }}</p>
    </div>
    @endif
    @endif

    <div class="footer text-center">
        @if($rp['show_footer'])<p>{{ $rp['footer_text'] ?? 'Thank you!' }}</p>@endif
        <p>Developed by: taxnest.com.pk</p>
        <p>{{ now()->format('d/m/Y h:i A') }}</p>
    </div>
</body>
</html>
