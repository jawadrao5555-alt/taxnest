<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $transaction->invoice_number }}</title>
    <style>
        @page { margin: 8mm 12mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000000;
            line-height: 1.5;
            background: #fff;
        }
        .receipt { max-width: 100%; margin: 0 auto; }

        .header-bar {
            background-color: #1e1b4b;
            padding: 16px 20px 14px;
            text-align: center;
            margin-bottom: 12px;
        }
        .header-bar .logo { margin-bottom: 6px; }
        .header-bar .logo img { max-width: 120px; max-height: 45px; object-fit: contain; }
        .header-bar h1 { font-size: 16px; font-weight: bold; color: #ffffff; margin-bottom: 4px; letter-spacing: 2px; text-transform: uppercase; }
        .header-bar p { font-size: 10px; color: #e5e7eb; line-height: 1.6; }

        .invoice-box {
            border: 2px solid #1e1b4b;
            padding: 8px 14px;
            margin: 0 0 10px;
        }
        .invoice-row { display: table; width: 100%; }
        .invoice-row .lbl { display: table-cell; width: 36%; font-size: 11px; font-weight: bold; padding: 3px 0; color: #000000; }
        .invoice-row .val { display: table-cell; width: 64%; font-size: 11px; text-align: right; padding: 3px 0; font-weight: bold; color: #000000; letter-spacing: 0.5px; }

        .info-section { padding: 6px 0; margin-bottom: 8px; border-bottom: 1.5px solid #9ca3af; }
        .info-row { display: table; width: 100%; }
        .info-row .lbl { display: table-cell; width: 28%; font-size: 10px; font-weight: bold; padding: 3px 0; color: #000000; text-transform: uppercase; letter-spacing: 0.3px; }
        .info-row .val { display: table-cell; width: 72%; font-size: 10px; text-align: right; padding: 3px 0; color: #000000; }

        .section-label { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #000000; margin-bottom: 4px; }

        table.items { width: 100%; border-collapse: collapse; margin: 6px 0; }
        table.items thead th {
            font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px;
            padding: 6px 5px; text-align: left; font-weight: bold; color: #ffffff; background-color: #1e1b4b;
        }
        table.items thead th.r { text-align: right; }
        table.items thead th.c { text-align: center; }
        table.items tbody td { font-size: 10px; padding: 5px 5px; vertical-align: top; border-bottom: 1px solid #d1d5db; color: #000000; }
        table.items tbody tr:nth-child(even) { background-color: #f5f3ff; }
        table.items tbody td.r { text-align: right; white-space: nowrap; font-weight: 700; }
        table.items tbody td.c { text-align: center; }
        .exempt-tag { font-size: 8px; font-weight: bold; color: #92400e; background: #fef3c7; padding: 1px 4px; border-radius: 2px; }

        .totals-box { border-top: 2px solid #1e1b4b; padding: 6px 0; margin: 6px 0; }
        .total-row { display: table; width: 100%; }
        .total-row .lbl { display: table-cell; text-align: left; font-size: 10px; padding: 3px 0; color: #000000; font-weight: 600; }
        .total-row .val { display: table-cell; text-align: right; font-size: 10px; padding: 3px 0; white-space: nowrap; color: #000000; font-weight: 700; }
        .total-row.discount .val { color: #dc2626; }

        .grand-total-box {
            background-color: #1e1b4b; padding: 10px 16px; margin: 4px 0 10px; display: table; width: 100%;
        }
        .grand-total-box .lbl { display: table-cell; text-align: left; font-size: 16px; font-weight: bold; color: #ffffff; vertical-align: middle; }
        .grand-total-box .val { display: table-cell; text-align: right; font-size: 16px; font-weight: bold; color: #ffffff; vertical-align: middle; }

        .pra-box { border: 2px solid #1e1b4b; padding: 8px; margin: 6px 0; text-align: center; }
        .pra-box .title { font-size: 11px; font-weight: bold; color: #1e1b4b; margin-bottom: 3px; letter-spacing: 0.5px; text-transform: uppercase; }
        .pra-box .num { font-size: 10px; font-weight: bold; color: #000000; }
        .pra-box div { color: #000000; font-size: 10px; }
        .local-box { border: 1.5px dashed #6b7280; padding: 6px; margin: 6px 0; text-align: center; font-size: 10px; color: #374151; font-weight: 600; }
        .qr-section { text-align: center; margin: 6px 0; }
        .qr-section img { width: 90px; height: 90px; }
        .qr-section p { font-size: 8px; margin-top: 2px; color: #374151; }

        .footer { margin-top: 10px; text-align: center; padding-top: 8px; border-top: 1.5px solid #9ca3af; }
        .footer p { font-size: 9px; color: #374151; line-height: 1.6; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #1e1b4b; margin-top: 3px; }
    </style>
</head>
<body>
    <div class="receipt">
        {{-- Receipt display prefs (ZFC 13 Aug 2026): this header used to print
             address/phone/email/NTN UNCONDITIONALLY — the ONLY render path that
             bypassed posReceiptPrefsFor(). Gate every optional line on the same
             per-transaction set the thermal receipts use. --}}
        @php $rpPdf = optional($transaction->company)->posReceiptPrefsFor($transaction) ?? \App\Models\Company::defaultDisplayPrefs(); @endphp
        <div class="header-bar">
            @if($company->logo_path)
            <div class="logo">
                <img src="{{ public_path('storage/' . $company->logo_path) }}" alt="{{ $company->name }}">
            </div>
            @endif
            @if($rpPdf['show_business_name'] ?? true)<h1>{{ $company->name }}</h1>@endif
            @if($company->address && ($rpPdf['show_address'] ?? true))<p>{{ $company->address }}</p>@endif
            @if($company->phone && ($rpPdf['show_mobile'] ?? true))<p>{{ __('pos.rcpt_tel') }} {{ $company->phone }}</p>@endif
            @if($company->email && ($rpPdf['show_email'] ?? true))<p><!--email_off-->{{ $company->email }}<!--/email_off--></p>@endif
            @if($company->ntn && ($rpPdf['show_ntn'] ?? true))<p>NTN: {{ $company->ntn }}</p>@endif
            @if($company->pra_pos_id)<p>{{ __('pos.rcpt_pos_reg') }} {{ $company->pra_pos_id }}</p>@endif
        </div>

        <div class="invoice-box">
            <div class="invoice-row">
                <div class="lbl">{{ __('pos.receipt_pos_invoice') }}:</div>
                <div class="val">{{ $transaction->invoice_number }}</div>
            </div>
            @if($transaction->pra_invoice_number)
            <div class="invoice-row">
                <div class="lbl">{{ __('pos.receipt_pra_fiscal') }}:</div>
                <div class="val">{{ $transaction->pra_invoice_number }}</div>
            </div>
            @endif
        </div>

        <div class="info-section">
            <div class="info-row">
                <div class="lbl">{{ __('pos.receipt_date') }}</div>
                <div class="val">{{ $transaction->created_at->format('d/m/Y h:i A') }}</div>
            </div>
            @if($transaction->terminal)
            <div class="info-row">
                <div class="lbl">{{ __('pos.receipt_terminal') }}</div>
                <div class="val">{{ $transaction->terminal->terminal_name }}</div>
            </div>
            @endif
            <div class="info-row">
                <div class="lbl">{{ __('pos.receipt_customer') }}</div>
                <div class="val">{{ $transaction->customer_name ?? __('pos.receipt_walkin') }}</div>
            </div>
            @if($transaction->customer_phone)
            <div class="info-row">
                <div class="lbl">{{ __('pos.receipt_phone') }}</div>
                <div class="val">{{ $transaction->customer_phone }}</div>
            </div>
            @endif
            <div class="info-row">
                <div class="lbl">{{ __('pos.receipt_payment_mode') }}</div>
                <div class="val">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</div>
            </div>
            {{-- Owner (Jul 2026): PRA and Local bills each have their OWN display set
                 — $rpPdf resolved once at the header above. --}}
            @if($transaction->creator && $rpPdf['show_cashier'])
            <div class="info-row">
                <div class="lbl">{{ __('pos.receipt_cashier') }}</div>
                <div class="val">{{ $transaction->creator->name }}</div>
            </div>
            @endif
        </div>

        @php
            // Owner decision (Jul 2026): toggle OFF hides subtotal + tax on ALL receipts
            // (incl. PRA fiscal) — customer copy shows grand total only and the Tax%
            // column is dropped. Item Price/Total show the ORIGINAL as-entered (ex-tax)
            // prices (owner update Jul 2026) even though lines then intentionally do not
            // sum to the grand total. Tax is always submitted to PRA; details visible
            // via Sahulat app QR scan.
            // Per-type since Jul 2026: resolved via posReceiptPrefsFor() in $rpPdf above.
            $showTaxLines = (bool) ($rpPdf['show_tax'] ?? true);
        @endphp
        <div class="section-label">{{ __('pos.receipt_order_items') }}</div>
        <table class="items">
            <thead>
                <tr>
                    <th style="width:{{ $showTaxLines ? '40%' : '50%' }};">{{ __('pos.receipt_item') }}</th>
                    <th class="c" style="width:10%;">{{ __('pos.receipt_qty') }}</th>
                    @if($showTaxLines)
                    <th class="r" style="width:10%;">{{ __('pos.rcpt_tax_pct') }}</th>
                    @endif
                    <th class="r" style="width:20%;">{{ __('pos.receipt_price') }}</th>
                    <th class="r" style="width:20%;">{{ __('pos.receipt_total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transaction->items as $item)
                @php
                    $lineAmt = (float) $item->subtotal;
                    $lineRate = (float) $item->unit_price;
                @endphp
                <tr>
                    <td>
                        {{ $item->item_name }}
                        @if($showTaxLines && $item->is_tax_exempt)
                        <span class="exempt-tag">{{ __('pos.rcpt_exempt') }}</span>
                        @endif
                    </td>
                    <td class="c">{{ $item->quantity }}</td>
                    @if($showTaxLines)
                    <td class="r">{{ $item->is_tax_exempt ? 'Exempt' : number_format($item->tax_rate ?? $transaction->tax_rate, 0) . '%' }}</td>
                    @endif
                    <td class="r">{{ number_format($lineRate, 2) }}</td>
                    <td class="r">{{ number_format($lineAmt, 2) }}</td>
                </tr>
                {{-- Deal components (frozen snapshot) — indented, NO price columns. --}}
                @if($item->item_type === 'deal' && is_array($item->deal_snapshot))
                @foreach($item->deal_snapshot as $comp)
                <tr>
                    <td style="padding-left:14px; font-size:9px; color:#000;">• {{ (int)($comp['qty'] ?? 1) }}x {{ $comp['name'] ?? 'Item' }}</td>
                    <td class="c"></td>
                    @if($showTaxLines)
                    <td class="r"></td>
                    @endif
                    <td class="r"></td>
                    <td class="r"></td>
                </tr>
                @endforeach
                @endif
                @endforeach
            </tbody>
        </table>

        @php
            // Tax-Inclusive (Menu-Rate-Final) bills: display the menu-price subtotal
            // (stored ex-tax header + included tax) so it matches the item lines.
            $pdfInclusive = (bool) ($transaction->tax_inclusive ?? false);
            // Card-save (mode 3) card/digital bills: "Menu Total" = item sum + explicit
            // "Card Discount" saving line (visible even when Show-Tax is OFF).
            $pdfMenuRate = $pdfInclusive ? ($transaction->tax_menu_rate ?? null) : null;
            $pdfCardSave = $pdfMenuRate !== null && (float) $pdfMenuRate > 0
                && abs((float) $pdfMenuRate - (float) $transaction->tax_rate) >= 0.005;
            $pdfDisplaySubtotal = $pdfCardSave
                ? (float) $transaction->items->sum('subtotal')
                : ($pdfInclusive
                    ? (float) $transaction->subtotal + (float) $transaction->tax_amount
                    : (float) $transaction->subtotal);
            $pdfCardSaving = $pdfCardSave
                ? max(0.0, round($pdfDisplaySubtotal - (float) $transaction->discount_amount - (float) $transaction->total_amount, 2))
                : 0.0;
        @endphp
        <div class="totals-box">
            @if($showTaxLines || $pdfCardSave)
            <div class="total-row">
                <div class="lbl">{{ $pdfCardSave ? __('pos.receipt_menu_total') : __('pos.receipt_subtotal') }}</div>
                <div class="val">PKR {{ number_format($pdfDisplaySubtotal, 2) }}</div>
            </div>
            @endif
            @if($transaction->discount_amount > 0)
            <div class="total-row discount">
                <div class="lbl">{{ __('pos.receipt_discount') }}{{ $transaction->discount_type === 'percentage' ? ' ('.$transaction->discount_value.'%)' : '' }}</div>
                <div class="val">-PKR {{ number_format($showTaxLines ? $transaction->discount_amount : round((float) $transaction->discount_amount), 2) }}</div>
            </div>
            @endif
            @if($pdfCardSave && $pdfCardSaving > 0.009)
            <div class="total-row discount">
                <div class="lbl">{{ __('pos.receipt_card_discount') }}</div>
                <div class="val">-PKR {{ number_format($pdfCardSaving, 2) }}</div>
            </div>
            @endif
            @if($showTaxLines)
            <div class="total-row">
                <div class="lbl">{{ __('pos.receipt_tax') }} ({{ number_format($transaction->tax_rate, 0) }}%{{ $pdfInclusive ? ' incl.' : '' }})</div>
                <div class="val">PKR {{ number_format($transaction->tax_amount, 2) }}</div>
            </div>
            @endif
        </div>

        <div class="grand-total-box">
            <div class="lbl">{{ __('pos.receipt_total_caps') }}</div>
            <div class="val">PKR {{ number_format($showTaxLines ? $transaction->total_amount : round((float) $transaction->total_amount), 2) }}</div>
        </div>

        @if($transaction->pra_status === 'submitted' && $transaction->pra_invoice_number)
        <div class="pra-box">
            <div class="title">✓ {{ __('pos.rcpt_pra_fiscal_invoice') }}</div>
            <div>POS: {{ $transaction->invoice_number }}</div>
            <div class="num">PRA: {{ $transaction->pra_invoice_number }}</div>
        </div>
        @php
            // minVersion 4 (ZFC 13 Aug 2026): same module grid as the local invoice
            // QR — visually consistent QRs, content untouched.
            $praQr = $transaction->pra_invoice_number
                ? \App\Support\QrImage::dataUri($transaction->pra_invoice_number, 5, 4)
                : ($transaction->pra_qr_code ?: '');
        @endphp
        @if($praQr)
        <div class="qr-section">
            <img src="{{ $praQr }}" alt="PRA QR">
            <p>{{ __('pos.receipt_scan_verify') }}</p>
        </div>
        @endif
        @elseif($transaction->pra_status === 'offline')
        <div class="local-box">
            {{ __('pos.receipt_offline_invoice') }} — {{ __('pos.receipt_offline_sync_auto') }}<br>
            {{ $transaction->invoice_number }}
        </div>
        @else
        @php
            // Reporting-OFF FINALS vs provisionals (client report Jul 2026 — ZFC):
            // finals with PRA reporting OFF are invoice_mode 'pra' + NULL pra_status —
            // they are REAL completed sales, NOT provisionals. Only deliberate
            // provisionals (invoice_mode 'local') may carry the PROVISIONAL badge.
            $rcptIsProvisional = ($transaction->invoice_mode ?? 'pra') === 'local';
            // Task #292 parity (ZFC 13 Aug 2026): the PDF used to print the invoice
            // QR even when "Print Menu QR Code" was OFF — honor the same gate as the
            // thermal receipts. Compact plain-text payload + minVersion 4 mirrors
            // receipt_80mm (visual consistency with the PRA QR); business name only
            // when the resolved display set allows it.
            $pdfShowQr = (bool) (optional($transaction->company)->posReceiptStyle()['show_menu_qr'] ?? true);
            $qrUrl = null;
            if ($pdfShowQr) {
                $qrLines = [
                    $rcptIsProvisional ? 'Provisional Bill' : 'Sale Receipt',
                    $transaction->invoice_number,
                    $transaction->created_at->format('d/m/Y H:i'),
                    'Total: ' . number_format($transaction->total_amount, 2),
                ];
                if ($rpPdf['show_business_name'] ?? true) {
                    $qrLines[] = $transaction->company->name ?? 'NestPOS';
                }
                $qrUrl = \App\Support\QrImage::dataUri(implode("\n", $qrLines), 5, 4);
            }
        @endphp
        @if($rcptIsProvisional)
        <div class="local-box" style="border: 1.5px dashed #7c3aed; color: #5b21b6;">
            <strong style="font-size: 12px;">{{ __('pos.receipt_provisional_bill') }}</strong><br>
            {{ $transaction->invoice_number }}<br>
            <span style="font-size: 9px;">{{ __('pos.receipt_provisional_note') }}</span>
        </div>
        @else
        <div class="local-box" style="border: 1.5px solid #374151; color: #111827;">
            <strong style="font-size: 12px;">{{ __('pos.receipt_sale_receipt') }}</strong><br>
            {{ $transaction->invoice_number }}
            {{-- Task 655: agent-mode bill rendered while still 'pending' — chhoti
                 wazahat ke yeh bill PRA ko report ho raha hai. --}}
            @if(($transaction->pra_status ?? null) === 'pending')<br><span style="font-size: 9px;">{{ __('pos.receipt_pending_pra_note') }}</span>@endif
        </div>
        @endif
        @if($qrUrl)
        <div class="qr-section" style="text-align: center; margin: 8px 0;">
            {{-- 90px = same rendered size as the PRA QR above (ZFC 13 Aug 2026). --}}
            <img src="{{ $qrUrl }}" alt="Invoice QR" style="width: 90px; height: 90px; margin: 0 auto;">
            <p style="font-size: 9px; color: #6b7280; margin-top: 4px;">{{ __('pos.receipt_scan_invoice') }}</p>
        </div>
        @endif
        @endif

        <div class="footer">
            <p>{{ __('pos.receipt_thank_purchase') }}</p>
            <div class="brand">{{ __('pos.brand_developed_by') }}</div>
            <p>{{ now()->format('d/m/Y h:i:s A') }}</p>
        </div>
    </div>
</body>
</html>
