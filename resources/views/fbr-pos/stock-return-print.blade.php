{{--
    Purchase return note (Task 1580) — the credit-note sheet that travels back
    with the goods to the distributor. Standalone, plain, signable; mirrors
    the pharmacy claim print so shops recognise it.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ret->return_number }}</title>
    @php
        $urduScript = app()->getLocale() === 'ur';
        $trim3 = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    @endphp
    <style>
        @if($urduScript)
        @include('partials.urdu-print-font')
        @endif
        * { box-sizing: border-box; }
        body { font-family: {{ $urduScript ? "'Jameel Noori Nastaleeq', 'XB Riyaz', " : '' }}'DejaVu Sans', Arial, sans-serif; margin: 0; padding: 24px; color: #111; background: #fff; font-size: {{ $urduScript ? '14px' : '12px' }}; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 14px; }
        .shop { font-size: 18px; font-weight: 700; }
        .muted { color: #555; font-size: 11px; }
        .title { font-size: 15px; font-weight: 700; text-align: right; }
        .meta { display: flex; flex-wrap: wrap; gap: 6px 28px; margin: 6px 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; }
        th { background: #f1f1f1; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        td.num, th.num { text-align: right; }
        tfoot td { font-weight: 700; background: #fafafa; }
        .credit { margin-top: 14px; padding: 10px 14px; border: 2px solid #111; display: inline-block; font-size: 14px; font-weight: 700; }
        .sign { margin-top: 40px; display: flex; justify-content: space-between; gap: 40px; }
        .sign div { flex: 1; border-top: 1px solid #111; padding-top: 6px; font-size: 11px; }
        @media print { .noprint { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="head">
        <div>
            <div class="shop">{{ $company->name ?? '' }}</div>
            <div class="muted">{{ $company->address ?? '' }}</div>
            @if($company->phone ?? null)<div class="muted">{{ $company->phone }}</div>@endif
        </div>
        <div class="title">
            {{ __('pos.sl_return_print_title') }}<br>
            <span class="muted">{{ $ret->return_number }} · {{ $ret->returned_on?->format('d M Y') ?? $ret->created_at?->format('d M Y') }}</span>
        </div>
    </div>

    <div class="meta">
        <span><strong>{{ __('pos.sl_pdf_supplier') }}:</strong> {{ $ret->supplier?->name ?? '—' }}@if($ret->supplier?->phone) · {{ $ret->supplier->phone }}@endif</span>
        @if($ret->purchaseOrder)<span><strong>{{ __('pos.sl_against_bill_lbl') }}:</strong> {{ $ret->purchaseOrder->po_number }}{{ $ret->purchaseOrder->supplier_invoice_no ? ' · #' . $ret->purchaseOrder->supplier_invoice_no : '' }}</span>@endif
        <span><strong>{{ __('pos.sl_return_reason_lbl') }}:</strong> {{ __('pos.sl_reason_' . $ret->reason) }}</span>
        @if($ret->supplier_reference)<span><strong>{{ __('pos.sl_return_sup_ref_lbl') }}:</strong> {{ $ret->supplier_reference }}</span>@endif
        @if($ret->branch)<span><strong>{{ __('pos.sl_branch_lbl') }}:</strong> {{ $ret->branch->name }}</span>@endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th>{{ __('pos.sl_col_item') }}</th>
                <th>{{ __('pos.ph_col_batch') }}</th>
                <th>{{ __('pos.ph_col_expiry') }}</th>
                <th class="num">{{ __('pos.ph_col_qty') }}</th>
                <th class="num">{{ __('pos.ph_col_cost') }}</th>
                <th class="num">{{ __('pos.ph_col_value') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach($ret->items as $i => $it)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $it->product?->name ?? ('#' . $it->product_id) }}</td>
                <td>{{ $it->batch_number ?: '—' }}</td>
                <td>{{ $it->expiry_date?->format('m/Y') ?? '—' }}</td>
                <td class="num">{{ $trim3($it->quantity) }} {{ $it->product?->uom }}</td>
                <td class="num">{{ number_format((float) $it->unit_cost, 2) }}</td>
                <td class="num">{{ number_format((float) $it->total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="num">{{ __('pos.sl_credit_note_total') }}</td>
                <td class="num">{{ number_format((float) $ret->credit_amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="credit">{{ __('pos.sl_credit_note_line', ['amount' => number_format((float) $ret->credit_amount, 2)]) }}</div>

    @if($ret->notes)<p class="muted" style="margin-top:10px"><strong>{{ __('pos.ph_col_note') }}:</strong> {{ $ret->notes }}</p>@endif
    <p class="muted">{{ __('pos.sl_prepared_by') }}: {{ $ret->creator?->name ?? '—' }}</p>

    <div class="sign">
        <div>{{ __('pos.ph_sign_shop') }}</div>
        <div>{{ __('pos.ph_sign_distributor') }}</div>
    </div>

    <p class="noprint" style="margin-top:24px">
        <button onclick="window.print()">{{ __('pos.print') }}</button>
        <a href="{{ route('fbrpos.stock.returns') }}" style="margin-left:12px">← {{ __('pos.sl_returns_link') }}</a>
    </p>
</body>
</html>
