<!DOCTYPE html>
{{-- Task 1580: distributor statement PDF (A4) — same numbers as the page,
     rendered for the rep / the file. mPDF for Urdu script, DomPDF otherwise. --}}
@php
    $kindLabels = [
        'purchase' => __('pos.sl_kind_purchase'),
        'payment' => __('pos.sl_kind_payment'),
        'return' => __('pos.sl_kind_return'),
        'claim' => __('pos.sl_kind_claim'),
    ];
    $methodLabels = [
        'cash' => __('pos.sl_method_cash'),
        'bank' => __('pos.sl_method_bank'),
        'online' => __('pos.sl_method_online'),
        'cheque' => __('pos.sl_method_cheque'),
    ];
@endphp
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 11px; margin: 0; }
        .head { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 12px; }
        .head h1 { font-size: 17px; margin: 0; }
        .head .sub { font-size: 10px; color: #6b7280; margin-top: 2px; }
        .meta { margin-bottom: 12px; font-size: 11px; }
        .meta b { display: inline-block; min-width: 90px; color: #374151; }
        .kpis { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
        .kpis td { width: 20%; border: 1px solid #e5e7eb; padding: 7px 9px; }
        .kpis .lbl { font-size: 8px; color: #6b7280; text-transform: uppercase; }
        .kpis .val { font-size: 13px; font-weight: bold; }
        .kpis .due { background: #fffbeb; }
        table.tx { width: 100%; border-collapse: collapse; }
        table.tx th { background: #f3f4f6; text-align: left; padding: 5px 7px; font-size: 9px; text-transform: uppercase; color: #4b5563; border-bottom: 1px solid #d1d5db; }
        table.tx td { padding: 5px 7px; border-bottom: 1px solid #eee; vertical-align: top; }
        table.tx .r { text-align: right; }
        table.tx .void td { color: #9ca3af; text-decoration: line-through; }
        .tot td { font-weight: bold; background: #f9fafb; border-top: 2px solid #d1d5db; }
        .small { font-size: 9px; color: #6b7280; }
        .foot { margin-top: 14px; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
    @if($pdfUrdu ?? false)
    <style>
        * { font-family: 'Jameel Noori Nastaleeq', 'XB Riyaz', DejaVu Sans, sans-serif; }
        body { direction: rtl; }
        table.tx, .kpis { direction: rtl; }
        table.tx th { text-transform: none; letter-spacing: 0; }
        .kpis .lbl { text-transform: none; }
    </style>
    @endif
</head>
<body>
    <div class="head">
        <h1>{{ __('pos.sl_statement_title') }}</h1>
        <div class="sub">{{ $company->name ?? '' }} &middot; {{ __('pos.dcp_generated') }} {{ now()->format('d M Y, H:i') }}</div>
    </div>

    <div class="meta">
        <div><b>{{ __('pos.sl_pdf_supplier') }}</b> {{ $supplier->name }}</div>
        @if($supplier->phone)<div><b>{{ __('pos.stock_sup_phone_ph') }}</b> {{ $supplier->phone }}</div>@endif
        @if($supplier->city)<div><b>{{ __('pos.stock_sup_city_ph') }}</b> {{ $supplier->city }}</div>@endif
        <div><b>{{ __('pos.sl_pdf_period') }}</b>
            {{ $from ? \Illuminate\Support\Carbon::parse($from)->format('d M Y') : '…' }} – {{ $to ? \Illuminate\Support\Carbon::parse($to)->format('d M Y') : now()->format('d M Y') }}
        </div>
        @if(($multiBranch ?? false))<div><b>{{ __('pos.sl_branch_lbl') }}</b> {{ $activeBranchName ?? __('pos.branch_all') }}</div>@endif
    </div>

    <table class="kpis">
        <tr>
            <td><div class="lbl">{{ __('pos.sl_col_billed') }}</div><div class="val">Rs {{ number_format($balance->billed, 0) }}</div></td>
            <td><div class="lbl">{{ __('pos.sl_col_paid') }}</div><div class="val">Rs {{ number_format($balance->paid, 0) }}</div></td>
            <td><div class="lbl">{{ __('pos.sl_col_returns') }}</div><div class="val">Rs {{ number_format($balance->returned, 0) }}</div></td>
            <td><div class="lbl">{{ __('pos.sl_col_claim_credits') }}</div><div class="val">Rs {{ number_format($balance->credited, 0) }}</div></td>
            <td class="due"><div class="lbl">{{ $balance->balance < -0.004 ? __('pos.sl_advance') : __('pos.sl_baqaya') }}</div><div class="val">Rs {{ number_format(abs($balance->balance), 2) }}</div></td>
        </tr>
    </table>

    <table class="tx">
        <thead>
            <tr>
                <th>{{ __('pos.sl_col_date') }}</th>
                <th>{{ __('pos.sl_col_type') }}</th>
                <th>{{ __('pos.sl_col_ref') }}</th>
                <th class="r">{{ __('pos.sl_col_debit') }}</th>
                <th class="r">{{ __('pos.sl_col_credit') }}</th>
                <th class="r">{{ __('pos.sl_col_balance') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="5" class="small">{{ __('pos.sl_opening_balance') }}</td>
                <td class="r"><b>{{ number_format($statement['opening'], 2) }}</b></td>
            </tr>
            @forelse($statement['rows'] as $r)
            <tr class="{{ $r['void'] ? 'void' : '' }}">
                <td>{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d M Y') }}</td>
                <td>{{ $kindLabels[$r['kind']] ?? $r['kind'] }}{{ $r['void'] ? ' (' . __('pos.sl_void_tag') . ')' : '' }}</td>
                <td>{{ $r['kind'] === 'payment' ? ($methodLabels[$r['ref']] ?? $r['ref']) : $r['ref'] }}@if($r['detail'] !== '') <span class="small">{{ $r['detail'] }}</span>@endif</td>
                <td class="r">{{ $r['debit'] > 0 ? number_format($r['debit'], 2) : '' }}</td>
                <td class="r">{{ $r['credit'] > 0 ? number_format($r['credit'], 2) : '' }}</td>
                <td class="r">{{ number_format($r['balance'], 2) }}</td>
            </tr>
            @empty
            <tr><td colspan="6" style="text-align:center; padding:16px; color:#9ca3af;">{{ __('pos.sl_no_rows') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="tot">
                <td colspan="3">{{ __('pos.sl_closing_balance') }}</td>
                <td class="r">{{ number_format($statement['period']['billed'], 2) }}</td>
                <td class="r">{{ number_format($statement['period']['paid'] + $statement['period']['returned'] + $statement['period']['credited'], 2) }}</td>
                <td class="r">{{ number_format($statement['closing'], 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="foot">{{ __('pos.sl_statement_note') }}</div>
</body>
</html>
