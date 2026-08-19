<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Task 1285: FBR Store branding — this ticket is a STORE SLIP (godown/packing), not a
         kitchen KOT. Labels use the fbr_* lang keys so they follow the active locale: web
         renders get it from SetPosLocale, agent renders set it before rendering (AgentController
         silent-print locale). --}}
    <title>{{ __('pos.fbr_store_slip_word') }} - {{ $company->name }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13px;
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 4mm 3mm;
            background: #fff;
            color: #000;
            line-height: 1.4;
            font-weight: bold;
        }
        .text-center { text-align: center; }
        .separator { border-top: 1px dashed #000; margin: 4px 0; }
        .flex { display: flex; justify-content: space-between; align-items: center; }
        .shop-name { font-size: 16px; font-weight: 900; margin-bottom: 2px; }
        .section-title { font-size: 18px; font-weight: 900; margin: 4px 0; }
        .token-box {
            display: inline-block;
            border: 2px solid #000;
            padding: 2px 16px;
            font-size: 22px;
            font-weight: 900;
            color: #000;
            margin: 4px 0;
            letter-spacing: 1px;
        }
        .code-box {
            display: inline-block;
            border: 2px solid #000;
            padding: 2px 16px;
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 3px;
            color: #000;
            margin: 4px 0;
        }
        .items-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .items-table th {
            font-size: 11px;
            text-transform: uppercase;
            border-top: 2px solid #000;
            border-bottom: 1px solid #000;
            padding: 3px 2px;
            text-align: left;
        }
        .items-table td {
            font-size: 14px;
            font-weight: 900;
            padding: 4px 2px;
            vertical-align: top;
        }
        .items-table .col-name { width: 75%; }
        .items-table .col-qty  { width: 25%; text-align: right; }
        .items-table tbody tr { border-bottom: 1px dashed #000; }
        .items-table tbody tr:last-child { border-bottom: none; }
        .notes-row td { font-size: 11px; font-weight: bold; font-style: italic; padding: 2px 2px 4px; }
        .footer-line { font-size: 10px; font-weight: bold; margin-top: 4px; }
        @media print {
            body { width: auto; max-width: 72mm; padding: 1mm; margin: 0 auto; }
            .no-print { display: none !important; }
        }
        @media screen {
            body { padding: 12px; }
            .no-print { margin-bottom: 16px; text-align: center; font-family: Arial, sans-serif; }
        }
    </style>
    @if($autoPrint ?? false)
    <script>
        window.onload = function() {
            window.print();
            if (window.parent && window.parent !== window) {
                try {
                    window.parent.postMessage({ type: 'pos_print_done', signal: (new URLSearchParams(window.location.search)).get('_signal') || '' }, '*');
                } catch(e) {}
            }
        };
        window.onafterprint = function() {
            if (window.opener) { window.close(); }
        };
    </script>
    @endif
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding: 8px 24px; background: #ea580c; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">{{ __('pos.fbr_ti_print_store_slip') }}</button>
    </div>

    <div class="text-center">
        <div class="shop-name">{{ $company->name }}</div>
        <div class="separator"></div>
        <div class="section-title">*** {{ mb_strtoupper(__('pos.fbr_store_slip_word')) }} ***</div>

        {{-- Order Matching (Aug 2026): same token/code box as PRA kitchen-ticket --}}
        @php
            $omStyle = $company->order_match_style ?? 'off';
            $showToken = $omStyle === 'token' && !empty($tokenNo);
            $showCode  = $omStyle === 'code'  && !empty($orderCode);
        @endphp
        @if($showToken)
            <p style="margin-top:3px;">
                <span class="token-box">{{ __('pos.order_match_token_label', [], 'en') }} {{ $tokenNo }}</span>
            </p>
        @elseif($showCode)
            <p style="margin-top:3px;">
                <span class="code-box">{{ $orderCode }}</span>
            </p>
        @endif
    </div>

    <div class="separator"></div>

    <div class="flex">
        <span>{{ $now->format('M d, Y') }}</span>
        <span>{{ $now->format('h:i A') }}</span>
    </div>

    @if(!empty($customerName))
    <div style="margin-top:2px;">
        <span>{{ __('pos.receipt_customer', [], 'en') }}: {{ $customerName }}</span>
    </div>
    @endif

    <div class="separator"></div>

    <table class="items-table">
        <thead>
            <tr>
                <th class="col-name">Item</th>
                <th class="col-qty">Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td class="col-name">{{ $item['item_name'] ?? $item['name'] ?? '—' }}</td>
                <td class="col-qty">{{ rtrim(rtrim(number_format((float)($item['quantity'] ?? 1), 3, '.', ''), '0'), '.') }}</td>
            </tr>
            @if(!empty($item['special_notes']))
            <tr class="notes-row">
                <td colspan="2" style="padding-left:8px;">↳ {{ $item['special_notes'] }}</td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>

    @if(!empty($kitchenNotes))
    <div class="separator"></div>
    <div style="font-size:12px; font-weight:900;">Note: {{ $kitchenNotes }}</div>
    @endif

    <div class="separator"></div>
    <div class="footer-line text-center">FBR POS — {{ $company->name }}</div>
</body>
</html>
