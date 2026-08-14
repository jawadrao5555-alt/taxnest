<!DOCTYPE html>
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pos.tax_reports') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 9px; color: #1a1a1a; line-height: 1.4; }
        .header { padding: 15px 20px; border-bottom: 3px solid #2563eb; margin-bottom: 10px; }
        .company-name { font-size: 18px; font-weight: bold; color: #2563eb; margin-bottom: 2px; }
        .report-title { font-size: 13px; font-weight: bold; color: #374151; margin-bottom: 4px; }
        .report-meta { font-size: 9px; color: #6b7280; }
        .report-meta span { margin-right: 15px; }
        .content { padding: 0 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th { background-color: #eff6ff; color: #1e3a8a; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; padding: 6px 4px; text-align: left; border-bottom: 2px solid #2563eb; }
        th.right, td.right { text-align: right; }
        th.center, td.center { text-align: center; }
        td { padding: 5px 4px; border-bottom: 1px solid #e5e7eb; font-size: 8.5px; }
        tr:nth-child(even) { background-color: #fafafa; }
        .section-title { font-size: 11px; font-weight: bold; color: #2563eb; margin: 12px 0 6px; }
        .cn-line { border: 1px solid #fda4af; background: #fff1f2; border-radius: 5px; padding: 7px 10px; margin: 8px 0 4px; font-size: 8.5px; color: #9f1239; }
        .cn-line b { margin-right: 12px; }
        .summary-box { border: 2px solid #2563eb; border-radius: 6px; padding: 12px; margin-top: 4px; page-break-inside: avoid; }
        .summary-grid { display: table; width: 100%; }
        .summary-item { display: table-cell; text-align: center; padding: 5px; }
        .summary-label { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.3px; }
        .summary-value { font-size: 13px; font-weight: bold; color: #1a1a1a; margin-top: 2px; }
        .summary-value.blue { color: #2563eb; }
        .footer { text-align: center; color: #9ca3af; font-size: 7.5px; margin-top: 15px; padding: 10px 20px; border-top: 1px solid #e5e7eb; }
    </style>
    @if($pdfUrdu ?? false)
    <style>
        body { font-family: 'XB Riyaz', 'DejaVu Sans', sans-serif; direction: rtl; }
        table { direction: rtl; }
        th { text-transform: none; letter-spacing: 0; }
        .summary-label { text-transform: none; letter-spacing: 0; }
    </style>
    @endif
</head>
<body>
    <div class="header">
        <div class="company-name">{{ $company->company_name ?? $company->name ?? 'Company' }}</div>
        <div class="report-title">{{ __('pos.tax_reports') }} &mdash; {{ __('pos.month_fbr_tax_summary', ['month' => now()->format('F Y')]) }}{{ ($billTypeFilter ?? '') === 'returns' ? ' — ' . __('pos.opt_credit_notes_only') : (($billTypeFilter ?? '') === 'sales' ? ' — ' . __('pos.opt_sales_only') : '') }}</div>
        <div class="report-meta">
            <span>{{ __('pos.dcp_generated') }} {{ now()->format('d M Y, h:i A') }}</span>
            <span>NTN: {{ $company->ntn ?? 'N/A' }}</span>
        </div>
    </div>

    <div class="content">
        <div class="summary-box">
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.total_sales_excl_tax') }}</div>
                    <div class="summary-value">PKR {{ number_format($monthlyTax->total_sales ?? 0, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.total_tax_collected') }}</div>
                    <div class="summary-value blue">PKR {{ number_format($monthlyTax->total_tax ?? 0, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.fbr_pos_fee_collected') }}</div>
                    <div class="summary-value">PKR {{ number_format($monthlyTax->total_pos_fee ?? 0, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.total_invoices') }}</div>
                    <div class="summary-value">{{ number_format($monthlyTax->invoice_count ?? 0) }}</div>
                </div>
            </div>
        </div>

        {{-- Credit-note summary line (Task 698 — mirrors the screen's Task-695
             line): refunds are never hidden — netted figures already subtract them. --}}
        @if(($billTypeReady ?? false) && ((($billTypeFilter ?? '') === 'returns') || ($monthlyTax->return_count ?? 0) > 0))
        <div class="cn-line">
            <b>{{ __('pos.tr_credit_notes') }}: {{ number_format($monthlyTax->return_count ?? 0) }}</b>
            <b>{{ __('pos.tr_refunded_amount') }}: PKR {{ number_format($monthlyTax->return_amount ?? 0, 2) }}</b>
            <b>{{ __('pos.tr_tax_reversed') }}: PKR {{ number_format($monthlyTax->return_tax ?? 0, 2) }}</b>
            {{ ($billTypeFilter ?? '') === 'returns' ? __('pos.tr_cn_only_note') : __('pos.tr_cn_netted_note') }}
        </div>
        @endif

        <div class="section-title">{{ __('pos.fbr_submission_status') }}</div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('pos.fbr_submitted') }}</th>
                    <th>{{ __('pos.pending_word') }}</th>
                    <th>{{ __('pos.failed_word') }}</th>
                    <th>{{ __('pos.local_offline') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ number_format($fbrStats->submitted ?? 0) }}</td>
                    <td>{{ number_format($fbrStats->pending ?? 0) }}</td>
                    <td>{{ number_format($fbrStats->failed ?? 0) }}</td>
                    <td>{{ number_format($fbrStats->local_count ?? 0) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="section-title">{{ __('pos.tax_breakdown_by_rate') }}</div>
        <table>
            <thead>
                <tr>
                    <th>{{ __('pos.tax_rate_col') }}</th>
                    <th class="center">{{ __('pos.invoices_word') }}</th>
                    <th class="right">{{ __('pos.sales_word') }}</th>
                    <th class="right">{{ __('pos.tax_word') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($taxByRate as $rate)
                <tr>
                    <td style="font-weight:bold;">{{ number_format($rate->tax_rate, 0) }}%</td>
                    <td class="center">{{ number_format($rate->count) }}</td>
                    <td class="right">PKR {{ number_format($rate->sales_total, 2) }}</td>
                    <td class="right" style="font-weight:bold;color:#2563eb;">PKR {{ number_format($rate->tax_total, 2) }}</td>
                </tr>
                @empty
                <tr><td colspan="4" style="text-align:center;color:#9ca3af;padding:12px;">{{ __('pos.no_tax_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="footer">
        {{ __('pos.tr_footer') }} &mdash; {{ $company->company_name ?? $company->name ?? 'Company' }} &mdash; {{ now()->format('d M Y, h:i A') }}
    </div>
</body>
</html>
