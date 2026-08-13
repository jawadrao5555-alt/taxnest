<!DOCTYPE html>
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>Sales Analytics {{ $analytics->from }} to {{ $analytics->to }}</title>
    <style>
        @page { margin: 10mm 15mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 11px; color: #000000; line-height: 1.5; background: #fff; }

        .header { background-color: #1e1b4b; padding: 16px 20px; text-align: center; margin-bottom: 14px; }
        .header h1 { font-size: 16px; font-weight: bold; color: #ffffff; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 4px; }
        .header p { font-size: 10px; color: #e5e7eb; }

        .report-title { text-align: center; margin-bottom: 14px; }
        .report-title h2 { font-size: 14px; font-weight: bold; color: #1e1b4b; text-transform: uppercase; letter-spacing: 1px; }
        .report-title p { font-size: 10px; color: #374151; margin-top: 2px; }

        .info-box { border: 2px solid #1e1b4b; padding: 8px 14px; margin-bottom: 14px; }
        .info-row { display: table; width: 100%; }
        .info-row .lbl { display: table-cell; width: 42%; font-size: 10px; font-weight: bold; padding: 3px 0; color: #000000; }
        .info-row .val { display: table-cell; width: 58%; font-size: 10px; text-align: right; padding: 3px 0; color: #000000; font-weight: 700; }

        .section-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #1e1b4b; margin: 12px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #1e1b4b; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.data thead th { font-size: 9px; text-transform: uppercase; padding: 6px 5px; text-align: left; font-weight: bold; color: #ffffff; background-color: #1e1b4b; }
        table.data thead th.r { text-align: right; }
        table.data thead th.c { text-align: center; }
        table.data tbody td { font-size: 10px; padding: 5px 5px; border-bottom: 1px solid #d1d5db; color: #000000; }
        table.data tbody td.r { text-align: right; font-weight: 700; }
        table.data tbody td.c { text-align: center; }
        table.data tbody tr:nth-child(even) { background-color: #f5f3ff; }
        table.data tbody td.sub { font-size: 9px; padding-left: 16px; color: #374151; font-weight: normal; }

        .summary-box { background-color: #1e1b4b; padding: 10px 16px; margin: 10px 0; display: table; width: 100%; }
        .summary-box .lbl { display: table-cell; text-align: left; font-size: 14px; font-weight: bold; color: #ffffff; vertical-align: middle; }
        .summary-box .val { display: table-cell; text-align: right; font-size: 14px; font-weight: bold; color: #ffffff; vertical-align: middle; }

        .footer { margin-top: 14px; text-align: center; padding-top: 8px; border-top: 1.5px solid #9ca3af; }
        .footer p { font-size: 9px; color: #374151; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #1e1b4b; margin-top: 3px; }
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
        @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="report-title">
        <h2>{{ __('pos.ra_report_title') }}</h2>
        <p>{{ __('pos.ra_mode_prefix') }} {{ strtoupper($tab) }} — {{ \Carbon\Carbon::parse($analytics->from)->format('d M Y') }} {{ __('pos.ra_to_word') }} {{ \Carbon\Carbon::parse($analytics->to)->format('d M Y') }}</p>
        <p>{{ __('pos.dcp_generated') }} {{ now()->format('d M Y h:i A') }}</p>
    </div>

    <div class="section-title">{{ __('pos.ra_summary_comparison') }}</div>
    <div class="info-box">
        <div class="info-row">
            <div class="lbl">{{ __('pos.ra_total_revenue') }}</div>
            <div class="val">PKR {{ number_format($analytics->summary->revenue, 2) }}{{ $analytics->previous->revenue_pct !== null ? ' (' . ($analytics->previous->revenue_pct >= 0 ? '+' : '') . $analytics->previous->revenue_pct . __('pos.ra_vs_prev_period') . ')' : '' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.ra_total_bills') }}</div>
            <div class="val">{{ number_format($analytics->summary->bills) }}{{ $analytics->previous->bills_pct !== null ? ' (' . ($analytics->previous->bills_pct >= 0 ? '+' : '') . $analytics->previous->bills_pct . '%)' : '' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.ra_sales_tax') }}</div>
            <div class="val">PKR {{ number_format($analytics->summary->tax, 2) }}{{ $analytics->previous->tax_pct !== null ? ' (' . ($analytics->previous->tax_pct >= 0 ? '+' : '') . $analytics->previous->tax_pct . '%)' : '' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.ra_average_bill') }}</div>
            <div class="val">PKR {{ number_format($analytics->summary->avg_bill, 2) }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.ra_total_discounts') }}</div>
            <div class="val">PKR {{ number_format($analytics->summary->discount, 2) }}</div>
        </div>
        @if(($analytics->wastage ?? null) !== null)
        <div class="info-row">
            <div class="lbl">{{ __('pos.ra_wastage_label') }}</div>
            <div class="val">{{ number_format($analytics->wastage->count) }} / PKR {{ number_format($analytics->wastage->amount, 2) }}</div>
        </div>
        @endif
        <div class="info-row">
            <div class="lbl">{{ __('pos.dcp_unique_customers') }}</div>
            <div class="val">{{ $analytics->summary->unique_customers }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.ra_previous_period') }} ({{ \Carbon\Carbon::parse($analytics->previous->from)->format('d M') }} — {{ \Carbon\Carbon::parse($analytics->previous->to)->format('d M Y') }}):</div>
            <div class="val">PKR {{ number_format($analytics->previous->revenue, 2) }} ({{ $analytics->previous->bills }} {{ __('pos.dc_bills_sfx') }})</div>
        </div>
    </div>

    {{-- Top wasted items (Task 600): same item-wise list the on-screen report shows (Task 597) --}}
    @if((($analytics->wastage ?? null) !== null) && ($analytics->wastage->items ?? collect())->isNotEmpty())
    <div class="section-title">{{ __('pos.ra_wastage_top_items') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="c" style="width:8%;">#</th>
                <th>{{ __('pos.th_product') }}</th>
                <th class="r">{{ __('pos.receipt_qty') }}</th>
                <th class="r">{{ __('pos.amount_word') }} (PKR)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->wastage->items as $i => $wi)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $wi->name }}</td>
                <td class="r">{{ rtrim(rtrim(number_format($wi->qty, 2), '0'), '.') }}</td>
                <td class="r">{{ number_format($wi->amount, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($analytics->profit !== null && ($analytics->profit->cost > 0 || $analytics->profit->revenue > 0))
    <div class="section-title">{{ __('pos.ra_profit_estimate', ['pct' => $analytics->profit->coverage_pct]) }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="r">{{ __('pos.ra_sales_costed_items') }}</th>
                <th class="r">{{ __('pos.ra_cost') }}</th>
                <th class="r">{{ __('pos.ra_gross_profit') }}</th>
                <th class="c">{{ __('pos.ra_margin') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="r">{{ number_format($analytics->profit->revenue, 2) }}</td>
                <td class="r">{{ number_format($analytics->profit->cost, 2) }}</td>
                <td class="r">{{ number_format($analytics->profit->profit, 2) }}</td>
                <td class="c">{{ $analytics->profit->margin_pct !== null ? $analytics->profit->margin_pct . '%' : '-' }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    @if($analytics->profit !== null && $analytics->profit->revenue <= 0 && ($analytics->profit->product_revenue ?? 0) > 0)
    {{-- First-time setup: sales exist but no line has a saved purchase cost --}}
    <div style="border: 1.5px solid #1d4ed8; background-color: #eff6ff; padding: 8px 12px; margin: 0 0 12px;">
        <p style="font-size: 10px; font-weight: bold; color: #1e40af; margin-bottom: 3px;">{{ __('pos.munafa_setup_head') }}</p>
        <p style="font-size: 9px; color: #1e3a8a; margin-bottom: 3px;">{{ __('pos.munafa_setup_body') }}</p>
        <p style="font-size: 9px; color: #1e3a8a;">1. {{ __('pos.pra_munafa_setup_step1') }} &nbsp;2. {{ __('pos.pra_munafa_setup_step2') }} &nbsp;3. {{ __('pos.munafa_setup_step3') }}</p>
    </div>
    @elseif($analytics->profit !== null && ($analytics->profit->unknown_lines ?? 0) > 0)
    {{-- Partial exclusion: some old lines have no cost snapshot --}}
    <div style="border: 1.5px solid #b45309; background-color: #fffbeb; padding: 8px 12px; margin: 0 0 12px;">
        <p style="font-size: 10px; font-weight: bold; color: #92400e; margin-bottom: 3px;">{{ __('pos.munafa_excluded_note', ['lines' => number_format($analytics->profit->unknown_lines), 'amount' => number_format($analytics->profit->unknown_sale_value ?? 0, 0)]) }}</p>
        <p style="font-size: 9px; color: #92400e; margin-bottom: 3px;">{{ __('pos.munafa_excluded_why') }}</p>
        <p style="font-size: 9px; color: #92400e;">{{ __('pos.pra_munafa_excluded_action') }}</p>
    </div>
    @endif

    @if($analytics->categories->isNotEmpty())
    <div class="section-title">{{ __('pos.ra_category_breakdown') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.ra_category_product') }}</th>
                <th class="c">{{ __('pos.receipt_qty') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
                <th class="r">{{ __('pos.dc_th_tax_pkr') }}</th>
                @if($analytics->is_admin_view)<th class="r">{{ __('pos.ra_profit_pkr') }}</th>@endif
                <th class="c">{{ __('pos.dc_th_share') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->categories as $catName => $cat)
            <tr>
                <td style="font-weight:bold;">{{ $catName }}</td>
                <td class="c">{{ rtrim(rtrim(number_format($cat->qty, 2), '0'), '.') }}</td>
                <td class="r">{{ number_format($cat->revenue, 2) }}</td>
                <td class="r">{{ number_format($cat->tax, 2) }}</td>
                @if($analytics->is_admin_view)<td class="r">{{ $cat->profit === null ? '-' : number_format($cat->profit, 2) }}</td>@endif
                <td class="c">{{ $cat->share }}%</td>
            </tr>
            @foreach($cat->products as $pname => $p)
            <tr>
                <td class="sub">— {{ $pname }}</td>
                <td class="c" style="font-weight:normal;">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td>
                <td class="r" style="font-weight:normal;">{{ number_format($p->revenue, 2) }}</td>
                <td></td>
                @if($analytics->is_admin_view)<td></td>@endif
                <td></td>
            </tr>
            @endforeach
            @endforeach
        </tbody>
    </table>
    @endif

    @if($analytics->cashiers->isNotEmpty())
    <div class="section-title">{{ __('pos.dcp_cashier_performance') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dcp_cashier') }}</th>
                <th class="c">{{ __('pos.dcp_bills') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
                <th class="r">{{ __('pos.dc_th_tax_pkr') }}</th>
                <th class="r">{{ __('pos.ra_avg_bill_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->cashiers as $c)
            <tr>
                <td>{{ $c->name }}</td>
                <td class="c">{{ $c->count }}</td>
                <td class="r">{{ number_format($c->revenue, 2) }}</td>
                <td class="r">{{ number_format($c->tax, 2) }}</td>
                <td class="r">{{ number_format($c->avg, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($analytics->waiters->isNotEmpty())
    <div class="section-title">{{ __('pos.ra_sales_by_waiter') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.role_waiter') }}</th>
                <th class="c">{{ __('pos.orders_word') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
                <th class="r">{{ __('pos.ra_avg_bill_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->waiters as $w)
            <tr>
                <td>{{ $w->name }}</td>
                <td class="c">{{ $w->count }}</td>
                <td class="r">{{ number_format($w->revenue, 2) }}</td>
                <td class="r">{{ number_format($w->avg, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($analytics->top_customers->isNotEmpty())
    <div class="section-title">{{ __('pos.ra_top_customers') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="c" style="width:8%;">#</th>
                <th>{{ __('pos.receipt_customer') }}</th>
                <th class="c">{{ __('pos.ra_visits') }}</th>
                <th class="r">{{ __('pos.ra_spent_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->top_customers as $i => $cu)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $cu->name }}</td>
                <td class="c">{{ $cu->count }}</td>
                <td class="r">{{ number_format($cu->revenue, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($analytics->payments->isNotEmpty())
    <div class="section-title">{{ __('pos.ra_payment_split') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dcp_payment_method') }}</th>
                <th class="c">{{ __('pos.dcp_bills') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->payments as $method => $pm)
            <tr>
                <td>{{ ucwords(str_replace('_', ' ', $method)) }}</td>
                <td class="c">{{ $pm->count }}</td>
                <td class="r">{{ number_format($pm->revenue, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="summary-box">
        <div class="lbl">{{ __('pos.ra_total_revenue_tab', ['tab' => strtoupper($tab)]) }}</div>
        <div class="val">PKR {{ number_format($analytics->summary->revenue, 2) }}</div>
    </div>

    <div class="footer">
        <p>{{ __('pos.ra_system_generated') }}</p>
        <div class="brand">{{ __('pos.dcp_powered_nestpos') }}</div>
    </div>
</body>
</html>
