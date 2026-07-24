<!DOCTYPE html>
<html lang="en">
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
</head>
<body>
    <div class="header">
        <h1>{{ $company->name }}</h1>
        @if($company->address)<p>{{ $company->address }}</p>@endif
        @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="report-title">
        <h2>Sales Analytics Report</h2>
        <p>Mode: {{ strtoupper($tab) }} — {{ \Carbon\Carbon::parse($analytics->from)->format('d M Y') }} to {{ \Carbon\Carbon::parse($analytics->to)->format('d M Y') }}</p>
        <p>Generated: {{ now()->format('d M Y h:i A') }}</p>
    </div>

    <div class="section-title">Summary &amp; Previous-Period Comparison</div>
    <div class="info-box">
        <div class="info-row">
            <div class="lbl">Total Revenue:</div>
            <div class="val">PKR {{ number_format($analytics->summary->revenue, 2) }}{{ $analytics->previous->revenue_pct !== null ? ' (' . ($analytics->previous->revenue_pct >= 0 ? '+' : '') . $analytics->previous->revenue_pct . '% vs prev period)' : '' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">Total Bills:</div>
            <div class="val">{{ number_format($analytics->summary->bills) }}{{ $analytics->previous->bills_pct !== null ? ' (' . ($analytics->previous->bills_pct >= 0 ? '+' : '') . $analytics->previous->bills_pct . '%)' : '' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">Sales Tax:</div>
            <div class="val">PKR {{ number_format($analytics->summary->tax, 2) }}{{ $analytics->previous->tax_pct !== null ? ' (' . ($analytics->previous->tax_pct >= 0 ? '+' : '') . $analytics->previous->tax_pct . '%)' : '' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">Average Bill:</div>
            <div class="val">PKR {{ number_format($analytics->summary->avg_bill, 2) }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">Total Discounts:</div>
            <div class="val">PKR {{ number_format($analytics->summary->discount, 2) }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">Unique Customers (named):</div>
            <div class="val">{{ $analytics->summary->unique_customers }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">Previous Period ({{ \Carbon\Carbon::parse($analytics->previous->from)->format('d M') }} — {{ \Carbon\Carbon::parse($analytics->previous->to)->format('d M Y') }}):</div>
            <div class="val">PKR {{ number_format($analytics->previous->revenue, 2) }} ({{ $analytics->previous->bills }} bills)</div>
        </div>
    </div>

    @if($analytics->profit !== null && ($analytics->profit->cost > 0 || $analytics->profit->revenue > 0))
    <div class="section-title">Profit Estimate (Cost-Price Based — {{ $analytics->profit->coverage_pct }}% Items Covered)</div>
    <table class="data">
        <thead>
            <tr>
                <th class="r">Sales (Costed Items)</th>
                <th class="r">Cost</th>
                <th class="r">Gross Profit</th>
                <th class="c">Margin</th>
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

    @if($analytics->categories->isNotEmpty())
    <div class="section-title">Category Breakdown (With Product Drill-down)</div>
    <table class="data">
        <thead>
            <tr>
                <th>Category / Product</th>
                <th class="c">Qty</th>
                <th class="r">Revenue (PKR)</th>
                <th class="r">Tax (PKR)</th>
                @if($analytics->is_admin_view)<th class="r">Profit (PKR)</th>@endif
                <th class="c">Share</th>
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
    <div class="section-title">Cashier Performance</div>
    <table class="data">
        <thead>
            <tr>
                <th>Cashier</th>
                <th class="c">Bills</th>
                <th class="r">Revenue (PKR)</th>
                <th class="r">Tax (PKR)</th>
                <th class="r">Avg Bill (PKR)</th>
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
    <div class="section-title">Sales by Waiter</div>
    <table class="data">
        <thead>
            <tr>
                <th>Waiter</th>
                <th class="c">Orders</th>
                <th class="r">Revenue (PKR)</th>
                <th class="r">Avg Bill (PKR)</th>
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
    <div class="section-title">Top Customers</div>
    <table class="data">
        <thead>
            <tr>
                <th class="c" style="width:8%;">#</th>
                <th>Customer</th>
                <th class="c">Visits</th>
                <th class="r">Spent (PKR)</th>
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
    <div class="section-title">Payment Method Split</div>
    <table class="data">
        <thead>
            <tr>
                <th>Method</th>
                <th class="c">Bills</th>
                <th class="r">Revenue (PKR)</th>
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
        <div class="lbl">TOTAL REVENUE ({{ strtoupper($tab) }})</div>
        <div class="val">PKR {{ number_format($analytics->summary->revenue, 2) }}</div>
    </div>

    <div class="footer">
        <p>System-generated Sales Analytics Report</p>
        <div class="brand">Powered by NestPOS Enterprise</div>
    </div>
</body>
</html>
