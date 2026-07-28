<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Ticket - {{ $order->order_number }}</title>
    @php
        // KOT PRINT STYLE (customer feedback 27 Jul 2026, Pizza Master video):
        // paper-saving toggles + print position, read from the COMPANY row so
        // BOTH render paths (kitchen-ticket route + Agent print-job content)
        // honor them. Null-coalesced defaults = prod schema-drift safe.
        $kotCompact      = (bool) ($company->kot_compact ?? false);
        $kotShowCustomer = (bool) ($company->kot_show_customer ?? true);
        $kotShowOrderby  = (bool) ($company->kot_show_orderby ?? true);
        $kotShowBarcode  = (bool) ($company->kot_show_barcode ?? true);
        $kotShowFooter   = (bool) ($company->kot_show_footer ?? true);
        $kotAlignCenter  = (bool) ($company->kot_align_center ?? false);
        $kotMarginMm     = max(0, min(30, (int) ($company->kot_left_margin_mm ?? 0)));
    @endphp
    <style>
        @page { size: 80mm auto; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 13px;
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 3mm;
            background: #fff;
            color: #000;
            line-height: 1.5;
        }
        .separator { border-top: 2px dashed #000; margin: 6px 0; }
        .separator-light { border-top: 1px dashed #000; margin: 4px 0; }
        .separator-station { border-top: 3px solid #000; margin: 8px 0 4px; }
        .bold { font-weight: bold; }
        .text-center { text-align: center; }
        .text-lg { font-size: 16px; }
        .text-xl { font-size: 20px; }
        .text-sm { font-size: 11px; }
        .text-xs { font-size: 9px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .mb-1 { margin-bottom: 4px; }
        .flex { display: flex; justify-content: space-between; align-items: center; }
        .items-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .items-table td { padding: 5px 2px; vertical-align: top; font-size: 14px; color: #000; }
        /* ZFC feedback Jul 2026: match the familiar "Item | Qty" slip layout —
           name LEFT, qty RIGHT as a plain number under a ruled header row
           (the old "x2" prefix on the left read as part of the item name). */
        .items-table .qty { width: 15%; font-weight: bold; font-size: 17px; text-align: right; padding-right: 4px; color: #000; }
        .items-table .name { width: 85%; color: #000; font-weight: 600; }
        .items-table tr.items-head { border-top: 2px solid #000; border-bottom: 2px solid #000; }
        .items-table tr.items-head td { font-weight: bold; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; padding: 3px 2px; }
        .items-table tr.items-head .qty { font-size: 12px; }
        /* Owner (Jul 2026): kitchen complained notes print too light/small — thermal
           printers render italic + small text thin. Big, upright, ultra-bold + a text
           stroke so cheap printers lay down more ink. */
        .items-table .note {
            font-size: 15px; font-style: normal; color: #000; padding-left: 10px;
            font-weight: 900; -webkit-text-stroke: 0.5px #000; letter-spacing: 0.5px;
        }
        .items-table tr { border-bottom: 1px dashed #000; }
        .items-table tr:last-child { border-bottom: none; }
        .order-type-badge {
            display: inline-block; padding: 3px 10px; border: 2px solid #000;
            font-weight: bold; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;
            color: #000; background: #fff;
        }
        .priority-badge {
            display: inline-block; padding: 3px 12px; border: 3px solid #000;
            font-weight: bold; font-size: 16px; text-transform: uppercase; letter-spacing: 2px;
            background: #000; color: #fff;
        }
        /* ZFC feedback Jul 2026: reversed (white-on-black) blocks print blurry on
           cheap thermal printers — station headers now solid black text on white. */
        .station-header {
            font-size: 15px; font-weight: 900; text-transform: uppercase;
            padding: 5px 8px; margin: 0 0 4px 0; border: 2px solid #000;
            text-align: center; letter-spacing: 2px; background: #fff; color: #000;
            -webkit-text-stroke: 0.5px #000;
        }
        .station-section { margin-bottom: 8px; }
        .station-item-count { font-size: 11px; text-align: center; color: #000; margin-bottom: 4px; font-weight: bold; }
        .kitchen-notes {
            border: 3px solid #000; padding: 6px 8px; margin-top: 6px;
            font-size: 16px; font-weight: 900; background: #fff; color: #000;
            -webkit-text-stroke: 0.5px #000; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .print-btn {
            display: block; width: 100%; padding: 12px; margin-top: 10px;
            background: #7c3aed; color: #fff; border: none; border-radius: 8px;
            font-size: 14px; font-weight: bold; cursor: pointer;
        }
        .print-btn:hover { background: #6d28d9; }
        .print-btn-row { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
        .print-btn-row button { flex: 1; min-width: 80px; padding: 10px; border: none; border-radius: 8px; font-size: 12px; font-weight: bold; cursor: pointer; }
        .btn-reprint { background: #f59e0b; color: #fff; }
        .btn-station { background: #3b82f6; color: #fff; font-size: 11px; }
        .btn-station:hover { background: #2563eb; }
        .kot-barcode-box { text-align: center; margin: 8px 0 4px; }
        .kot-barcode-box svg { max-width: 95%; height: 50px; }
        .kot-barcode-hint { font-size: 9px; color: #000; font-weight: bold; letter-spacing: 1px; margin-top: 2px; }
        @if($kotCompact)
        /* COMPACT KOT (paper-saving, Jul 2026) — screen-side shrink rules. These
           sit AFTER the base rules (same specificity, later wins) and BEFORE the
           v6 print fix. Print-side padding override lives after the v6 block. */
        body { font-size: 12px; line-height: 1.3; padding: 2mm 3mm; }
        .separator { margin: 3px 0; }
        .separator-light { margin: 2px 0; }
        .separator-station { margin: 4px 0 2px; }
        .text-xl { font-size: 16px; }
        .text-lg { font-size: 14px; }
        .mt-1 { margin-top: 2px; }
        .mt-2 { margin-top: 4px; }
        .items-table { margin: 2px 0; }
        .items-table td { padding: 3px 2px; font-size: 13px; }
        .items-table .qty { font-size: 15px; }
        .items-table .note { font-size: 13px; }
        .order-type-badge { padding: 1px 6px; font-size: 12px; }
        .station-header { font-size: 13px; padding: 3px 6px; letter-spacing: 1px; }
        .station-section { margin-bottom: 4px; }
        .station-item-count { margin-bottom: 2px; }
        .kitchen-notes { padding: 4px 6px; margin-top: 4px; font-size: 14px; }
        .kot-barcode-box { margin: 4px 0 2px; }
        .kot-barcode-box svg { height: 36px; }
        @endif
        /* PRINTABLE-WIDTH FIX v6 (ZFC Pizza Point Jul 2026) — this block MUST stay
           LAST among the STATIC rules (only the company-driven opt-in overrides
           below it may follow). It used to sit at the TOP, before the base
           `body { width:80mm; margin:0 auto; }` rule — equal specificity means the
           LATER rule wins, so the base rule silently overrode this fix during print:
           on A4-default Windows queues the 80mm body auto-centered ~65mm from the
           left and the 72mm print head produced a blank slip with only the first
           1-2 letters of each line at the right edge (receipts were fine — their
           print block already came after the base styles).
           v5 history: margin auto → 0 so misconfigured A4 queues stay readable;
           72mm cap = safe printable width of 80mm paper; padding matches receipts
           (4mm top clears low-starting heads, 3mm sides clear the dead zone). */
        @media print {
            /* `html body` = higher specificity than the base `body` rule, so this
               fix survives even if someone later re-orders the stylesheet. */
            html body { width: auto; max-width: 72mm; margin: 0; padding: 4mm 3mm 1mm; }
            .no-print { display: none !important; }
            .station-section { page-break-after: auto; }
        }
        /* COMPANY KOT PRINT OPTIONS (Jul 2026) — OPT-IN per-company overrides of
           the v6 fix. Default (all OFF) keeps margin:0 + 4mm top padding exactly
           as v6 shipped. Center/margin are deliberate shop choices: center on a
           misconfigured A4 Windows queue can re-create the v6 blank-slip failure,
           which is why it is opt-in and warned about on the settings page. */
        @if($kotCompact)
        @media print {
            html body { padding: 2mm 3mm 1mm; }
        }
        @endif
        @if($kotAlignCenter)
        @media print {
            html body { margin-left: auto; margin-right: auto; }
        }
        @elseif($kotMarginMm > 0)
        @media print {
            html body { margin-left: {{ $kotMarginMm }}mm; }
        }
        @endif
    </style>
    @if($kotShowBarcode)
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    @endif
</head>
<body>
    <div class="text-center">
        @if(($order->kot_print_count ?? 0) > 1)
            {{-- Phase 5 — re-send marker so kitchen knows items changed.
                 Jul 2026 (Pizza Master feedback): the old white-on-black
                 "UPDATED" badge printed as an EMPTY black box on thermal
                 printers and wasted 2 lines — merged into ONE bold line. --}}
            <p class="text-sm bold" style="color:#000; font-weight:900;">*** REPRINT #{{ $order->kot_print_count }} &mdash; IGNORE PRIOR TICKET ***</p>
        @endif
        @if($order->priority ?? false)
            <p class="priority-badge mt-1">!!! RUSH !!!</p>
        @endif
        <p class="text-xl bold mt-1">*** {{ strtoupper($stationLabel ?? 'KITCHEN') }} ***</p>
        <p class="text-lg bold mt-1">{{ $order->order_number }}</p>
        {{-- Item #6: stable per-print-batch number — delta tickets get their own KOT #
             so the kitchen can reference "KOT #2 of table 5" (stamped, not counted). --}}
        @if(!empty($kotBatchNo))
            <p class="text-sm bold">KOT #{{ $kotBatchNo }}</p>
        @endif
    </div>

    <div class="separator"></div>

    <div class="flex">
        <span class="bold">{{ $order->created_at->format('M d, Y') }}</span>
        <span class="bold">{{ $order->created_at->format('h:i A') }}</span>
    </div>

    <div class="flex mt-1">
        <span class="order-type-badge">{{ str_replace('_', ' ', $order->order_type) }}</span>
        @if($order->table)
            <span class="bold text-lg">T-{{ $order->table->table_number }}</span>
        @endif
    </div>

    @if($kotShowCustomer && $order->customer_name)
    <div class="mt-1">
        <span class="bold text-sm">Customer: {{ $order->customer_name }}</span>
    </div>
    @endif

    <div class="separator"></div>

    @php
        // "ADDED ITEMS" banner (ZFC feedback Jul 2026): delta=1 is sent on EVERY
        // universal-screen KOT — including the very FIRST send — so keying the
        // banner on $delta alone stamped "ADDED ITEMS" on brand-new orders and
        // confused the kitchen. Real test: does the order have items ALREADY
        // printed in an earlier batch that are NOT on this ticket? Only then is
        // this ticket an addition.
        $isAdditionTicket = !empty($delta) && $order->items
            ->whereNotNull('kot_printed_at')
            ->pluck('id')
            ->diff(($ticketItems ?? collect())->pluck('id'))
            ->isNotEmpty();
        // KOT Full Mode (ZFC feedback, Jul 2026): full-order ticket that carries
        // BOTH old and new rows — banner + per-row NEW badges only when the order
        // actually has prior printed rows (first-ever ticket stays clean).
        $newIds = collect($newItemIds ?? []);
        $isFullUpdate = $newIds->isNotEmpty()
            && ($ticketItems ?? collect())->pluck('id')->diff($newIds)->isNotEmpty();
    @endphp
    {{-- Jul 2026 (Pizza Master feedback): boxed banners ate ~4 lines each —
         slimmed to single bold centered lines so a reprint/delta ticket is
         nearly as short as a first-print KOT. --}}
    @if($isAdditionTicket)
    <div class="mt-1 text-center">
        <span class="bold text-lg">++ ADDED ITEMS{{ !empty($kotBatchNo) ? ' — KOT #'.$kotBatchNo : '' }} ++</span>
    </div>
    @elseif($isFullUpdate)
    <div class="mt-1 text-center">
        <span class="bold text-lg">++ UPDATED ORDER{{ !empty($kotBatchNo) ? ' — KOT #'.$kotBatchNo : '' }} ++ <span class="text-sm">(&raquo; NEW)</span></span>
    </div>
    @endif

    @php
        // Counter/Station routing (Jul 2026): grouping is resolved in the
        // CONTROLLER (PosStation::prepareTicket — bulk lookup, no per-item
        // queries). Stations configured => sections are STATION names;
        // zero stations => ONE flat 'ALL' group, NO category sections
        // (ZFC feedback 21 Jul 2026). $stationLabel set means this ticket
        // is already server-filtered to ONE station.
        $grouped = $grouped ?? collect();
        $stationNames = $grouped->keys()->toArray();
    @endphp

    @if(($ticketItems ?? collect())->isEmpty())
    <div class="mt-1" style="border: 2px dashed #000; padding: 6px; text-align: center;">
        <span class="bold text-sm">NO ITEMS FOR THIS COUNTER</span>
    </div>
    @endif

    @foreach($grouped as $station => $items)
        <div class="station-section" data-station="{{ $station }}">
            @if($grouped->count() > 1)
                <div class="station-header">{{ $station }}</div>
                <div class="station-item-count">{{ $items->count() }} item(s)</div>
            @endif
            <table class="items-table">
                <tr class="items-head">
                    <td class="name">Item</td>
                    <td class="qty">Qty</td>
                </tr>
                @foreach($items as $item)
                <tr>
                    <td class="name">
                        @if($isFullUpdate && $newIds->contains($item->id))
                            <span style="border: 1.5px solid #000; padding: 0 3px; font-weight: 900; font-size: 12px;">NEW</span>
                        @endif
                        <span class="bold">{{ $item->item_name }}</span>
                        @if($item->special_notes)
                            <br><span class="note">&raquo; NOTE: {{ $item->special_notes }}</span>
                        @endif
                    </td>
                    <td class="qty">{{ number_format($item->quantity, $item->quantity == intval($item->quantity) ? 0 : 2) }}</td>
                </tr>
                @endforeach
            </table>
            @if(!$loop->last && $grouped->count() > 1)
                <div class="separator-light"></div>
            @endif
        </div>
    @endforeach

    @if($order->kitchen_notes)
    <div class="separator"></div>
    <div class="kitchen-notes">
        NOTES: {{ $order->kitchen_notes }}
    </div>
    @endif

    {{-- KOT Print Style (Jul 2026): footer blocks individually toggleable per
         company — paper saving. Each hidden block also drops its separator. --}}
    @if($kotShowOrderby)
    <div class="separator"></div>

    <div class="text-center text-sm">
        {{-- ZFC feedback Jul 2026: creator = the CASHIER who took the order, not the
             cook — "Prepared by" read backwards on the pass. Count from the TICKET's
             rows (delta/station prints show fewer than the whole order) and show the
             total QUANTITY too — "1 item(s)" with x2 on the line made the kitchen
             second-guess how many to fire. --}}
        @php
            $kotRows = ($ticketItems ?? $order->items);
            $kotQty  = $kotRows->sum('quantity');
        @endphp
        <p>Order by: {{ $order->creator->name ?? 'Staff' }}</p>
        <p class="mt-1">{{ $kotRows->count() }} item(s) &mdash; Total Qty: {{ $kotQty == intval($kotQty) ? intval($kotQty) : number_format($kotQty, 2) }}</p>
    </div>
    @endif

    @if($kotShowBarcode)
    <div class="separator"></div>
    <div class="kot-barcode-box">
        <svg id="kotBarcode"></svg>
        <div class="kot-barcode-hint">SCAN BARCODE TO CLEAR</div>
    </div>
    @endif
    @if($kotShowFooter)
    @if(!$kotShowOrderby && !$kotShowBarcode)
    <div class="separator"></div>
    @endif
    <p class="text-center bold text-sm">{{ $company->name ?? 'Restaurant' }}</p>
    @endif

    <div class="no-print print-btn-row">
        <button class="print-btn" onclick="printAll()">Print Full KOT</button>
        <button class="btn-reprint" onclick="printAll()">Reprint</button>
    </div>

    @if($grouped->count() > 1)
    <div class="no-print" style="margin-top: 8px;">
        <p style="font-size: 11px; font-weight: bold; color: #1f2937; text-align: center; margin-bottom: 6px;">Print by Station:</p>
        <div class="print-btn-row">
            @foreach($stationNames as $sName)
            <button class="btn-station" onclick="printStation('{{ $sName }}')">{{ $sName }}</button>
            @endforeach
        </div>
    </div>
    @endif

    <script>
        let hasPrinted = false;

        function printAll() {
            document.querySelectorAll('.station-section').forEach(s => s.style.display = '');
            window.print();
        }

        function printStation(station) {
            document.querySelectorAll('.station-section').forEach(s => {
                s.style.display = s.dataset.station === station ? '' : 'none';
            });
            window.print();
            setTimeout(() => {
                document.querySelectorAll('.station-section').forEach(s => s.style.display = '');
            }, 500);
        }

        function renderBarcode() {
            // KOT Print Style (Jul 2026): barcode can be hidden per company —
            // skip cleanly when the svg is not on the page.
            if (!document.getElementById('kotBarcode')) return;
            try {
                if (typeof JsBarcode === 'function') {
                    JsBarcode('#kotBarcode', 'KOT-{{ $order->id }}', {
                        format: 'CODE128',
                        width: 2,
                        height: {{ $kotCompact ? 36 : 50 }},
                        displayValue: true,
                        fontSize: 12,
                        margin: 0,
                        background: '#ffffff',
                        lineColor: '#000000'
                    });
                }
            } catch (e) { console.warn('Barcode render failed', e); }
            // QR removed per owner (20 Jul 2026) — CODE128 barcode is the ONLY code
            // on the ticket. KDS camera scanner reads 1D CODE128 too (html5-qrcode
            // scans all formats by default), so scan-to-clear keeps working.
        }

        // Counter/Station routing: when the server already filtered this ticket to one
        // station ($stationLabel set), the legacy client-side ?station= name filter must
        // NOT run (its param is an id, not a section label — it would hide everything).
        const serverStationFiltered = {{ isset($stationLabel) && $stationLabel !== null ? 'true' : 'false' }};
        const ticketHasItems = {{ ($ticketItems ?? collect())->count() > 0 ? 'true' : 'false' }};

        window.onload = function() {
            renderBarcode();
            const urlParams = new URLSearchParams(window.location.search);
            const isInIframe = window.parent && window.parent !== window;
            const frameSignal = urlParams.get('_signal'); // sent by parent for postMessage routing
            if (urlParams.get('auto_print') === '1' && !hasPrinted) {
                hasPrinted = true;
                // Race guard: a station-filtered ticket can come up empty (another
                // device printed these rows first). Never fire a blank print —
                // signal the parent (or close the popup) and stop.
                if (!ticketHasItems) {
                    if (isInIframe && frameSignal) {
                        try { window.parent.postMessage({ type: 'pos_print_done', signal: frameSignal }, '*'); } catch (e) {}
                    } else {
                        setTimeout(function() { window.close(); }, 300);
                    }
                    return;
                }
                // When inside the parent's hidden print iframe, attach afterprint INSIDE the iframe
                // (where it's spec-reliable) and signal the parent via postMessage when the print
                // dialog actually closes. Parent uses this signal to enforce strict print ordering.
                if (isInIframe && frameSignal) {
                    let signaled = false;
                    const signalParent = function() {
                        if (signaled) return;
                        signaled = true;
                        try { window.parent.postMessage({ type: 'pos_print_done', signal: frameSignal }, '*'); } catch (e) {}
                    };
                    window.addEventListener('afterprint', signalParent, { once: true });
                    setTimeout(signalParent, 20000);
                } else {
                    // Opened as a POPUP (Send to Kitchen button) — auto-close once the
                    // print dialog is dismissed so the cashier lands straight back on a
                    // fresh sale screen. Iframe case above must NOT close (parent owns it).
                    window.addEventListener('afterprint', function() {
                        setTimeout(function() { window.close(); }, 300);
                    }, { once: true });
                }
                setTimeout(function() { window.print(); }, 200);
            }
            const station = urlParams.get('station');
            if (station && !isInIframe && !serverStationFiltered) {
                setTimeout(() => printStation(station), 600);
            }
        };
    </script>
</body>
</html>
