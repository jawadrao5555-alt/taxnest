{{--
    💊 The printable hand-over list (Task 1558).

    This is the sheet that physically travels with the box to the distributor,
    so it is deliberately plain, standalone (no panel chrome) and signable at
    the bottom: the shop hands over goods and wants a name on paper for them.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $claim->claim_number }}</title>
    @php $urduScript = app()->getLocale() === 'ur'; @endphp
    <style>
        @if($urduScript)
        {{-- Standalone page: a layout gives it no font, so JNN must be declared here. --}}
        @include('partials.urdu-print-font')
        @endif
        * { box-sizing: border-box; }
        body { font-family: {{ $urduScript ? "'Jameel Noori Nastaleeq', 'XB Riyaz', " : '' }}'DejaVu Sans', Arial, sans-serif; margin: 0; padding: 24px; color: #111; background: #fff; font-size: {{ $urduScript ? '14px' : '12px' }}; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 14px; }
        .shop { font-size: 18px; font-weight: 700; }
        .muted { color: #555; font-size: 11px; }
        .title { font-size: 15px; font-weight: 700; text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; }
        th { background: #f1f1f1; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        td.num, th.num { text-align: right; }
        tfoot td { font-weight: 700; background: #fafafa; }
        .sign { margin-top: 40px; display: flex; justify-content: space-between; gap: 40px; }
        .sign div { flex: 1; border-top: 1px solid #111; padding-top: 6px; font-size: 11px; }
        @media print { .noprint { display: none; } body { padding: 0; } }
    </style>
</head>
<body onload="window.print()">
    <div class="head">
        <div>
            <div class="shop">{{ $company->name ?? '' }}</div>
            <div class="muted">{{ $company->address ?? '' }}</div>
            @if($company->phone ?? null)<div class="muted">{{ $company->phone }}</div>@endif
        </div>
        <div class="title">
            {{ __('pos.ph_claim_print_title') }}<br>
            <span class="muted">{{ $claim->claim_number }} · {{ $claim->created_at?->format('d M Y') }}</span>
        </div>
    </div>

    <p><strong>{{ __('pos.ph_col_supplier') }}:</strong> {{ $claim->supplier?->name ?? $claim->supplier_name ?? '—' }}
    @if($claim->supplier?->phone) · {{ $claim->supplier->phone }}@endif</p>

    <table>
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th>{{ __('pos.ph_col_medicine') }}</th>
                <th>{{ __('pos.ph_col_batch') }}</th>
                <th>{{ __('pos.ph_col_expiry') }}</th>
                <th>{{ __('pos.ph_writeoff_reason') }}</th>
                <th class="num">{{ __('pos.ph_col_qty') }}</th>
                <th class="num">{{ __('pos.ph_col_cost') }}</th>
                <th class="num">{{ __('pos.ph_col_value') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach($claim->items as $i => $it)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $it->item_name }}@if($it->product?->generic_name)<br><span class="muted">{{ $it->product->generic_name }} {{ $it->product->strength }}</span>@endif</td>
                <td>{{ $it->batch_number }}</td>
                <td>{{ $it->expiry_date?->format('m/Y') ?? '—' }}</td>
                <td>{{ __('pos.ph_reason_' . $it->reason) }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $it->quantity, 3, '.', ''), '0'), '.') }}</td>
                <td class="num">{{ number_format((float) $it->cost_price, 2) }}</td>
                <td class="num">{{ number_format((float) $it->total_amount, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7" class="num">{{ __('pos.ph_col_value') }}</td>
                <td class="num">{{ number_format((float) $claim->total_amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if($claim->notes)<p class="muted" style="margin-top:10px"><strong>{{ __('pos.ph_col_note') }}:</strong> {{ $claim->notes }}</p>@endif

    <div class="sign">
        <div>{{ __('pos.ph_sign_shop') }}</div>
        <div>{{ __('pos.ph_sign_distributor') }}</div>
    </div>

    <p class="noprint" style="margin-top:24px"><button onclick="window.print()">{{ __('pos.print') }}</button></p>
</body>
</html>
