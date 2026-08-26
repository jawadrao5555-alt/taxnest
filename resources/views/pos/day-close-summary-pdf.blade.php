<!DOCTYPE html>
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ ($isXReport ?? false) ? __('pos.dc_summary_x') : __('pos.dc_summary_z') }} {{ $report->report_number }}</title>
    <style>
        @page { margin: 10mm 15mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; font-size: 11px; line-height: 1.45; }
        .head { background: #1e1b4b; color: #fff; padding: 16px; text-align: center; }
        .head h1 { margin: 0; font-size: 17px; letter-spacing: 1px; }
        .head p { margin: 4px 0 0; font-size: 10px; color: #e5e7eb; }
        .notice { border: 2px solid #0ea5e9; background: #f0f9ff; color: #075985; text-align: center; font-weight: bold; padding: 8px; margin: 12px 0; }
        .section { color: #312e81; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .7px; border-bottom: 1px solid #312e81; padding-bottom: 3px; margin: 14px 0 6px; }
        .headline { width: 100%; border-collapse: separate; border-spacing: 7px; margin: 6px -7px; }
        .headline td { width: 25%; background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 5px; padding: 8px; }
        .label { display: block; font-size: 8px; color: #4b5563; text-transform: uppercase; }
        .value { display: block; font-weight: bold; font-size: 14px; color: #1e1b4b; margin-top: 2px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #1e1b4b; color: #fff; padding: 6px; text-align: left; font-size: 9px; }
        table.data td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
        table.data .r { text-align: right; font-weight: bold; }
        .foot { margin-top: 16px; color: #6b7280; text-align: center; font-size: 9px; border-top: 1px solid #d1d5db; padding-top: 7px; }
    </style>
    @if($pdfUrdu ?? false)
    <style>body, table.data { font-family: 'Jameel Noori Nastaleeq', 'XB Riyaz', DejaVu Sans, sans-serif; direction: rtl; } .section, .label { letter-spacing: 0; text-transform: none; }</style>
    @endif
</head>
<body>
@php
    $isX = (bool) ($isXReport ?? false);
    $fmt = fn ($n) => 'PKR ' . number_format((float) $n, 2);
    $date = \Carbon\Carbon::parse($report->report_date)->format('d M Y');
    $streams = is_array($streamSplit ?? null) ? $streamSplit : [];
@endphp
<div class="head">
    <h1>{{ $isX ? __('pos.dc_summary_x') : __('pos.dc_summary_z') }}</h1>
    <p>{{ $company->name ?? '' }} &middot; {{ $date }} &middot; {{ $report->report_number }}</p>
</div>
@if($isX)
<div class="notice">{{ __('pos.dc_summary_provisional') }}</div>
@endif

<div class="section">{{ __('pos.dc_summary_overview') }}</div>
<table class="headline"><tr>
    <td><span class="label">{{ __('pos.dc_summary_bills') }}</span><span class="value">{{ number_format((int) ($report->total_invoices ?? 0)) }}</span></td>
    <td><span class="label">{{ __('pos.dc_summary_gross') }}</span><span class="value">{{ $fmt($report->gross_sales ?? 0) }}</span></td>
    <td><span class="label">{{ __('pos.dc_summary_discount') }}</span><span class="value">{{ $fmt($report->total_discount ?? 0) }}</span></td>
    <td><span class="label">{{ __('pos.dc_summary_total') }}</span><span class="value">{{ $fmt($report->total_amount ?? 0) }}</span></td>
</tr></table>
<table class="data">
    <tr><td>{{ __('pos.dc_summary_net') }}</td><td class="r">{{ $fmt($report->net_sales ?? 0) }}</td></tr>
    <tr><td>{{ __('pos.dc_summary_tax') }}</td><td class="r">{{ $fmt($report->total_tax ?? 0) }}</td></tr>
    @if((int) ($report->returns_count ?? 0) > 0)<tr><td>{{ __('pos.dc_summary_returns', ['count' => $report->returns_count]) }}</td><td class="r">-{{ $fmt($report->returns_amount ?? 0) }}</td></tr>@endif
</table>

<div class="section">{{ __('pos.dc_summary_payments') }}</div>
<table class="data">
    <tr><td>{{ __('pos.dc_summary_cash') }}</td><td class="r">{{ $fmt($report->cash_amount ?? 0) }}</td></tr>
    <tr><td>{{ __('pos.dc_summary_card') }}</td><td class="r">{{ $fmt($report->card_amount ?? 0) }}</td></tr>
    <tr><td>{{ __('pos.dc_summary_other') }}</td><td class="r">{{ $fmt($report->other_amount ?? 0) }}</td></tr>
</table>

@if(!empty($streams['pra']) || !empty($streams['local']))
<div class="section">{{ __('pos.dc_summary_streams') }}</div>
<table class="data">
    <thead><tr><th>{{ __('pos.dc_summary_stream') }}</th><th class="r">{{ __('pos.dc_summary_bills') }}</th><th class="r">{{ __('pos.dc_summary_tax') }}</th><th class="r">{{ __('pos.dc_summary_total') }}</th></tr></thead>
    <tbody>
    @foreach(['pra' => __('pos.dc_summary_pra'), 'local' => __('pos.dc_summary_local')] as $key => $label)
        @if(isset($streams[$key]))<tr><td>{{ $label }}</td><td class="r">{{ number_format((int) ($streams[$key]['count'] ?? 0)) }}</td><td class="r">{{ $fmt($streams[$key]['tax'] ?? 0) }}</td><td class="r">{{ $fmt($streams[$key]['sales'] ?? 0) }}</td></tr>@endif
    @endforeach
    </tbody>
</table>
@endif

@if($report->expected_cash !== null || $report->counted_cash !== null || $report->opening_float !== null)
<div class="section">{{ __('pos.dc_summary_cash_recon') }}</div>
<table class="data">
    @if($report->opening_float !== null)<tr><td>{{ __('pos.dc_summary_opening') }}</td><td class="r">{{ $fmt($report->opening_float) }}</td></tr>@endif
    @if($report->expected_cash !== null)<tr><td>{{ __('pos.dc_summary_expected') }}</td><td class="r">{{ $fmt($report->expected_cash) }}</td></tr>@endif
    @if($report->counted_cash !== null)<tr><td>{{ __('pos.dc_summary_counted') }}</td><td class="r">{{ $fmt($report->counted_cash) }}</td></tr>@endif
    @if($report->cash_variance !== null)<tr><td>{{ __('pos.dc_summary_variance') }}</td><td class="r">{{ $fmt($report->cash_variance) }}</td></tr>@endif
</table>
@endif
<div class="foot">{{ $isX ? __('pos.dc_xreport_hint') : __('pos.dcp_sys_report_pra') }}</div>
</body>
</html>