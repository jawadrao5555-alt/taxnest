<!DOCTYPE html>
@php $urduScript = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<html lang="{{ $urduScript ? 'ur' : 'en' }}" dir="{{ $urduScript ? 'rtl' : 'ltr' }}" data-pos-locale="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8">
<title>{{ $isXReport ?? false ? __('pos.dc_summary_xreport') : __('pos.dc_summary_zreport') }} {{ $report->report_number }}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
html,body { background:#f3f4f6; font-family:'Courier New',Courier,monospace; color:#000; }
.toolbar { max-width:340px; margin:10px auto 0; display:flex; gap:8px; }
.toolbar button,.toolbar a { flex:1; padding:10px; font-size:13px; font-weight:bold; border:0; border-radius:8px; cursor:pointer; text-align:center; text-decoration:none; font-family:inherit; }
.btn-print { background:#0A4D5C; color:#fff; } .btn-back { background:#e5e7eb; color:#111; }
.receipt { width:302px; margin:10px auto 30px; background:#fff; padding:10px 8px; font-size:11px; line-height:1.45; }
.c{text-align:center}.b{font-weight:bold}.lg{font-size:13px}.xl{font-size:15px}.sm{font-size:9px}.hr{border-top:1px dashed #000;margin:5px 0}.hr2{border-top:2px solid #000;margin:5px 0}
table{width:100%;border-collapse:collapse}td{padding:2px 0;font-size:11px;vertical-align:top}td.r{text-align:right;white-space:nowrap}.sec{font-weight:bold;text-transform:uppercase;font-size:10px;letter-spacing:1px;margin-top:4px}
@media print { html,body{background:#fff}.toolbar{display:none}.receipt{width:100%;max-width:80mm;margin:0;padding:0 2mm}@page{margin:3mm;size:80mm auto} }
@if($urduScript)
@include('partials.urdu-print-font')
html,body{font-family:'Jameel Noori Nastaleeq','Noto Naskh Arabic','Urdu Typesetting','Courier New',Courier,monospace}.receipt{line-height:1.9}.sec{text-transform:none;letter-spacing:0}
@endif
</style>
</head>
<body>
<div class="toolbar"><button class="btn-print" onclick="window.print()">🖨 {{ __('pos.dc_summary_print') }}</button><a class="btn-back" href="{{ route('pos.day-close', ['date' => $report->report_date->format('Y-m-d')]) }}">{{ __('pos.receipt_back') }}</a></div>
<div class="receipt">
    <div class="c b lg">{{ $company->name }}</div>
    <div class="hr2"></div>
    <div class="c b xl">{{ $isXReport ?? false ? __('pos.dc_summary_xreport') : __('pos.dc_summary_zreport') }}</div>
    <div class="c">{{ $report->report_number }}</div>
    <div class="c">{{ $report->report_date->format('l, d M Y') }}</div>
    @if($isXReport ?? false)
    <div data-report-state="provisional" style="border:2px solid #000;padding:3px;text-align:center;font-weight:bold;margin:5px 0;">{{ __('pos.dc_provisional_watermark') }}</div>
    @else
    <div data-report-state="frozen" style="border:2px solid #166534;padding:3px;text-align:center;font-weight:bold;margin:5px 0;">{{ __('pos.dc_summary_frozen') }}</div>
    @endif
    <div class="hr2"></div>
    <div class="sec">{{ __('pos.dc_summary_totals') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_total_invoices') }}</td><td class="r b">{{ $summary['invoice_count'] }}</td></tr>
        <tr><td>{{ __('pos.dc_gross_sales') }}</td><td class="r">{{ number_format($summary['gross_sales'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_discount') }}</td><td class="r">-{{ number_format($summary['discount'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_net_sales') }}</td><td class="r">{{ number_format($summary['net_sales'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_sales_tax') }}</td><td class="r">{{ number_format($summary['tax'], 2) }}</td></tr>
        <tr><td class="b lg">{{ __('pos.dc_total_revenue') }}</td><td class="r b lg">{{ number_format($summary['total'], 2) }}</td></tr>
    </table><div class="hr"></div>
    <div class="sec">{{ __('pos.dc_summary_payments') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_cash') }}</td><td class="r">{{ number_format($summary['payments']['cash'] ?? 0, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_card') }}</td><td class="r">{{ number_format($summary['payments']['card'] ?? 0, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_summary_online') }}</td><td class="r">{{ number_format($summary['payments']['online'] ?? 0, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_other') }}</td><td class="r">{{ number_format($summary['payments']['other'] ?? 0, 2) }}</td></tr>
    </table><div class="hr"></div>
    <div class="sec">{{ __('pos.dc_summary_streams') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_stream_pra') }} ({{ $summary['pra_invoices'] ?? 0 }})</td><td class="r">{{ number_format($summary['pra']['sales'] ?? $summary['total'], 2) }}</td></tr>
        @if($summary['show_local'] ?? false)<tr><td>{{ __('pos.dc_stream_local') }} ({{ $summary['local_invoices'] ?? 0 }})</td><td class="r">{{ number_format($summary['local']['sales'] ?? 0, 2) }}</td></tr>@endif
    </table>
    @if(($summary['returns_count'] ?? 0) > 0)<div class="hr"></div><div class="sec">{{ __('pos.dc_summary_returns') }}</div><table><tr><td>{{ __('pos.dc_summary_return_count') }}</td><td class="r">{{ $summary['returns_count'] }}</td></tr><tr><td>{{ __('pos.dc_summary_refund_amount') }}</td><td class="r">-{{ number_format($summary['returns_amount'], 2) }}</td></tr></table>@endif
    @if(($summary['cash_recon']['visible'] ?? false))
    <div class="hr"></div><div class="sec">{{ __('pos.dc_summary_cash_recon') }}</div><table>
        <tr><td>{{ __('pos.dc_opening_float') }}</td><td class="r">{{ $summary['cash_recon']['opening'] === null ? '-' : number_format($summary['cash_recon']['opening'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dcp_expected_cash_drawer') }}</td><td class="r">{{ $summary['cash_recon']['expected'] === null ? '-' : number_format($summary['cash_recon']['expected'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_counted_cash') }}</td><td class="r">{{ $summary['cash_recon']['counted'] === null ? '-' : number_format($summary['cash_recon']['counted'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_variance') }}</td><td class="r">{{ $summary['cash_recon']['variance'] === null ? '-' : number_format($summary['cash_recon']['variance'], 2) }}</td></tr>
    </table>
    @endif
    <div class="hr2"></div><div class="c sm b">{{ __('pos.dcp_powered_nestpos') }}</div>
</div>
</body>
</html>