<!DOCTYPE html>
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $isXReport ?? false ? 'Summary X-Report' : 'Summary Z-Report' }} {{ $report->report_number }}</title>
    <style>
        @page { margin: 12mm 15mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #111827; }
        .header { background: #1e1b4b; color: #fff; padding: 14px 18px; text-align: center; }
        .header h1 { margin: 0 0 3px; font-size: 16px; }
        .header p { margin: 2px 0; font-size: 9px; color: #e5e7eb; }
        .title { text-align: center; margin: 14px 0; }
        .title h2 { margin: 0; color: #1e1b4b; font-size: 15px; }
        .title p { margin: 3px 0; font-size: 10px; }
        .watermark { border: 2px solid #dc2626; color: #dc2626; background: #fef2f2; padding: 7px; text-align: center; font-weight: bold; margin-bottom: 12px; }
        .frozen-state { border: 2px solid #166534; color: #166534; background: #f0fdf4; padding: 7px; text-align: center; font-weight: bold; margin-bottom: 12px; }
        .hero { background: #1e1b4b; color: #fff; padding: 10px 14px; margin-bottom: 12px; }
        .hero table, table { width: 100%; border-collapse: collapse; }
        .hero td:last-child, td.amount { text-align: right; font-weight: bold; }
        .hero td { padding: 2px 0; }
        h3 { color: #1e1b4b; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid #1e1b4b; padding-bottom: 3px; margin: 13px 0 5px; }
        td { padding: 6px 5px; border-bottom: 1px solid #d1d5db; }
        td.amount { white-space: nowrap; }
        .grid td { width: 50%; border: 1px solid #d1d5db; }
        .negative { color: #dc2626; }
        .footer { margin-top: 18px; text-align: center; border-top: 1px solid #9ca3af; padding-top: 7px; font-size: 9px; color: #4b5563; }
        @if($pdfUrdu ?? false)
        body { font-family: 'Jameel Noori Nastaleeq', 'XB Riyaz', 'DejaVu Sans', sans-serif; direction: rtl; }
        h3 { text-transform: none; letter-spacing: 0; }
        @endif
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $company->name }}</h1>
        @if($company->address)<p>{{ $company->address }}</p>@endif
        @if($company->phone)<p>{{ __('pos.rcpt_tel') }} {{ $company->phone }}</p>@endif
    </div>
    <div class="title">
        <h2>{{ $isXReport ?? false ? __('pos.dc_summary_xreport') : __('pos.dc_summary_zreport') }}</h2>
        <p>{{ $report->report_date->format('l, d F Y') }} · {{ $report->report_number }}</p>
    </div>
    @if($isXReport ?? false)
    <div class="watermark" data-report-state="provisional">{{ __('pos.dc_provisional_watermark') }}<br><span style="font-size:9px;">{{ __('pos.dc_summary_x_hint') }}</span></div>
    @else
    <div class="frozen-state" data-report-state="frozen">{{ __('pos.dc_summary_frozen') }}</div>
    @endif

    <div class="hero">
        <table>
            <tr><td>{{ __('pos.dc_total_invoices') }}</td><td>{{ $summary['invoice_count'] }}</td></tr>
            <tr><td>{{ __('pos.dc_total_revenue') }}</td><td>PKR {{ number_format($summary['total'], 2) }}</td></tr>
        </table>
    </div>

    <h3>{{ __('pos.dc_summary_totals') }}</h3>
    <table>
        <tr><td>{{ __('pos.dc_gross_sales') }}</td><td class="amount">PKR {{ number_format($summary['gross_sales'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_discount') }}</td><td class="amount negative">PKR {{ number_format($summary['discount'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_net_sales') }}</td><td class="amount">PKR {{ number_format($summary['net_sales'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_sales_tax') }}</td><td class="amount">PKR {{ number_format($summary['tax'], 2) }}</td></tr>
        <tr><td><strong>{{ __('pos.dc_total_revenue') }}</strong></td><td class="amount"><strong>PKR {{ number_format($summary['total'], 2) }}</strong></td></tr>
    </table>

    <h3>{{ __('pos.dc_summary_payments') }}</h3>
    <table class="grid">
        <tr>
            <td>{{ __('pos.dc_cash') }} <span style="float:right;font-weight:bold;">PKR {{ number_format($summary['payments']['cash'] ?? 0, 2) }}</span></td>
            <td>{{ __('pos.dc_card') }} <span style="float:right;font-weight:bold;">PKR {{ number_format($summary['payments']['card'] ?? 0, 2) }}</span></td>
        </tr>
        <tr>
            <td>{{ __('pos.dc_summary_online') }} <span style="float:right;font-weight:bold;">PKR {{ number_format($summary['payments']['online'] ?? 0, 2) }}</span></td>
            <td>{{ __('pos.dc_other') }} <span style="float:right;font-weight:bold;">PKR {{ number_format($summary['payments']['other'] ?? 0, 2) }}</span></td>
        </tr>
    </table>

    <h3>{{ __('pos.dc_summary_streams') }}</h3>
    <table>
        <tr><td>{{ __('pos.dc_stream_pra') }} ({{ $summary['pra_invoices'] ?? 0 }})</td><td class="amount">PKR {{ number_format($summary['pra']['sales'] ?? $summary['total'], 2) }}</td></tr>
        @if($summary['show_local'] ?? false)
        <tr><td>{{ __('pos.dc_stream_local') }} ({{ $summary['local_invoices'] ?? 0 }})</td><td class="amount">PKR {{ number_format($summary['local']['sales'] ?? 0, 2) }}</td></tr>
        @endif
    </table>

    @if(($summary['returns_count'] ?? 0) > 0)
    <h3>{{ __('pos.dc_summary_returns') }}</h3>
    <table><tr><td>{{ __('pos.dc_summary_return_count') }}</td><td class="amount">{{ $summary['returns_count'] }}</td></tr><tr><td>{{ __('pos.dc_summary_refund_amount') }}</td><td class="amount negative">PKR {{ number_format($summary['returns_amount'], 2) }}</td></tr></table>
    @endif

    @if(($summary['cash_recon']['visible'] ?? false))
    <h3>{{ __('pos.dc_summary_cash_recon') }}</h3>
    <table>
        <tr><td>{{ __('pos.dc_opening_float') }}</td><td class="amount">PKR {{ $summary['cash_recon']['opening'] === null ? '—' : number_format($summary['cash_recon']['opening'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dcp_expected_cash_drawer') }}</td><td class="amount">PKR {{ $summary['cash_recon']['expected'] === null ? '—' : number_format($summary['cash_recon']['expected'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_counted_cash') }}</td><td class="amount">PKR {{ $summary['cash_recon']['counted'] === null ? '—' : number_format($summary['cash_recon']['counted'], 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_variance') }}</td><td class="amount">{{ $summary['cash_recon']['variance'] === null ? '—' : 'PKR ' . number_format($summary['cash_recon']['variance'], 2) }}</td></tr>
    </table>
    @endif
    <div class="footer">{{ __('pos.dcp_powered_nestpos') }} · {{ __('pos.dc_summary_report_title') }}</div>
</body>
</html>