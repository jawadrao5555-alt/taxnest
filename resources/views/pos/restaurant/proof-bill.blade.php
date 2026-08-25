<!DOCTYPE html>
@php $urduScript = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<html lang="{{ $urduScript ? 'ur' : 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proof Bill - {{ $order->order_number }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            /* Owner (29 Jul 2026): typewriter font pasand nahi — same clean
               font stack as receipt_80mm. */
            font-family: Arial, 'Helvetica Neue', Helvetica, 'Segoe UI', sans-serif;
            font-size: 13px;
            /* Thermal rule: never force body width = physical paper width
               (80mm prints ~72mm) — auto + max-width cap. */
            width: auto;
            max-width: 72mm;
            /* margin 0 (NOT auto) even on screen — ZFC's misconfigured A4 queue
               centered the body and printed only a right-edge strip; mirror
               receipt_80mm v5/v6: left-align + 4mm side padding. */
            margin: 0;
            padding: 4mm 4mm 1mm;
            background: #fff;
            color: #000;
            /* Owner (30 Jul 2026): "beech mein gap bohat zyada" — tighter rows. */
            line-height: 1.3;
        }
        .separator { border-top: 2px dashed #000; margin: 6px 0; }
        .bold { font-weight: bold; }
        .text-center { text-align: center; }
        .text-lg { font-size: 16px; }
        .text-sm { font-size: 11px; }
        .mt-1 { margin-top: 4px; }
        .flex { display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; }
        .items-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        /* Owner (30 Jul 2026): compact rows + dashed line under every item + more
           room for the item name (naam do line mein na toote). */
        .items-table td { padding: 2px; vertical-align: top; font-size: 12px; }
        .items-table tr.item-row td { border-bottom: 1px dashed #000; padding-bottom: 3px; }
        .items-table .qty { width: 9%; font-weight: bold; }
        .items-table .amt { width: 19%; text-align: right; white-space: nowrap; }
        .items-table td.amt-last { width: 21%; }
        .items-table tr.head { border-top: 2px solid #000; border-bottom: 2px solid #000; }
        .items-table tr.head td { font-weight: bold; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; padding: 3px 2px; }
        .totals td { padding: 2px 2px; font-size: 13px; }
        .totals .grand td { font-weight: bold; font-size: 16px; border-top: 2px solid #000; padding-top: 4px; }
        /* Slim single-line marker — no reversed block, no boxed banner (thermal rules). */
        .proof-line { text-align: center; font-weight: 900; font-size: 15px; letter-spacing: 1px; margin: 4px 0; }
        /* Task 1386: the table line must never break mid-name on an 80mm roll —
           same rule the kitchen slip uses (Task 1378). nowrap + a length-based
           font step set inline from PHP keeps even the longest stored name (the
           column caps at 20 chars, plus the label) on ONE piece. */
        .proof-table-line { white-space: nowrap; }
        /* PRINT: margin MUST be 0 (not auto) — auto centers the 80mm body on the
           driver's wider canvas and the thermal head prints only the left slice,
           so the bill came out as a thin right-edge strip (ZFC photo 28 Jul 2026).
           Mirror receipt_80mm's print block: width auto + 72mm cap + margin 0. */
        @media print { body { width: auto; max-width: 72mm; margin: 0; padding: 4mm 4mm 1mm; } }
        {{-- COMPANY PRINT POSITION (31 Jul 2026, Pizza Master): center/left-margin
             options apply to ALL slips now (see receipt_80mm). Opt-in; default OFF
             keeps the left-align rule above untouched. --}}
        @php
            // Pizza Master (11 Aug 2026): proof bill = customer-facing → follows the
            // RECEIPT margin columns; NULL = legacy shared kot_* fallback.
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
           Fallback Naskh stack still covers offline agent renders.
           NOTE: DomPDF PDFs never reach here — PosLocale::applyPdfSafeLocale()
           drops 'ur' → 'rur' before every PDF render (DomPDF can't shape). */
        @include('partials.urdu-print-font')
        body { font-family: 'Jameel Noori Nastaleeq', 'Noto Naskh Arabic', 'Urdu Typesetting', Tahoma, Arial, 'Segoe UI', sans-serif; line-height: 1.9; }
        @endif
    </style>
</head>
<body>
    {{-- ZFC redesign (28 Jul 2026): old-software style — big bold Bill/Table No,
         Qty/Price/Amount columns, ENGLISH only (printed bills carry no Roman Urdu).
         Business-name toggle follows the LOCAL receipt set. --}}
    @php $pp = $company->posReceiptPrefs('local'); @endphp
    @if($pp['show_business_name'] ?? true)
    <div class="text-center bold text-lg">{{ $company->business_name ?? $company->name }}</div>
    @endif
    <div class="proof-line">{{ __('pos.proof_bill_banner') }}</div>
    <div class="separator"></div>
    <div class="text-center bold" style="font-size:18px; letter-spacing:1px;">
        {{ __('pos.proof_bill_no') }} {{ $order->order_number }}
    </div>
    @php
        // Task 1386: the customer copy carried the same "T-" bug the kitchen slip
        // shed in Task 1378 — a shop that names its tables "Table No 01" handed
        // out a slip reading "TABLE NO: T-Table No 01". The name now prints
        // exactly as the shop stored it; the localized TABLE NO: label is added
        // ONLY when the name doesn't already read as a table (so a bare "01"
        // still reads as one). Same self-label detection + font stepping as
        // kitchen-ticket.blade.php.
        $proofTableName = trim((string) ($order->table->table_number ?? ''));
        $proofTableText = '';
        if ($proofTableName !== '') {
            $proofTableLabel = trim((string) __('pos.proof_table_no'));
            // Detection ignores the label's trailing punctuation ("TABLE NO:")
            // and also accepts the shorter KOT wording, so an Urdu-named table
            // is recognised on both slips.
            $proofLabelStem = trim((string) preg_replace('/[\s:\-\.،؛]+$/u', '', $proofTableLabel));
            $proofKotLabel = trim((string) __('pos.kot_table_label'));
            $proofTableSelfLabelled = preg_match('/^(table|tbl)/iu', $proofTableName) === 1
                // "T1" / "T-1" / "T 1" shorthand already reads as a table.
                || preg_match('/^t[\s\-\._]?\d/i', $proofTableName) === 1
                || ($proofLabelStem !== '' && mb_stripos($proofTableName, $proofLabelStem) === 0)
                || ($proofKotLabel !== '' && mb_stripos($proofTableName, $proofKotLabel) === 0);
            $proofTableText = $proofTableSelfLabelled ? $proofTableName : trim($proofTableLabel . ' ' . $proofTableName);
        }
        // Printable width is 72mm − 4mm padding each side ≈ 64mm; these steps
        // keep the longest possible line — label + the 20-char column — on ONE
        // line instead of wrapping mid-name. Same ladder as the kitchen slip,
        // except the widest bucket stays at 12px (measured: the widest line this
        // bill can print, "TABLE NO:" + 20 chars, still clears the 64mm at 12px):
        // the KOT's 10px is a heavier 900-weight line, and on the customer copy
        // it printed smaller than the body text.
        $proofTableLen = mb_strlen($proofTableText);
        $proofTableFont = $proofTableLen <= 12 ? 20 : ($proofTableLen <= 16 ? 18 : ($proofTableLen <= 20 ? 15 : ($proofTableLen <= 26 ? 13 : 12)));
    @endphp
    @if($proofTableText !== '')
    <div class="text-center bold proof-table-line" style="font-size:{{ $proofTableFont }}px; margin-top:2px;">{{ $proofTableText }}</div>
    @else
    <div class="text-center bold" style="font-size:20px; margin-top:2px;">
        {{ \Illuminate\Support\Facades\Lang::has('pos.ot_' . $order->order_type) ? __('pos.ot_' . $order->order_type) : strtoupper(str_replace('_',' ',$order->order_type)) }}
    </div>
    @endif
    <div class="flex text-sm mt-1">
        <span>{{ $order->created_at->format('d-M-Y') }}</span>
        <span>{{ $order->created_at->format('g:i A') }}</span>
    </div>
    <table class="items-table">
        <tr class="head"><td>{{ __('pos.receipt_item') }}</td><td class="qty" style="text-align:center;">{{ __('pos.receipt_qty') }}</td><td class="amt">{{ __('pos.receipt_price') }}</td><td class="amt amt-last">{{ __('pos.proof_amount') }}</td></tr>
        @foreach($order->items as $item)
        <tr class="item-row">
            <td>{{ $item->item_name }}</td>
            <td class="qty" style="text-align:center;">{{ rtrim(rtrim(number_format((float)$item->quantity, 2), '0'), '.') }}</td>
            <td class="amt">{{ number_format((float)$item->unit_price, 0) }}</td>
            <td class="amt amt-last">{{ number_format((float)($item->subtotal ?? ((float)$item->quantity * (float)$item->unit_price)), 0) }}</td>
        </tr>
        @endforeach
    </table>
    <table class="totals" style="width:100%">
        <tr><td>{{ __('pos.receipt_total') }}</td><td style="text-align:right">Rs {{ number_format((float)$order->subtotal, 0) }}</td></tr>
        @if((float)$order->discount_amount > 0)
        <tr><td>{{ __('pos.receipt_discount') }}</td><td style="text-align:right">- Rs {{ number_format((float)$order->discount_amount, 0) }}</td></tr>
        @endif
        @if((float)$order->tax_amount > 0)
        <tr><td>{{ __('pos.receipt_tax') }}</td><td style="text-align:right">Rs {{ number_format((float)$order->tax_amount, 0) }}</td></tr>
        @endif
        <tr class="grand"><td style="text-transform:uppercase;">{{ __('pos.receipt_grand_total') }}</td><td style="text-align:right">Rs {{ number_format((float)$order->total_amount, 0) }}</td></tr>
    </table>
    <div class="separator"></div>
    <div class="flex text-sm">
        @if($order->creator)<span>{{ __('pos.proof_user') }} <span class="bold">{{ \Illuminate\Support\Str::of($order->creator->name)->before(' ') }}</span></span>@endif
        <span>{{ \Illuminate\Support\Facades\Lang::has('pos.ot_' . $order->order_type) ? __('pos.ot_' . $order->order_type) : strtoupper(str_replace('_',' ',$order->order_type)) }}</span>
    </div>
    {{-- Owner batch (26 Aug 2026): when the customer says the money is coming
         ONLINE, the cash-style NOT PAID line misleads the counter into waiting
         for notes. Same slip, honest wording — the bill still isn't final. --}}
    @if($order->online_payment_awaited_at ?? null)
    <div class="proof-line">{{ __('pos.proof_online_awaited') }}</div>
    <div class="text-center text-sm bold">{{ __('pos.proof_online_hint') }}</div>
    @else
    <div class="proof-line">{{ __('pos.proof_not_paid') }}</div>
    @endif
    <div class="text-center text-sm bold">{{ __('pos.proof_thank_you') }}</div>

    <script>
        // Same auto-print contract as the KOT ticket: iframe → postMessage the
        // parent (strict print ordering); popup → print then self-close.
        var hasPrinted = false;
        window.onload = function() {
            var urlParams = new URLSearchParams(window.location.search);
            var isInIframe = window.parent && window.parent !== window;
            var frameSignal = urlParams.get('_signal');
            if (urlParams.get('auto_print') === '1' && !hasPrinted) {
                hasPrinted = true;
                if (isInIframe && frameSignal) {
                    var signaled = false;
                    var signalParent = function() {
                        if (signaled) return;
                        signaled = true;
                        try { window.parent.postMessage({ type: 'pos_print_done', signal: frameSignal }, '*'); } catch (e) {}
                    };
                    window.addEventListener('afterprint', signalParent, { once: true });
                    setTimeout(signalParent, 20000);
                } else {
                    window.addEventListener('afterprint', function() {
                        setTimeout(function() { window.close(); }, 300);
                    }, { once: true });
                }
                setTimeout(function() {
                    // Task 1287: Urdu proof bills render in Jameel Noori Nastaleeq
                    // (~5.5 MB, cold cache possible) — bounded wait for the face
                    // before printing; ALWAYS eventually fires (a Naskh fallback
                    // slip beats a lost slip). No-op outside Urdu mode.
                    var tnFired = false;
                    var tnGo = function() { if (!tnFired) { tnFired = true; window.print(); } };
                    @if($urduScript)
                    try {
                        if (document.fonts && document.fonts.load) {
                            document.fonts.load("16px 'Jameel Noori Nastaleeq'", "\u0627\u0631\u062F\u0648").then(tnGo, tnGo);
                            setTimeout(tnGo, 8000);
                            return;
                        }
                    } catch (e) {}
                    @endif
                    tnGo();
                }, 200);
            }
        };
    </script>
</body>
</html>
