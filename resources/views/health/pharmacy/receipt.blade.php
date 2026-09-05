{{--
    Pharmacy receipt — standalone page, no panel chrome, because it goes to
    paper. 80mm thermal by default, still readable on A4.

    The batch and expiry of every line are printed: that is what makes a strip
    traceable after it leaves the counter, and it is the pharmacy's own defence
    in a recall.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->sale_number }}</title>
    @if(app()->getLocale() === 'ur')
        @includeIf('partials.urdu-print-font')
    @endif
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 10px 8px 24px;
            background: #fff;
            color: #000;
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 12px;
            line-height: 1.45;
            /* Never pin the body to the paper width: a fixed width clips on
               narrower rolls and leaves a dead margin on wider ones. */
            max-width: 320px;
            margin-inline: auto;
        }
        .center { text-align: center; }
        .end { text-align: end; }
        .bold { font-weight: 700; }
        .big { font-size: 15px; }
        .muted { color: #444; font-size: 11px; }
        .rule { border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 2px 0; vertical-align: top; }
        th { font-size: 10px; text-transform: uppercase; border-bottom: 1px solid #000; text-align: start; }
        td.n, th.n { text-align: end; white-space: nowrap; }
        .totals td { padding: 1px 0; }
        .grand { font-size: 15px; font-weight: 800; border-top: 1px solid #000; }
        .noprint { margin-top: 14px; }
        @media print {
            .noprint { display: none !important; }
            body { padding: 0; max-width: none; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="center">
        <p class="bold big" style="margin:0">{{ $company->name ?? '' }}</p>
        @if($company->address ?? null)<p class="muted" style="margin:2px 0">{{ $company->address }}</p>@endif
        @if($company->phone ?? null)<p class="muted" style="margin:2px 0">{{ $company->phone }}</p>@endif
        @if($fbr['registration_no'])<p class="muted" style="margin:2px 0">{{ __('health.ph_ntn') }}: {{ $fbr['registration_no'] }}</p>@endif
        <p class="bold" style="margin:6px 0 0">{{ __('health.ph_receipt_title') }}</p>
    </div>

    <div class="rule"></div>

    <table>
        <tr>
            <td class="bold">{{ $sale->sale_number }}</td>
            <td class="n muted">{{ $sale->created_at?->format('d-m-Y H:i') }}</td>
        </tr>
        <tr>
            <td colspan="2" class="muted">
                {{ __('health.ph_patient_name') }}: {{ $sale->patient_name ?: __('health.ph_walk_in') }}
                @if($sale->patient_mr_no) &middot; {{ $sale->patient_mr_no }} @endif
            </td>
        </tr>
        @if($sale->prescription)
            <tr><td colspan="2" class="muted">{{ __('health.ph_rx_title') }}: {{ $sale->prescription->prescription_no }}</td></tr>
        @endif
        @if($sale->creator)
            <tr><td colspan="2" class="muted">{{ __('health.ph_by') }}: {{ $sale->creator->name }}</td></tr>
        @endif
    </table>

    <div class="rule"></div>

    <table>
        <thead>
            <tr>
                <th>{{ __('health.ph_medicine') }}</th>
                <th class="n">{{ __('health.ph_qty') }}</th>
                <th class="n">{{ __('health.ph_unit_price') }}</th>
                <th class="n">{{ __('health.ph_line_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
                <tr>
                    <td>
                        {{ $item->item_name }}
                        @if($item->batch_no || $item->expiry_date)
                            <div class="muted">
                                @if($item->batch_no){{ __('health.ph_batch_no') }}: {{ $item->batch_no }}@endif
                                @if($item->expiry_date) &middot; {{ __('health.ph_expiry') }}: {{ $item->expiry_date->format('m/Y') }}@endif
                            </div>
                        @endif
                        @if($item->dosage_instructions)
                            <div class="muted">{{ $item->dosage_instructions }}</div>
                        @endif
                    </td>
                    <td class="n">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                    <td class="n">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="n">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="rule"></div>

    <table class="totals">
        <tr>
            <td>{{ __('health.ph_subtotal') }}</td>
            <td class="n">{{ number_format((float) $sale->subtotal, 2) }}</td>
        </tr>
        @if((float) $sale->discount_amount > 0)
            <tr>
                <td>{{ __('health.ph_discount') }}</td>
                <td class="n">−{{ number_format((float) $sale->discount_amount, 2) }}</td>
            </tr>
        @endif
        @if((float) $sale->tax_amount > 0)
            <tr>
                <td>{{ __('health.ph_tax') }} ({{ rtrim(rtrim(number_format((float) $sale->tax_rate, 2, '.', ''), '0'), '.') }}%)</td>
                <td class="n">{{ number_format((float) $sale->tax_amount, 2) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>{{ __('health.ph_total') }}</td>
            <td class="n">{{ number_format((float) $sale->total_amount, 2) }}</td>
        </tr>
        <tr>
            <td>{{ __('health.pay_' . $sale->payment_method) }}</td>
            <td class="n">{{ number_format((float) $sale->paid_amount, 2) }}</td>
        </tr>
        @if((float) $sale->change_amount > 0)
            <tr>
                <td>{{ __('health.ph_change') }}</td>
                <td class="n">{{ number_format((float) $sale->change_amount, 2) }}</td>
            </tr>
        @endif
        @if((float) $sale->refunded_amount > 0)
            <tr>
                <td>{{ __('health.ph_refunded') }}</td>
                <td class="n">−{{ number_format((float) $sale->refunded_amount, 2) }}</td>
            </tr>
        @endif
    </table>

    @if($sale->notes)
        <div class="rule"></div>
        <p class="muted" style="margin:0">{{ $sale->notes }}</p>
    @endif

    <div class="rule"></div>
    <p class="center muted" style="margin:0">{{ __('health.ph_receipt_footer') }}</p>

    {{-- Filing is the billing module's job; this line only states that the tax
         split was frozen in a filable shape at sale time. --}}
    @if($sale->fbr_ready && $fbr['registration_no'])
        <p class="center muted" style="margin:4px 0 0">{{ __('health.ph_fbr_ready_note') }}</p>
    @endif

    <div class="noprint center">
        <button type="button" onclick="window.print()"
                style="padding:8px 18px;border:1px solid #000;background:#fff;font-weight:700;border-radius:8px;cursor:pointer">
            {{ __('health.ph_print') }}
        </button>
    </div>

</body>
</html>
