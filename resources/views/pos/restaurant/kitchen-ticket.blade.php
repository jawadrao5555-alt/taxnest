<!DOCTYPE html>
@php $urduScript = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<html lang="{{ $urduScript ? 'ur' : 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Ticket - {{ $order->order_number }}</title>
    @php
        // KOT PRINT STYLE (customer feedback 27 Jul 2026, Pizza Master video):
        // paper-saving toggles + print position, read from the COMPANY row so
        // BOTH render paths (kitchen-ticket route + Agent print-job content)
        // honor them. Null-coalesced defaults = prod schema-drift safe.
        $kotCompact           = (bool) ($company->kot_compact ?? false);
        $kotShowCustomer      = (bool) ($company->kot_show_customer ?? true);
        $kotShowOrderby       = (bool) ($company->kot_show_orderby ?? true);
        $kotShowBarcode       = (bool) ($company->kot_show_barcode ?? true);
        $kotShowFooter        = (bool) ($company->kot_show_footer ?? true);
        $kotShowKitchenNotes  = (bool) ($company->kot_show_kitchen_notes ?? false);
        // Task 718 (Pizza Master default): kot_align_center is now NULLABLE —
        // NULL = shop never chose explicitly. For PRINT CSS we default to LEFT
        // (v6-safe, margin:0) regardless of the "center" UI default — this is
        // the Task 756 regression fix: NULL was resolving to margin:auto via
        // `?? true`, which on A4-default Windows queues shifts a 72mm body
        // ~69mm right → the 72mm thermal head prints only the blank left margin.
        // The settings UI still pre-selects Center for NULL companies (aligned
        // with PosKotThemes::alignBool) so an untouched save writes explicit
        // true. Only explicit true (owner opt-in, with the A4 warning on the
        // settings page) now emits the margin:auto print rule.
        $kotAlignCenter       = (bool) ($company->kot_align_center ?? false);
        $kotMarginMm          = max(0, min(30, (int) ($company->kot_left_margin_mm ?? 0)));
    @endphp
    <style>
        @page { size: 80mm auto; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            /* Owner (31 Jul 2026): KOT gets the same clean font as the proof
               bill / receipt_80mm — typewriter font retired here too. */
            font-family: Arial, 'Helvetica Neue', Helvetica, 'Segoe UI', sans-serif;
            font-size: 13px;
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 3mm;
            background: #fff;
            color: #000;
            /* Owner (30-31 Jul 2026): "beech mein gap bohat zyada" — tighter rows,
               same as the proof bill. */
            line-height: 1.3;
        }
        .separator { border-top: 2px dashed #000; margin: 4px 0; }
        .separator-light { border-top: 1px dashed #000; margin: 3px 0; }
        .separator-station { border-top: 3px solid #000; margin: 6px 0 3px; }
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
        /* Owner (31 Jul 2026): proof-bill-style density — compact rows; the dashed
           line under every item (below) stays as the row separator. */
        .items-table td { padding: 3px 2px; vertical-align: top; font-size: 14px; color: #000; }
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
        /* ZFC (5 Aug 2026): "NOTE ke aage sab kuch jura hua ultra-bold" — label
           chhota ultra-black rahe, asal note ka TEXT alag andaz mein (bara, bold
           magar bina stroke ke, thora sa gap) taake kitchen asaani se parh sake.
           Jul 2026 ka sabaq qaim: kabhi italic/patla nahi (thermal par ghayab). */
        .items-table .note-label { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
        .items-table .note-text { font-size: 16px; font-weight: 700; -webkit-text-stroke: 0px transparent; letter-spacing: 0.2px; margin-left: 4px; }
        .items-table tr { border-bottom: 1px dashed #000; }
        .items-table tr:last-child { border-bottom: none; }
        .order-type-badge {
            display: inline-block; padding: 3px 10px; border: 2px solid #000;
            font-weight: bold; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;
            color: #000; background: #fff;
        }
        /* Task 1378 (owner photo, 21 Aug 2026): the table name used to sit on the
           SAME header line as the order-type badge + date/time. On an 80mm roll a
           name like "Table No 01" broke mid-name ("DINE IN T-Table" / "No 01") and
           pushed the time's "AM" onto its own line. The name now owns a full line,
           never wraps (nowrap) and its font size steps down with the name length
           (set inline from PHP) so even the longest stored name — the column caps
           at 20 chars — still fits the 72mm printable width in ONE piece.
           The date/time span keeps its own nowrap so "AM/PM" can never drop. */
        .kot-table-line { font-weight: 900; white-space: nowrap; margin-top: 3px; line-height: 1.25; color: #000; }
        .kot-when { white-space: nowrap; }
        /* Aug 2026 (customer photo): the reversed white-on-black RUSH block printed
           as a faint dotted box on thermal printers — same lesson as station headers:
           solid black text on white + heavy border prints crisp everywhere.
           9 Aug 2026 (E-ICEBLUE video, 500 orders/day): the bordered 3-line block
           wasted paper — now ONE plain bold line, no border/padding/stroke. */
        .priority-badge {
            display: inline-block; font-weight: 900; font-size: 14px;
            text-transform: uppercase; letter-spacing: 0.5px;
            background: #fff; color: #000;
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
        /* Task 778: full-mode UPDATE tickets tag genuinely-new rows — solid black
           on white (thermal-safe, same lesson as station headers). */
        .kot-new-tag { font-weight: 900; font-size: 11px; border: 1px solid #000; padding: 0 3px; letter-spacing: 1px; color: #000; background: #fff; }
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
        .items-table .note-label { font-size: 11px; }
        .items-table .note-text { font-size: 14px; }
        .order-type-badge { padding: 1px 6px; font-size: 12px; }
        .kot-table-line { margin-top: 2px; }
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
            /* Task 764 (ZFC, 15 Aug 2026): explicit centering used margin:auto,
               which centers within the QUEUE's page — on an A4-default Windows
               queue that shifted the 72mm body ~65mm right and the thermal head
               printed a BLANK slip (same physics as the v6 NULL fix). v6-safe
               centering: center within the 80mm PAPER instead — body is capped
               at 72mm, so a fixed 4mm left margin = visually centered on the
               roll regardless of what page size the queue believes it has. */
            html body { margin-left: 4mm; margin-right: 0; }
        }
        @elseif($kotMarginMm > 0)
        @media print {
            html body { margin-left: {{ $kotMarginMm }}mm; }
        }
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
    @if($kotShowBarcode)
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    @endif
</head>
<body>
    @php
        // Task 794: VOID / CANCEL mode — this slip tells the kitchen to STOP
        // making dishes removed from a running order after their KOT fired.
        // Rendered by the void-ticket route (iframe) and the agent's kot_void
        // print job. Normal-KOT sections are suppressed below in this mode.
        $void = $void ?? false;
        $voidItems = $voidItems ?? collect();
    @endphp
    <div class="text-center">
        @if(!$void)
        @if(($order->kot_print_count ?? 0) > 1 && empty($delta))
            {{-- Phase 5 — re-send marker so kitchen knows items changed.
                 Jul 2026 (Pizza Master feedback): the old white-on-black
                 "UPDATED" badge printed as an EMPTY black box on thermal
                 printers and wasted 2 lines — merged into ONE bold line.
                 Jul 28 2026 (ZFC feedback): delta tickets carry ONLY new items,
                 so they must print CLEAN — no reprint/updated wording at all.
                 Banner kept for manual full re-sends (non-delta) only. --}}
            <p class="text-sm bold" style="color:#000; font-weight:900;">{{ __('pos.kot_reprint_banner', ['n' => $order->kot_print_count]) }}</p>
        @endif
        @php
            // Task 778: full-mode UPDATE ticket = whole cart re-rendered because
            // of new rows ($newItemIds only populated on the full-mode paths) —
            // mark it clearly UPDATED so the kitchen doesn't re-fire everything.
            $kotFullUpdate = !empty($delta) && ($newItemIds ?? collect())->isNotEmpty();
        @endphp
        @if($kotFullUpdate)
            <p class="text-sm bold" style="color:#000; font-weight:900;">{{ __('pos.kot_updated_banner') }}</p>
        @endif
        @endif {{-- !$void: reprint/updated banners never print on a void slip --}}
        {{-- 10 Aug 2026 (Pizza Master photo): URGENT top se hata kar neeche footer
             lines ke saath chhota sa — paper aur kam lage; render site is below,
             beside the order-by line. --}}
        <p class="text-xl bold mt-1">*** {{ strtoupper($stationLabel ?? __('pos.kot_kitchen')) }} ***</p>
        {{-- Order Matching (Aug 2026): same number on KOT + customer receipt so
             counter staff can pair a ready order with the customer's bill.
             'token' = daily token; 'code' = short unique code (last ORD segment).
             Shim (transaction) KOTs skip the code box — their big header is
             already the bill number, which the receipt carries anyway.
             SINGLE SERIAL (E-ICEBLUE video, 9 Aug 2026): when the token/code box
             prints, the long ORD- number is the SAME identity (code = its last
             segment) — printing both read as a confusing "double serial" and
             wasted a line. Box now REPLACES the ORD- line; KOT # rides the same
             line instead of its own. --}}
        @php
            $omStyle = $company->order_match_style ?? 'off';
            $omToken = ($omStyle === 'token' && !empty($order->token_no)) ? (int) $order->token_no : null;
        @endphp
        {{-- 10 Aug 2026 (Pizza Master follow-up video): "KOT #1" carries no info on the
             FIRST ticket — print the batch number only from #2 onward, where the kitchen
             genuinely needs it to spot a delta/repeat ticket for the same order. --}}
        @php
            $kotBatchShown = !empty($kotBatchNo) && (int) $kotBatchNo > 1;
            // Task 778: delta slips (added-qty-only tickets) get a small ADD-ON
            // marker riding the KOT #N line — same order number + token as the
            // first slip, so the kitchen reads it as an update, not a new order.
            // Full-mode update tickets show the UPDATED banner instead (above).
            $kotAddon = !empty($delta) && $kotBatchShown && ($newItemIds ?? collect())->isEmpty();
        @endphp
        {{-- 10 Aug 2026 (Pizza Master, via owner): CODE style par chhota boxed code
             "pyara nahi lagta" — poora ORD- number hi wapis (bold, complete). Matching
             phir bhi chalti hai: receipt ka code = isi number ka aakhri hissa. Sirf
             TOKEN style ka bada box rehta hai (woh alag number hai — bulane ke liye). --}}
        @php
            // Task 777 (ZFC, 16 Aug 2026): transaction-shim KOTs (order-less
            // bills) print the BILL token big when the stream's number style
            // is 'token' — matches the receipt's calling number. Passed only
            // by renderTransactionKot; order-based KOTs stay unchanged.
            $shimBillToken = $shimBillToken ?? null;
        @endphp
        @php
            // Task 993 (owner voice note, 16 Aug 2026): a takeaway/delivery
            // punched-and-paid on the sale screen already HAS its finalized
            // bill at KOT render time (billing pass-through links
            // pos_transaction_id before the post-payment auto-print chain
            // fires). When NO order-match identifier applies (style 'off', or
            // 'token' style without a token), the KOT header prints the SAME
            // number the receipt shows — bill token big (serial as small Ref)
            // on token-style streams, else the invoice number — REPLACING the
            // raw ORD- line so counter staff can pair slips at a glance.
            // 'code' style keeps the full ORD- line (the receipt's code IS its
            // last segment — that pairing already works; never two competing
            // numbers). Pre-payment KOTs (dine-in hold / waiter send) have no
            // linked bill and stay exactly as today; delta/reprint renders of
            // a paid order resolve the same bill → same number on every slip.
            // Stream/style resolution mirrors receipt_80mm/58mm (isLocalBill/
            // isExemptStream + local/pra_number_style). Guarded per PROD
            // drift convention — any lookup failure = old ORD- behavior.
            $kotBillToken = null;
            $kotBillNum = null;
            // Daily style (ZFC): large L00x only — no TOKEN label / Bill Serial ref.
            // local_number_style=daily is local-stream only; shim KOTs are local bills.
            $kotIsDailyStyle = false;
            $shimIsDailyStyle = (($company->local_number_style ?? 'serial') === 'daily');
            try {
                if ($omToken === null && $shimBillToken === null && $omStyle !== 'code'
                    && $order->exists && !empty($order->pos_transaction_id)) {
                    $kotBillTxn = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
                        ->where('company_id', $order->company_id)
                        ->find($order->pos_transaction_id);
                    if ($kotBillTxn && $kotBillTxn->invoice_number) {
                        $kotBillNum = $kotBillTxn->invoice_number;
                        $kotBillToken = \App\Support\PosBillNumberStyle::bigNumber($company, $kotBillTxn);
                        $kotIsDailyStyle = \App\Support\PosBillNumberStyle::styleFor($company, $kotBillTxn) === 'daily';
                    }
                }
            } catch (\Throwable $e) { $kotBillToken = null; $kotBillNum = null; $kotIsDailyStyle = false; }
        @endphp
        @if($omToken)
            <p style="margin-top:3px;"><span style="display:inline-block; border:2px solid #000; padding:2px 10px; font-size:20px; font-weight:900; color:#000;">{{ __('pos.order_match_token_label') }} {{ $omToken }}</span>@if($kotBatchShown) <span class="text-sm bold">KOT #{{ $kotBatchNo }}@if($kotAddon) &middot; {{ __('pos.kot_addon_marker') }}@endif</span>@endif</p>
        @elseif($shimBillToken !== null)
            <p style="margin-top:3px;"><span style="display:inline-block; border:2px solid #000; padding:2px 10px; font-size:20px; font-weight:900; color:#000;">@if($shimIsDailyStyle){{ $shimBillToken }}@else{{ __('pos.order_match_token_label') }} {{ $shimBillToken }}@endif</span></p>
            @unless($shimIsDailyStyle)
            <p class="text-sm bold">{{ __('pos.bill_ref_label') }}: {{ $order->order_number }}</p>
            @endunless
        @elseif($kotBillToken !== null)
            {{-- Task 993: paid order, token-style stream — bill token big with the
                 serial as small Ref, mirroring the receipt + shim-KOT design.
                 Daily style omits the TOKEN label and Bill Serial ref line.
                 KOT #N rides the token line (delta/reprint sequencing intact). --}}
            <p style="margin-top:3px;"><span style="display:inline-block; border:2px solid #000; padding:2px 10px; font-size:20px; font-weight:900; color:#000;">@if($kotIsDailyStyle){{ $kotBillToken }}@else{{ __('pos.order_match_token_label') }} {{ $kotBillToken }}@endif</span>@if($kotBatchShown) <span class="text-sm bold">KOT #{{ $kotBatchNo }}@if($kotAddon) &middot; {{ __('pos.kot_addon_marker') }}@endif</span>@endif</p>
            @unless($kotIsDailyStyle)
            <p class="text-sm bold">{{ __('pos.bill_ref_label') }}: {{ $kotBillNum }}</p>
            @endunless
        @elseif($kotBillNum)
            {{-- Task 993: paid order, serial-style stream — the bill's invoice
                 number replaces the ORD- line (single identifier, owner rule). --}}
            <p class="text-lg bold mt-1">{{ $kotBillNum }}@if($kotBatchShown) <span class="text-sm bold">&mdash; KOT #{{ $kotBatchNo }}@if($kotAddon) &middot; {{ __('pos.kot_addon_marker') }}@endif</span>@endif</p>
        @else
            <p class="text-lg bold mt-1">{{ $order->order_number }}@if($kotBatchShown) <span class="text-sm bold">&mdash; KOT #{{ $kotBatchNo }}@if($kotAddon) &middot; {{ __('pos.kot_addon_marker') }}@endif</span>@endif</p>
        @endif
    </div>

    <div class="separator"></div>

    @if($void)
    {{-- Task 794: VOID / CANCEL slip. Marker style: slim bold lines only —
         NO reversed/white-on-black blocks (thermal printers render those as
         empty boxes; same lesson as station headers / RUSH badge above). --}}
    <div class="text-center" style="margin: 4px 0;">
        <p class="bold" style="font-size: 16px; font-weight: 900; letter-spacing: 2px; border-top: 2px solid #000; border-bottom: 2px solid #000; padding: 3px 0; color: #000; background: #fff;">** {{ __('pos.kot_void_header') }} **</p>
        <p class="text-sm bold" style="color:#000; font-weight:900;">{{ __('pos.kot_void_subline') }}</p>
    </div>
    @endif

    @php
        // Task 1378 (owner photo, 21 Aug 2026): table name gets its OWN header
        // line, printed WHOLE. Two fixes in one:
        //   • It used to ride beside the order-type badge, so on an 80mm roll a
        //     name like "Table No 01" wrapped mid-name and the time lost its
        //     "AM" to the next line. Badge + date/time now own line 1, the
        //     table name owns line 2 (nowrap + a length-based font step).
        //   • The old hard-coded "T-" prefix produced "T-Table No 01" on shops
        //     that already name their tables "Table …". The name now prints
        //     exactly as the shop stored it; a clear TABLE label is added ONLY
        //     when the name doesn't already carry one (so "01" still reads as
        //     a table). Localized label = never a Latin word on Urdu slips.
        $kotTableName = trim((string) ($order->table->table_number ?? ''));
        $kotTableText = '';
        if ($kotTableName !== '') {
            $kotTableLabel = trim((string) __('pos.kot_table_label'));
            $kotTableSelfLabelled = preg_match('/^(table|tbl)/iu', $kotTableName) === 1
                // "T1" / "T-1" / "T 1" shorthand already reads as a table.
                || preg_match('/^t[\s\-\._]?\d/i', $kotTableName) === 1
                || ($kotTableLabel !== '' && mb_stripos($kotTableName, $kotTableLabel) === 0);
            $kotTableText = $kotTableSelfLabelled ? $kotTableName : trim($kotTableLabel . ' ' . $kotTableName);
        }
        // Printable width is 72mm − 3mm padding each side ≈ 66mm; these steps
        // keep the longest possible label (6-char label + 20-char column) on
        // ONE line. Compact tickets shrink one notch further.
        $kotTableLen = mb_strlen($kotTableText);
        $kotTableFont = $kotTableLen <= 12 ? 20 : ($kotTableLen <= 16 ? 18 : ($kotTableLen <= 20 ? 15 : ($kotTableLen <= 26 ? 13 : 10)));
        if ($kotCompact) { $kotTableFont = max(10, $kotTableFont - 2); }
    @endphp
    {{-- 10 Aug 2026 (owner): order-type badge + date + time on ONE line — saves a
         full printed line. Task 1378: the table name no longer squeezes in here;
         it prints whole on its own line below (see the block above). --}}
    <div class="flex">
        <span>
            <span class="order-type-badge">{{ \Illuminate\Support\Facades\Lang::has('pos.ot_' . $order->order_type) ? __('pos.ot_' . $order->order_type) : strtoupper(str_replace('_', ' ', $order->order_type)) }}</span>
        </span>
        <span class="bold kot-when">{{ $order->created_at->format('M d') }} {{ $order->created_at->format('h:i A') }}</span>
    </div>
    @if($kotTableText !== '')
    <div class="kot-table-line" style="font-size: {{ $kotTableFont }}px;">{{ $kotTableText }}</div>
    @endif

    {{-- Task 620 (ZFC, Aug 2026): waiter-punched orders must ALWAYS carry the
         waiter's REAL display name on the KOT — the footer "Order by" line is a
         paper-saving toggle (kot_show_orderby) and vanished at shops that turned
         it off, leaving no name at all. This line is independent of that toggle
         and prints only for source='waiter' orders. --}}
    @if(($order->source ?? null) === 'waiter' && ($order->creator->name ?? null))
    <div class="mt-1">
        <span class="bold text-sm">{{ __('pos.kot_waiter') }} {{ $order->creator->name }}</span>
    </div>
    @endif

    @if($kotShowCustomer && $order->customer_name)
    <div class="mt-1">
        <span class="bold text-sm">{{ __('pos.receipt_customer') }}: {{ $order->customer_name }}</span>
    </div>
    @endif

    {{-- Jul 28 2026 (ZFC feedback via owner): "ADDED ITEMS" / "UPDATED ORDER"
         banners REMOVED entirely — an updated order's ticket prints only the
         NEW items, plainly, with no update wording. The per-batch "KOT #N" in
         the header is enough for the kitchen to sequence tickets. --}}

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

    @if($void)
    {{-- Task 794: void items table — removed dish + removed qty, same layout
         language as the normal items table so the kitchen reads it instantly. --}}
    <table class="items-table">
        <tr class="items-head">
            <td class="name">{{ __('pos.receipt_item') }}</td>
            <td class="qty">{{ __('pos.receipt_qty') }}</td>
        </tr>
        @foreach($voidItems as $vi)
        <tr>
            <td class="name">
                <span class="bold">{{ $vi['item_name'] ?? '' }}</span>
                @if(!empty($vi['notes']))
                    <br><span class="note"><span class="note-label">&raquo; {{ __('pos.kot_note') }}</span><span class="note-text">{{ $vi['notes'] }}</span></span>
                @endif
            </td>
            <td class="qty">@php $vq = (float) ($vi['qty'] ?? 0); @endphp{{ number_format($vq, $vq == intval($vq) ? 0 : 2) }}</td>
        </tr>
        @endforeach
    </table>
    @endif

    @if(!$void && ($ticketItems ?? collect())->isEmpty())
    <div class="mt-1" style="border: 2px dashed #000; padding: 6px; text-align: center;">
        <span class="bold text-sm">{{ __('pos.kot_no_items_counter') }}</span>
    </div>
    @endif

    @if(!$void)
    @foreach($grouped as $station => $items)
        <div class="station-section" data-station="{{ $station }}">
            @if($grouped->count() > 1)
                <div class="station-header">{{ $station }}</div>
                <div class="station-item-count">{{ __('pos.kot_items_count', ['count' => $items->count()]) }}</div>
            @endif
            <table class="items-table">
                <tr class="items-head">
                    <td class="name">{{ __('pos.receipt_item') }}</td>
                    <td class="qty">{{ __('pos.receipt_qty') }}</td>
                </tr>
                @foreach($items as $item)
                <tr>
                    <td class="name">
                        <span class="bold">{{ $item->item_name }}</span>@if(($newItemIds ?? collect())->contains($item->id)) <span class="kot-new-tag">{{ __('pos.kot_new_tag') }}</span>@endif
                        @if($item->special_notes)
                            <br><span class="note"><span class="note-label">&raquo; {{ __('pos.kot_note') }}</span><span class="note-text">{{ $item->special_notes }}</span></span>
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
    @endif {{-- !$void: normal items sections --}}

    @if(!$void && $kotShowKitchenNotes && $order->kitchen_notes)
    <div class="separator"></div>
    {{-- Aug 2026 (restaurant feedback): multi-item notes come as separate lines from
         the textarea — print each on its OWN line, numbered, so the kitchen can match
         note 1 to item 1. Single-line notes keep the old compact format. --}}
    @php
        $kotNoteLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $order->kitchen_notes)), fn ($l) => $l !== ''));
    @endphp
    <div class="kitchen-notes">
        @if(count($kotNoteLines) > 1)
            <div>{{ __('pos.kot_notes') }}</div>
            @foreach($kotNoteLines as $kni => $knl)
                <div>{{ $kni + 1 }}. {{ $knl }}</div>
            @endforeach
        @else
            {{ __('pos.kot_notes') }} {{ $kotNoteLines[0] ?? '' }}
        @endif
    </div>
    @endif

    {{-- KOT Print Style (Jul 2026): footer blocks individually toggleable per
         company — paper saving. Each hidden block also drops its separator. --}}
    @if(!$void && $kotShowOrderby)
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
        {{-- 9 Aug 2026 (E-ICEBLUE video): one line instead of two — paper saving.
             10 Aug follow-up #2: URGENT apni alag line ki bajaye ISI line ke side
             par chhota sa — ek aur line bachi. --}}
        <p>{{ __('pos.kot_order_by') }} {{ $order->creator->name ?? __('pos.kot_staff') }} &mdash; {{ __('pos.kot_items_count', ['count' => $kotRows->count()]) }}, {{ __('pos.kot_total_qty') }} {{ $kotQty == intval($kotQty) ? intval($kotQty) : number_format($kotQty, 2) }}@if($order->priority ?? false) <span class="priority-badge">{{ __('pos.kot_rush') }}</span>@endif</p>
    </div>
    @endif
    {{-- URGENT fallback: agar company ne order-by footer line hi band ki hui ho
         (kot_show_orderby OFF) to URGENT phir bhi chhapna zaroori hai — tab hi
         apni chhoti line par aata hai. Warna upar wali line ke side par hai. --}}
    @if(!$void && !$kotShowOrderby && ($order->priority ?? false))
    <div class="text-center"><span class="priority-badge">{{ __('pos.kot_rush') }}</span></div>
    @endif

    @if(!$void && $kotShowBarcode)
    <div class="separator"></div>
    <div class="kot-barcode-box">
        <svg id="kotBarcode"></svg>
        <div class="kot-barcode-hint">{{ __('pos.kot_scan_to_clear') }}</div>
    </div>
    @endif
    @if($kotShowFooter)
    @if($void || (!$kotShowOrderby && !$kotShowBarcode))
    <div class="separator"></div>
    @endif
    <p class="text-center bold text-sm">{{ $company->name ?? 'Restaurant' }}</p>
    @endif

    <div class="no-print print-btn-row">
        <button class="print-btn" onclick="printAll()">{{ __('pos.kot_print_full') }}</button>
        <button class="btn-reprint" onclick="printAll()">{{ __('pos.kot_reprint_btn') }}</button>
    </div>

    @if($grouped->count() > 1)
    <div class="no-print" style="margin-top: 8px;">
        <p style="font-size: 11px; font-weight: bold; color: #1f2937; text-align: center; margin-bottom: 6px;">{{ __('pos.kot_print_by_station') }}</p>
        <div class="print-btn-row">
            @foreach($stationNames as $sName)
            <button class="btn-station" onclick="printStation('{{ $sName }}')">{{ $sName }}</button>
            @endforeach
        </div>
    </div>
    @endif

    <script>
        let hasPrinted = false;

        // Task 1287: in Urdu mode the ticket renders in Jameel Noori Nastaleeq
        // (~5.5 MB — a cold cache is possible on a fresh kitchen device). Wait
        // for the face (bounded) before printing so the job isn't rasterized
        // in the Naskh fallback mid-download. ALWAYS eventually fires — a
        // Naskh ticket beats a lost ticket. No-op outside Urdu mode.
        function tnWaitUrduFont(cb) {
            @if($urduScript)
            try {
                if (document.fonts && document.fonts.load) {
                    var fired = false;
                    var go = function() { if (!fired) { fired = true; cb(); } };
                    document.fonts.load("16px 'Jameel Noori Nastaleeq'", "\u0627\u0631\u062F\u0648").then(go, go);
                    setTimeout(go, 8000);
                    return;
                }
            } catch (e) {}
            @endif
            cb();
        }

        function printAll() {
            document.querySelectorAll('.station-section').forEach(s => s.style.display = '');
            tnWaitUrduFont(function() { window.print(); });
        }

        function printStation(station) {
            document.querySelectorAll('.station-section').forEach(s => {
                s.style.display = s.dataset.station === station ? '' : 'none';
            });
            // Station filter must stay applied until the print has actually been
            // handed to the dialog — restore only runs inside the same callback.
            tnWaitUrduFont(function() {
                window.print();
                setTimeout(() => {
                    document.querySelectorAll('.station-section').forEach(s => s.style.display = '');
                }, 500);
            });
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
        // Task 794: void slips carry voidItems instead of ticketItems — the
        // blank-print race guard must count the right list or auto_print
        // silently refuses every void slip.
        const ticketHasItems = {{ (($ticketItems ?? collect())->count() > 0 || (($void ?? false) && ($voidItems ?? collect())->count() > 0)) ? 'true' : 'false' }};

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
                setTimeout(function() { tnWaitUrduFont(function() { window.print(); }); }, 200);
            }
            const station = urlParams.get('station');
            if (station && !isInIframe && !serverStationFiltered) {
                setTimeout(() => printStation(station), 600);
            }
        };
    </script>
</body>
</html>
