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
    <div class="text-center bold" style="font-size:20px; margin-top:2px;">
        @if($order->table){{ __('pos.proof_table_no') }} T-{{ $order->table->table_number }}@else{{ \Illuminate\Support\Facades\Lang::has('pos.ot_' . $order->order_type) ? __('pos.ot_' . $order->order_type) : strtoupper(str_replace('_',' ',$order->order_type)) }}@endif
    </div>
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
    <div class="proof-line">{{ __('pos.proof_not_paid') }}</div>
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
                setTimeout(function() { window.print(); }, 200);
            }
        };
    </script>
</body>
</html>
