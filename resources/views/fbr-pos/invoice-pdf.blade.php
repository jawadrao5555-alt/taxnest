<!DOCTYPE html>
@php $urduScript = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<html lang="{{ $urduScript ? 'ur' : 'en' }}" dir="{{ $urduScript ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $transaction->invoice_number }}</title>
    <style>
        @page {
            /* 🖨️ Safer A4 margins — prevents corner-cut on consumer printers
               (most can't reach within 12mm of paper edge). */
            margin: 15mm 15mm 18mm 15mm;
            size: A4 portrait;
        }
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
            background-color: #1e3a5f;
            padding: 16px 20px 14px;
            text-align: center;
            margin-bottom: 12px;
        }
        .header-bar .logo { margin-bottom: 6px; }
        .header-bar .logo img { max-width: 120px; max-height: 45px; object-fit: contain; }
        .header-bar h1 { font-size: 16px; font-weight: bold; color: #ffffff; margin-bottom: 4px; letter-spacing: 2px; text-transform: uppercase; }
        .header-bar p { font-size: 10px; color: #e5e7eb; line-height: 1.6; }

        .invoice-box {
            border: 2px solid #1e3a5f;
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
            padding: 6px 5px; text-align: left; font-weight: bold; color: #ffffff; background-color: #1e3a5f;
        }
        table.items thead th.r { text-align: right; }
        table.items thead th.c { text-align: center; }
        table.items tbody td { font-size: 10px; padding: 5px 5px; vertical-align: top; border-bottom: 1px solid #d1d5db; color: #000000; }
        table.items tbody tr:nth-child(even) { background-color: #f0f7ff; }
        table.items tbody td.r { text-align: right; white-space: nowrap; font-weight: 700; }
        table.items tbody td.c { text-align: center; }

        .totals-box { border-top: 2px solid #1e3a5f; padding: 6px 0; margin: 6px 0; }
        .total-row { display: table; width: 100%; }
        .total-row .lbl { display: table-cell; text-align: left; font-size: 10px; padding: 3px 0; color: #000000; font-weight: 600; }
        .total-row .val { display: table-cell; text-align: right; font-size: 10px; padding: 3px 0; white-space: nowrap; color: #000000; font-weight: 700; }
        .total-row.discount .val { color: #dc2626; }

        .grand-total-box {
            background-color: #1e3a5f; padding: 10px 16px; margin: 4px 0 10px; display: table; width: 100%;
        }
        .grand-total-box .lbl { display: table-cell; text-align: left; font-size: 16px; font-weight: bold; color: #ffffff; vertical-align: middle; }
        .grand-total-box .val { display: table-cell; text-align: right; font-size: 16px; font-weight: bold; color: #ffffff; vertical-align: middle; }

        .fbr-box { border: 1.5px solid #1e3a5f; padding: 6px; margin: 6px 0; text-align: center; }
        .fbr-box .title { font-size: 11px; font-weight: bold; color: #1e3a5f; margin-bottom: 3px; letter-spacing: 0.5px; text-transform: uppercase; }
        .fbr-box .num { font-size: 10px; font-weight: bold; color: #000000; }
        .fbr-box div { color: #000000; font-size: 10px; }
        .local-box { border: 1.5px dashed #6b7280; padding: 6px; margin: 6px 0; text-align: center; font-size: 10px; color: #374151; font-weight: 600; }

        .footer { margin-top: 10px; text-align: center; padding-top: 8px; border-top: 1.5px solid #9ca3af; }
        .footer p { font-size: 9px; color: #374151; line-height: 1.6; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #1e3a5f; margin-top: 3px; }
    </style>
    @if($urduScript)
    <style>
        /* Urdu script mode (Task 260): mPDF renders this path — DomPDF is
           bypassed for locale 'ur'. mPDF's OTL engine shapes Noto Naskh Arabic
           correctly (contextual init/medi/fina/isol forms + kashida joins).
           Layout stays LTR (display:table columns keep fixed widths); Unicode
           bidi algorithm flips each Urdu text run RTL on its own so mixed
           label/number rows still line up. Taller line-height for Urdu glyphs
           (they clip at Latin line heights on narrow leading). */
        /* 'XB Riyaz' first so mPDF (Urdu PDF path, Task 260) resolves it via
           its bundled FontVariables entry (key 'xbriyaz', useOTL=0xFF, Naskh).
           Browsers fall through to Noto Naskh Arabic → Urdu Typesetting → Tahoma. */
        body {
            font-family: 'XB Riyaz', 'Noto Naskh Arabic', 'Urdu Typesetting', Tahoma, Arial, sans-serif;
            line-height: 1.7;
        }
    </style>
    @endif
</head>
<body>
    <div class="receipt">
        <div class="header-bar">
            @if($company->logo_path)
            <div class="logo">
                <img src="{{ public_path('storage/' . $company->logo_path) }}" alt="{{ $company->name }}">
            </div>
            @endif
            @php
                // Receipt Display toggles (owner, 22 Jul 2026) — same 'fbrpos' pref
                // set the thermal receipt reads (business-profile Print Settings).
                $rd = $company->displayPrefs('fbrpos');
            @endphp
            <h1>{{ $company->name }}</h1>
            @if($rd['show_address'] && $company->address)<p>{{ $company->address }}</p>@endif
            @if($rd['show_mobile'] && $company->phone)<p>{{ __('pos.rcpt_tel') }} {{ $company->phone }}</p>@endif
            @if($rd['show_email'] && $company->email)<p>{{ $company->email }}</p>@endif
            @if($rd['show_ntn'] && $company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
        </div>

        <div class="invoice-box">
            <div class="invoice-row">
                <div class="lbl">{{ __('pos.receipt_pos_invoice') }}:</div>
                <div class="val">{{ $transaction->invoice_number }}</div>
            </div>
            {{-- Owner (6 Aug 2026): FBR invoice number sirf neeche QR box mein;
                 POS Reg # kisi jagah nahi. --}}
        </div>

        {{-- Order type badge (mirrors receipt.blade.php lines 265–281):
             Dine-In / Take Away / Delivery shown bold + centered so the badge
             is visible on the printed A4 PDF. Retail bills (no order_type) skip. --}}
        @php
            $pdfOrderType = match ($transaction->order_type ?? null) {
                'dine_in'  => 'DINE-IN',
                'takeaway' => 'TAKE AWAY',
                'delivery' => 'DELIVERY',
                default    => null,
            };
        @endphp
        @if($pdfOrderType)
        <div style="text-align:center; margin:6px 0 4px;">
            <span style="display:inline-block; border:2px solid #1e3a5f; padding:3px 16px; font-size:13px; font-weight:bold; letter-spacing:1.5px; color:#1e3a5f;">{{ $pdfOrderType }}</span>
        </div>
        @endif

        <div class="info-section">
            <div class="info-row">
                <div class="lbl">{{ __('pos.receipt_date') }}</div>
                <div class="val">{{ $transaction->created_at->format('d/m/Y h:i A') }}</div>
            </div>
            <div class="info-row">
                <div class="lbl">{{ __('pos.tax_period') }}</div>
                <div class="val">{{ $transaction->created_at->format('F Y') }}</div>
            </div>
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
            @if($transaction->customer_ntn)
            <div class="info-row">
                <div class="lbl">NTN</div>
                <div class="val">{{ $transaction->customer_ntn }}</div>
            </div>
            @endif
            <div class="info-row">
                <div class="lbl">{{ __('pos.receipt_payment_mode') }}</div>
                <div class="val">{{ ucwords(str_replace('_', ' ', $transaction->payment_method)) }}</div>
            </div>
            @if($rd['show_cashier'] && $transaction->creator)
            <div class="info-row">
                <div class="lbl">{{ __('pos.receipt_cashier') }}</div>
                <div class="val">{{ $transaction->creator->name }}</div>
            </div>
            @endif
        </div>

        <div class="section-label">{{ __('pos.receipt_order_items') }}</div>
        <table class="items">
            <thead>
                <tr>
                    <th style="width:36%;">{{ __('pos.receipt_item') }}</th>
                    <th class="c" style="width:10%;">{{ __('pos.rcpt_uom') }}</th>
                    <th class="c" style="width:8%;">{{ __('pos.receipt_qty') }}</th>
                    <th class="r" style="width:10%;">{{ __('pos.rcpt_tax_pct') }}</th>
                    <th class="r" style="width:18%;">{{ __('pos.receipt_price') }}</th>
                    <th class="r" style="width:18%;">{{ __('pos.receipt_total') }}</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $fmtQty = function($q) {
                        $f = (float) $q;
                        return $f == (int) $f ? (string) (int) $f : rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
                    };
                @endphp
                @foreach($transaction->items as $item)
                <tr>
                    <td>
                        {{ $item->item_name }}
                        @if(($item->item_discount ?? 0) > 0)
                        <div style="font-size: 9px; color: #b91c1c;">↳ {{ __('pos.receipt_discount') }}: -PKR {{ number_format($item->item_discount, 2) }}</div>
                        @endif
                    </td>
                    <td class="c">{{ $item->uom ?? 'U' }}</td>
                    <td class="c">{{ $fmtQty($item->quantity) }}</td>
                    <td class="r">{{ $item->is_tax_exempt ? 'Exempt' : number_format($item->tax_rate, 0) . '%' }}</td>
                    <td class="r">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="r">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-box">
            <div class="total-row">
                <div class="lbl">{{ __('pos.receipt_subtotal') }}</div>
                <div class="val">PKR {{ number_format($transaction->subtotal, 2) }}</div>
            </div>
            @if($transaction->discount_amount > 0)
            <div class="total-row discount">
                <div class="lbl">{{ __('pos.receipt_discount') }}{{ $transaction->discount_type === 'percentage' ? ' ('.$transaction->discount_value.'%)' : '' }}</div>
                <div class="val">-PKR {{ number_format($transaction->discount_amount, 2) }}</div>
            </div>
            @endif
            <div class="total-row">
                <div class="lbl">{{ __('pos.receipt_tax') }} ({{ number_format($transaction->tax_rate, 0) }}%)</div>
                <div class="val">PKR {{ number_format($transaction->tax_amount, 2) }}</div>
            </div>
            @if($transaction->fbr_service_charge > 0)
            <div class="total-row">
                <div class="lbl">{{ __('pos.dcp_fbr_pos_fee_sro') }}</div>
                <div class="val">PKR {{ number_format($transaction->fbr_service_charge, 2) }}</div>
            </div>
            @endif
        </div>

        <div class="grand-total-box">
            <div class="lbl">{{ __('pos.receipt_total_caps') }}</div>
            <div class="val">PKR {{ number_format($transaction->total_amount, 2) }}</div>
        </div>

        {{-- Owner (6 Aug 2026): QR box saaf — sirf FBR invoice number + Tax Asaan
             verify line (QR PDF mein pehle se alag block mein hai jahan lagta hai).
             POS invoice / POS Reg # yahan se hataye (Reg # footer mein maujood). --}}
        @if($transaction->fbr_status === 'submitted' && $transaction->fbr_invoice_number)
        @php $fbrQr = \App\Support\QrImage::dataUri($transaction->fbr_invoice_number); @endphp
        <div class="fbr-box">
            @if($fbrQr)
            <img src="{{ $fbrQr }}" alt="FBR QR" style="width: 80px; height: 80px; margin: 3px auto; display: block;">
            @endif
            <div class="num">FBR: {{ $transaction->fbr_invoice_number }}</div>
            {{-- Task 769: verify-line toggle (Receipt Settings) — default ON when absent. --}}
            @if($rd['show_verify_line'] ?? true)
            <div style="font-size:9px; margin-top:3px;">{{ __('pos.receipt_scan_verify_fbr') }}</div>
            @endif
        </div>
        @elseif($transaction->fbr_status === null || $transaction->fbr_status === 'local')
        @php
            // Mirrors thermal receipt (22 Jul 2026): PROVISIONAL only for deliberate
            // provisionals (invoice_mode 'local'); reporting-OFF finals (fbr/NULL)
            // and legacy fbr/'local' finals are REAL sales => SALE RECEIPT.
            $pdfIsProvisional = ($transaction->invoice_mode ?? 'fbr') === 'local';
            $qrData = json_encode([
                'type' => $pdfIsProvisional ? 'Provisional Bill' : 'Sale Receipt',
                'inv' => $transaction->invoice_number,
                'date' => $transaction->created_at->format('d/m/Y H:i'),
                'total' => number_format($transaction->total_amount, 2),
                'business' => $company->name ?? 'TaxNest FBR POS',
            ]);
            $qrUrl = \App\Support\QrImage::dataUri($qrData);
        @endphp
        @if($pdfIsProvisional)
        <div class="local-box" style="border: 1.5px dashed #1e3a5f; color: #1e3a5f;">
            <strong style="font-size: 12px;">{{ __('pos.receipt_provisional_bill') }}</strong><br>
            {{ $transaction->invoice_number }}<br>
            <span style="font-size: 9px;">{{ __('pos.receipt_provisional_note') }}</span>
        </div>
        @else
        <div class="local-box" style="border: 1.5px solid #1e3a5f; color: #1e3a5f;">
            <strong style="font-size: 12px;">{{ __('pos.receipt_sale_receipt') }}</strong><br>
            {{ $transaction->invoice_number }}
        </div>
        @endif
        @if($qrUrl)
        <div style="text-align: center; margin: 8px 0;">
            <img src="{{ $qrUrl }}" alt="Invoice QR" style="width: 120px; height: 120px; margin: 0 auto;">
            <p style="font-size: 9px; color: #6b7280; margin-top: 4px;">{{ __('pos.receipt_scan_invoice') }}</p>
        </div>
        @endif
        @else
        <div class="local-box">
            {{ __('pos.rcpt_fbr_pending_retry') }}<br>
            {{ $transaction->invoice_number }}
        </div>
        @endif

        <div class="footer">
            @if($rd['show_footer'])
            <p>{{ __('pos.receipt_thank_purchase') }}</p>
            @if(!empty($company->receipt_footer_note))
            <p style="font-style: italic;">{{ $company->receipt_footer_note }}</p>
            @endif
            @endif
            @if($company->fbr_pos_id)
            <p style="font-weight:bold; color:#1e3a5f;">{{ __('pos.rcpt_fbr_integrated') }}</p>
            @endif
            <div class="brand">{{ __('pos.dcp_powered_taxnest_fbr') }}</div>
            {{-- Owner (6 Aug 2026): print-time timestamp hataya — Date info mein hai. --}}
        </div>
    </div>
</body>
</html>
