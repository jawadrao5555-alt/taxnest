<!DOCTYPE html>
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>Day Close Report {{ $report->report_number }}</title>
    <style>
        @page { margin: 10mm 15mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 11px; color: #000000; line-height: 1.5; background: #fff; }

        .header { background-color: #1e3a5f; padding: 16px 20px; text-align: center; margin-bottom: 14px; }
        .header h1 { font-size: 16px; font-weight: bold; color: #ffffff; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 4px; }
        .header p { font-size: 10px; color: #e5e7eb; }

        .report-title { text-align: center; margin-bottom: 14px; }
        .report-title h2 { font-size: 14px; font-weight: bold; color: #1e3a5f; text-transform: uppercase; letter-spacing: 1px; }
        .report-title p { font-size: 10px; color: #374151; margin-top: 2px; }

        .info-box { border: 2px solid #1e3a5f; padding: 8px 14px; margin-bottom: 14px; }
        .info-row { display: table; width: 100%; }
        .info-row .lbl { display: table-cell; width: 36%; font-size: 10px; font-weight: bold; padding: 3px 0; color: #000000; }
        .info-row .val { display: table-cell; width: 64%; font-size: 10px; text-align: right; padding: 3px 0; color: #000000; font-weight: 700; }

        .section-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #1e3a5f; margin: 12px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #1e3a5f; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.data thead th { font-size: 9px; text-transform: uppercase; padding: 6px 5px; text-align: left; font-weight: bold; color: #ffffff; background-color: #1e3a5f; }
        table.data thead th.r { text-align: right; }
        table.data thead th.c { text-align: center; }
        table.data tbody td { font-size: 10px; padding: 5px 5px; border-bottom: 1px solid #d1d5db; color: #000000; }
        table.data tbody td.r { text-align: right; font-weight: 700; }
        table.data tbody td.c { text-align: center; }
        table.data tbody tr:nth-child(even) { background-color: #f0f7ff; }
        table.data tfoot td { font-size: 10px; padding: 6px 5px; font-weight: bold; border-top: 2px solid #1e3a5f; color: #000000; }
        table.data tfoot td.r { text-align: right; }

        .summary-box { background-color: #1e3a5f; padding: 10px 16px; margin: 10px 0; display: table; width: 100%; }
        .summary-box .lbl { display: table-cell; text-align: left; font-size: 14px; font-weight: bold; color: #ffffff; vertical-align: middle; }
        .summary-box .val { display: table-cell; text-align: right; font-size: 14px; font-weight: bold; color: #ffffff; vertical-align: middle; }

        .hash-box { margin-top: 10px; padding: 6px; border: 1px solid #d1d5db; text-align: center; }
        .hash-box p { font-size: 7px; color: #374151; word-break: break-all; }
        .hash-box .label { font-size: 8px; font-weight: bold; color: #1e3a5f; margin-bottom: 2px; }

        .footer { margin-top: 14px; text-align: center; padding-top: 8px; border-top: 1.5px solid #9ca3af; }
        .footer p { font-size: 9px; color: #374151; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #1e3a5f; margin-top: 3px; }
    </style>
    @if($pdfUrdu ?? false)
    <style>
        body { font-family: 'XB Riyaz', 'DejaVu Sans', sans-serif; direction: rtl; }
        table.data, .info-box, .info-row { direction: rtl; }
        .section-title { text-transform: none; letter-spacing: 0; }
        table.data thead th { text-transform: none; letter-spacing: 0; }
        .header h1 { text-transform: none; letter-spacing: 0; }
        .report-title h2 { text-transform: none; letter-spacing: 0; }
    </style>
    @endif
</head>
<body>
    <div class="header">
        <h1>{{ $company->name }}</h1>
        @if($company->address)<p>{{ $company->address }}</p>@endif
        @if($company->phone)<p>{{ __('pos.rcpt_tel') }} {{ $company->phone }}</p>@endif
        @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="report-title">
        <h2>{{ __('pos.dcp_report_title') }}</h2>
        <p>{{ __('pos.dcp_fbr_eod_summary') }}</p>
        <p style="font-weight:bold; margin-top:4px;">{{ $report->report_date->format('l, d F Y') }}</p>
    </div>

    <div class="info-box">
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_report_no') }}:</div>
            <div class="val">{{ $report->report_number }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_date') }}:</div>
            <div class="val">{{ $report->report_date->format('d/m/Y') }}</div>
        </div>
        @if($company->fbr_pos_id)
        <div class="info-row">
            <div class="lbl">{{ __('pos.rcpt_pos_registration_hash') }}:</div>
            <div class="val">{{ $company->fbr_pos_id }}</div>
        </div>
        @endif
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_generated') }}:</div>
            <div class="val">{{ $report->created_at->format('d/m/Y h:i A') }}</div>
        </div>
    </div>

    <div class="section-title">{{ __('pos.dcp_invoice_summary') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dc_th_category') }}</th>
                <th class="c">{{ __('pos.dc_th_count') }}</th>
                <th class="r">{{ __('pos.dc_th_amount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('pos.dcp_fbr_submitted_invoices') }}</td>
                <td class="c">{{ $report->fbr_invoices }}</td>
                <td class="r">-</td>
            </tr>
            <tr>
                <td>{{ __('pos.dcp_local_invoices') }}</td>
                <td class="c">{{ $report->local_invoices }}</td>
                <td class="r">-</td>
            </tr>
            @if($report->failed_invoices > 0)
            <tr>
                <td>{{ __('pos.dcp_failed_pending_invoices') }}</td>
                <td class="c">{{ $report->failed_invoices }}</td>
                <td class="r">-</td>
            </tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('pos.dc_total_invoices') }}</td>
                <td class="c" style="font-weight:bold;">{{ $report->total_invoices }}</td>
                <td class="r">-</td>
            </tr>
        </tfoot>
    </table>

    <div class="section-title">{{ __('pos.dcp_financial_summary') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:60%;">{{ __('pos.dcp_description') }}</th>
                <th class="r" style="width:40%;">{{ __('pos.dc_th_amount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('pos.dc_gross_sales') }}</td>
                <td class="r">{{ number_format($report->gross_sales, 2) }}</td>
            </tr>
            @if($report->total_discount > 0)
            <tr>
                <td>{{ __('pos.dc_discount') }}</td>
                <td class="r" style="color:#dc2626;">-{{ number_format($report->total_discount, 2) }}</td>
            </tr>
            @endif
            <tr>
                <td>{{ __('pos.dc_net_sales') }}</td>
                <td class="r">{{ number_format($report->net_sales, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('pos.dcp_sales_tax_collected') }}</td>
                <td class="r">{{ number_format($report->total_tax, 2) }}</td>
            </tr>
            @if($report->total_fbr_fee > 0)
            <tr>
                <td>{{ __('pos.dcp_fbr_pos_fee_sro') }}</td>
                <td class="r">{{ number_format($report->total_fbr_fee, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="summary-box">
        <div class="lbl">{{ __('pos.dc_total_revenue') }}</div>
        <div class="val">PKR {{ number_format($report->total_amount, 2) }}</div>
    </div>

    <div class="section-title">{{ __('pos.dcp_payment_method_breakdown') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:60%;">{{ __('pos.dcp_payment_method') }}</th>
                <th class="r" style="width:40%;">{{ __('pos.dc_th_amount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('pos.dc_cash') }}</td>
                <td class="r">{{ number_format($report->cash_amount, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('pos.dc_card') }}</td>
                <td class="r">{{ number_format($report->card_amount, 2) }}</td>
            </tr>
            @if($report->other_amount > 0)
            <tr>
                <td>{{ __('pos.dc_other') }}</td>
                <td class="r">{{ number_format($report->other_amount, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    @if($report->counted_cash !== null)
    <div class="section-title">{{ __('pos.dc_cash_recon') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="r">{{ __('pos.dc_opening_float') }}</th>
                <th class="r">{{ __('pos.dc_cash_sales') }}</th>
                <th class="r">{{ __('pos.dc_expected_drawer') }}</th>
                <th class="r">{{ __('pos.dc_counted_cash') }}</th>
                <th class="r">{{ __('pos.dc_variance') }}</th>
            </tr>
        </thead>
        <tbody>
            @php $pdfVariance = (float) $report->cash_variance; @endphp
            <tr>
                <td class="r">{{ number_format($report->opening_float ?? 0, 2) }}</td>
                <td class="r">{{ number_format($report->cash_amount, 2) }}</td>
                <td class="r">{{ number_format($report->expected_cash ?? 0, 2) }}</td>
                <td class="r">{{ number_format($report->counted_cash, 2) }}</td>
                <td class="r" style="{{ abs($pdfVariance) < 0.01 ? 'color:#059669;' : ($pdfVariance < 0 ? 'color:#dc2626;' : 'color:#d97706;') }}">{{ $pdfVariance > 0 ? '+' : '' }}{{ number_format($pdfVariance, 2) }} {{ abs($pdfVariance) < 0.01 ? __('pos.dc_balanced') : ($pdfVariance < 0 ? __('pos.dc_short') : __('pos.dc_over')) }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    @if($cashierBreakdown->isNotEmpty())
    <div class="section-title">{{ __('pos.dcp_cashier_performance') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dcp_cashier') }}</th>
                <th class="c">{{ __('pos.dcp_sales') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
                <th class="r">{{ __('pos.dc_th_tax_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cashierBreakdown as $name => $data)
            <tr>
                <td>{{ $name }}</td>
                <td class="c">{{ $data->count }}</td>
                <td class="r">{{ number_format($data->revenue, 2) }}</td>
                <td class="r">{{ number_format($data->tax, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($analytics->top_products->isNotEmpty())
    <div class="section-title">{{ __('pos.dc_top_products') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="c" style="width:8%;">#</th>
                <th>{{ __('pos.receipt_item') }}</th>
                <th class="c">{{ __('pos.receipt_qty') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
                <th class="c">{{ __('pos.dc_th_share') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->top_products as $pname => $p)
            <tr>
                <td class="c">{{ $loop->iteration }}</td>
                <td>{{ $pname }}</td>
                <td class="c">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td>
                <td class="r">{{ number_format($p->revenue, 2) }}</td>
                <td class="c">{{ $p->share }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="section-title">{{ __('pos.dcp_fbr_submission_health') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="c">{{ __('pos.dc_submitted') }}</th>
                <th class="c">{{ __('pos.dc_pending') }}</th>
                <th class="c">{{ __('pos.dc_failed') }}</th>
                <th class="c">{{ __('pos.dc_local') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="c">{{ $analytics->fbr_health->submitted }}</td>
                <td class="c">{{ $analytics->fbr_health->pending }}</td>
                <td class="c">{{ $analytics->fbr_health->failed }}</td>
                <td class="c">{{ $analytics->fbr_health->local }}</td>
            </tr>
        </tbody>
    </table>

    @if($analytics->discounts->total > 0)
    <div class="section-title">{{ __('pos.dcp_discount_summary') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="c">{{ __('pos.dcp_bills_with_discount') }}</th>
                <th class="r">{{ __('pos.dcp_bill_level_pkr') }}</th>
                <th class="r">{{ __('pos.dcp_item_level_pkr') }}</th>
                <th class="r">{{ __('pos.dcp_total_discount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="c">{{ $analytics->discounts->bill_count }}</td>
                <td class="r">{{ number_format($analytics->discounts->bill_total, 2) }}</td>
                <td class="r">{{ number_format($analytics->discounts->item_total, 2) }}</td>
                <td class="r">{{ number_format($analytics->discounts->total, 2) }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    <div class="section-title">{{ __('pos.dcp_day_stats_comparison') }}</div>
    <div class="info-box">
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_avg_bill') }}:</div>
            <div class="val">PKR {{ number_format($analytics->avg_bill, 2) }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_unique_customers') }} {{ __('pos.dcp_named') }}:</div>
            <div class="val">{{ $analytics->unique_customers }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_vs_yesterday') }} ({{ \Carbon\Carbon::parse($analytics->comparison->yesterday->date)->format('d M') }}):</div>
            <div class="val">PKR {{ number_format($analytics->comparison->yesterday->revenue, 2) }} ({{ $analytics->comparison->yesterday->invoices }} {{ __('pos.dcp_bills_word') }}){{ $analytics->comparison->vs_yesterday_revenue_pct !== null ? ' — ' . ($analytics->comparison->vs_yesterday_revenue_pct >= 0 ? '+' : '') . $analytics->comparison->vs_yesterday_revenue_pct . '% ' . __('pos.dcp_today_word') : '' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_vs_last_week') }} ({{ \Carbon\Carbon::parse($analytics->comparison->last_week->date)->format('d M') }}):</div>
            <div class="val">PKR {{ number_format($analytics->comparison->last_week->revenue, 2) }} ({{ $analytics->comparison->last_week->invoices }} {{ __('pos.dcp_bills_word') }}){{ $analytics->comparison->vs_last_week_revenue_pct !== null ? ' — ' . ($analytics->comparison->vs_last_week_revenue_pct >= 0 ? '+' : '') . $analytics->comparison->vs_last_week_revenue_pct . '% ' . __('pos.dcp_today_word') : '' }}</div>
        </div>
    </div>

    <div class="section-title">{{ __('pos.dc_invoice_range') }}</div>
    <div class="info-box">
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_first_inv') }}:</div>
            <div class="val">{{ $report->first_invoice_number ?? '-' }} @ {{ $report->first_invoice_time ? $report->first_invoice_time->format('h:i A') : '-' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_last_inv') }}:</div>
            <div class="val">{{ $report->last_invoice_number ?? '-' }} @ {{ $report->last_invoice_time ? $report->last_invoice_time->format('h:i A') : '-' }}</div>
        </div>
    </div>

    @if($report->notes)
    <div class="section-title">{{ __('pos.receipt_notes') }}</div>
    <p style="font-size:10px; color:#374151; padding:4px 0;">{{ $report->notes }}</p>
    @endif

    <div class="hash-box">
        <div class="label">{{ __('pos.dcp_integrity_hash') }}</div>
        <p>{{ $report->hash }}</p>
    </div>

    <div class="footer">
        <p>{{ __('pos.dcp_sys_report_fbr') }}</p>
        <div class="brand">{{ __('pos.dcp_powered_taxnest_fbr') }}</div>
        <p>{{ __('pos.dc_generated') }}: {{ $report->created_at->format('d/m/Y h:i:s A') }}</p>
    </div>
</body>
</html>
