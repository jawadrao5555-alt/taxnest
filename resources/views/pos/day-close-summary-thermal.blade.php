<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ ($isXReport ?? false) ? __('pos.dc_summary_x') : __('pos.dc_summary_z') }} {{ $report->report_number }}</title>
    <style>
        @page { margin: 3mm; }
        body { width: auto; max-width: 72mm; margin: 0 auto; font-family: Arial, sans-serif; font-size: 10px; color: #000; }
        .c { text-align: center; } .r { text-align: right; } .head { font-weight: bold; font-size: 13px; } .muted { font-size: 8px; color: #444; }
        .notice { border: 1px solid #0369a1; padding: 4px; margin: 6px 0; font-weight: bold; text-align: center; }
        .sec { font-weight: bold; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 4px 0; margin-top: 7px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; } td { padding: 2px 0; vertical-align: top; } .total td { font-weight: bold; font-size: 11px; }
        @media print { body { width: auto; max-width: 72mm; } }
    </style>
    @if(app()->getLocale() === 'ur')<style>body, table { font-family: 'Jameel Noori Nastaleeq', 'XB Riyaz', Arial, sans-serif; direction: rtl; } .sec { text-transform: none; }</style>@endif
</head>
<body>
@php
    $isX = (bool) ($isXReport ?? false);
    $fmt = fn ($n) => number_format((float) $n, 2);
    $streams = is_array($streamSplit ?? null) ? $streamSplit : [];
@endphp
<div class="c head">{{ $isX ? __('pos.dc_summary_x') : __('pos.dc_summary_z') }}</div>
<div class="c">{{ $company->name ?? '' }}</div>
<div class="c muted">{{ \Carbon\Carbon::parse($report->report_date)->format('d M Y') }} · {{ $report->report_number }}</div>
@if($isX)<div class="notice">{{ __('pos.dc_summary_provisional') }}</div>@endif

<div class="sec">{{ __('pos.dc_summary_overview') }}</div>
<table>
<tr><td>{{ __('pos.dc_summary_bills') }}</td><td class="r">{{ number_format((int) ($report->total_invoices ?? 0)) }}</td></tr>
<tr><td>{{ __('pos.dc_summary_gross') }}</td><td class="r">{{ $fmt($report->gross_sales ?? 0) }}</td></tr>
<tr><td>{{ __('pos.dc_summary_discount') }}</td><td class="r">{{ $fmt($report->total_discount ?? 0) }}</td></tr>
<tr><td>{{ __('pos.dc_summary_net') }}</td><td class="r">{{ $fmt($report->net_sales ?? 0) }}</td></tr>
<tr><td>{{ __('pos.dc_summary_tax') }}</td><td class="r">{{ $fmt($report->total_tax ?? 0) }}</td></tr>
@if((int) ($report->returns_count ?? 0) > 0)<tr><td>{{ __('pos.dc_summary_returns', ['count' => $report->returns_count]) }}</td><td class="r">-{{ $fmt($report->returns_amount ?? 0) }}</td></tr>@endif
<tr class="total"><td>{{ __('pos.dc_summary_total') }}</td><td class="r">{{ $fmt($report->total_amount ?? 0) }}</td></tr>
</table>

<div class="sec">{{ __('pos.dc_summary_payments') }}</div>
<table>
<tr><td>{{ __('pos.dc_summary_cash') }}</td><td class="r">{{ $fmt($report->cash_amount ?? 0) }}</td></tr>
<tr><td>{{ __('pos.dc_summary_card') }}</td><td class="r">{{ $fmt($report->card_amount ?? 0) }}</td></tr>
<tr><td>{{ __('pos.dc_summary_other') }}</td><td class="r">{{ $fmt($report->other_amount ?? 0) }}</td></tr>
</table>

@if(!empty($streams['pra']) || !empty($streams['local']))
<div class="sec">{{ __('pos.dc_summary_streams') }}</div>
<table>
@foreach(['pra' => __('pos.dc_summary_pra'), 'local' => __('pos.dc_summary_local')] as $key => $label)
@if(isset($streams[$key]))<tr><td>{{ $label }} ({{ number_format((int) ($streams[$key]['count'] ?? 0)) }})</td><td class="r">{{ $fmt($streams[$key]['sales'] ?? 0) }}</td></tr>@endif
@endforeach
</table>
@endif

@if($report->expected_cash !== null || $report->counted_cash !== null || $report->opening_float !== null)
<div class="sec">{{ __('pos.dc_summary_cash_recon') }}</div>
<table>
@if($report->opening_float !== null)<tr><td>{{ __('pos.dc_summary_opening') }}</td><td class="r">{{ $fmt($report->opening_float) }}</td></tr>@endif
@if($report->expected_cash !== null)<tr><td>{{ __('pos.dc_summary_expected') }}</td><td class="r">{{ $fmt($report->expected_cash) }}</td></tr>@endif
@if($report->counted_cash !== null)<tr><td>{{ __('pos.dc_summary_counted') }}</td><td class="r">{{ $fmt($report->counted_cash) }}</td></tr>@endif
@if($report->cash_variance !== null)<tr class="total"><td>{{ __('pos.dc_summary_variance') }}</td><td class="r">{{ $fmt($report->cash_variance) }}</td></tr>@endif
</table>
@endif
<p class="c muted" style="margin-top:8px">{{ $isX ? __('pos.dc_xreport_hint') : __('pos.dcp_sys_report_pra') }}</p>
</body>
</html>