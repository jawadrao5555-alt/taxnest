<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Z-Report {{ $report->report_number }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { background: #f3f4f6; font-family: 'Courier New', Courier, monospace; color: #000; }
    .toolbar { max-width: 340px; margin: 10px auto 0; display: flex; gap: 8px; }
    .toolbar button, .toolbar a { flex: 1; padding: 10px; font-size: 13px; font-weight: bold; border: none; border-radius: 8px; cursor: pointer; text-align: center; text-decoration: none; font-family: inherit; }
    .btn-print { background: #0A4D5C; color: #fff; }
    .btn-back { background: #e5e7eb; color: #111; }
    .receipt { width: 302px; margin: 10px auto 30px; background: #fff; padding: 10px 8px; font-size: 11px; line-height: 1.45; }
    .c { text-align: center; }
    .b { font-weight: bold; }
    .lg { font-size: 13px; }
    .xl { font-size: 15px; }
    .sm { font-size: 9px; }
    .hr { border-top: 1px dashed #000; margin: 5px 0; }
    .hr2 { border-top: 2px solid #000; margin: 5px 0; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 1px 0; font-size: 11px; vertical-align: top; }
    td.r { text-align: right; white-space: nowrap; }
    td.ct { text-align: center; }
    .sec { font-weight: bold; text-transform: uppercase; font-size: 10px; letter-spacing: 1px; margin-top: 4px; }
    .wrap { word-break: break-all; }
    @media print {
        html, body { background: #fff; }
        .toolbar { display: none; }
        .receipt { width: 100%; max-width: 80mm; margin: 0; padding: 0 2mm; }
        @page { margin: 3mm; size: 80mm auto; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨 Print Z-Report</button>
    <a class="btn-back" href="{{ route('pos.day-close', ['date' => $report->report_date->format('Y-m-d')]) }}">Back</a>
</div>

<div class="receipt">
    <div class="c b lg">{{ $company->name }}</div>
    @if($company->address)<div class="c sm">{{ $company->address }}</div>@endif
    @if($company->ntn)<div class="c sm">NTN: {{ $company->ntn }}</div>@endif
    <div class="hr2"></div>
    <div class="c b xl">Z-REPORT (DAY CLOSE)</div>
    <div class="c">{{ $report->report_number }}</div>
    <div class="c">{{ $report->report_date->format('l, d M Y') }}</div>
    <div class="c sm">Closed: {{ $report->created_at->format('d/m/Y h:i A') }}@if($report->closedByUser) by {{ $report->closedByUser->name }}@endif</div>
    <div class="hr2"></div>

    <div class="sec">Sales Summary</div>
    <table>
        <tr><td>Total Invoices</td><td class="r b">{{ $report->total_invoices }}</td></tr>
        <tr><td>Gross Sales</td><td class="r">{{ number_format($report->gross_sales, 2) }}</td></tr>
        @if($report->total_discount > 0)
        <tr><td>Discount</td><td class="r">-{{ number_format($report->total_discount, 2) }}</td></tr>
        @endif
        <tr><td>Net Sales</td><td class="r">{{ number_format($report->net_sales, 2) }}</td></tr>
        <tr><td>Sales Tax</td><td class="r">{{ number_format($report->total_tax, 2) }}</td></tr>
        <tr><td class="b lg">TOTAL REVENUE</td><td class="r b lg">{{ number_format($report->total_amount, 2) }}</td></tr>
    </table>
    <div class="hr"></div>

    <div class="sec">Payments</div>
    <table>
        <tr><td>Cash</td><td class="r">{{ number_format($report->cash_amount, 2) }}</td></tr>
        <tr><td>Card</td><td class="r">{{ number_format($report->card_amount, 2) }}</td></tr>
        @if($report->other_amount > 0)
        <tr><td>Other</td><td class="r">{{ number_format($report->other_amount, 2) }}</td></tr>
        @endif
    </table>
    <div class="hr"></div>

    @if($report->counted_cash !== null)
    <div class="sec">Cash Reconciliation</div>
    <table>
        <tr><td>Opening Float</td><td class="r">{{ number_format($report->opening_float ?? 0, 2) }}</td></tr>
        <tr><td>Cash Sales</td><td class="r">{{ number_format($report->cash_amount, 2) }}</td></tr>
        @if(is_array($report->rider_summary) && ($report->rider_summary['cash_out'] ?? 0) > 0)
        <tr><td>Rider Cash (Unsettled)</td><td class="r">-{{ number_format($report->rider_summary['cash_out'], 2) }}</td></tr>
        @endif
        @if(is_array($report->rider_summary) && ($report->rider_summary['cash_in'] ?? 0) > 0)
        <tr><td>Rider Settlements (Old)</td><td class="r">+{{ number_format($report->rider_summary['cash_in'], 2) }}</td></tr>
        @endif
        <tr><td>Expected in Drawer</td><td class="r">{{ number_format($report->expected_cash ?? 0, 2) }}</td></tr>
        <tr><td>Counted Cash</td><td class="r">{{ number_format($report->counted_cash, 2) }}</td></tr>
        <tr>
            <td class="b">Variance {{ abs((float) $report->cash_variance) < 0.01 ? '(BALANCED)' : ((float) $report->cash_variance < 0 ? '(SHORT)' : '(OVER)') }}</td>
            <td class="r b">{{ (float) $report->cash_variance > 0 ? '+' : '' }}{{ number_format($report->cash_variance, 2) }}</td>
        </tr>
    </table>
    <div class="hr"></div>
    @endif

    @if(is_array($report->rider_summary) && !empty($report->rider_summary['riders']))
    <div class="sec">Delivery Riders</div>
    <table>
        @foreach($report->rider_summary['riders'] as $rr)
        <tr>
            <td>{{ $rr['name'] ?? '-' }} ({{ $rr['deliveries'] ?? 0 }} del)</td>
            <td class="r">{{ ($rr['cash_pending'] ?? 0) > 0 ? 'Owes ' . number_format($rr['cash_pending'], 2) : 'Clear' }}</td>
        </tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    <div class="sec">PRA Status</div>
    <table>
        <tr><td>Submitted</td><td class="r">{{ $analytics->pra_health->submitted }}</td></tr>
        @if($analytics->pra_health->pending > 0)<tr><td>Pending</td><td class="r">{{ $analytics->pra_health->pending }}</td></tr>@endif
        @if($analytics->pra_health->offline > 0)<tr><td>Offline Queue</td><td class="r">{{ $analytics->pra_health->offline }}</td></tr>@endif
        @if($analytics->pra_health->failed > 0)<tr><td>Failed</td><td class="r">{{ $analytics->pra_health->failed }}</td></tr>@endif
        @if($analytics->pra_health->not_reported > 0)<tr><td>Not Reported</td><td class="r">{{ $analytics->pra_health->not_reported }}</td></tr>@endif
    </table>
    <div class="hr"></div>

    @if($analytics->categories->isNotEmpty())
    <div class="sec">Category Sales</div>
    <table>
        @foreach($analytics->categories as $catName => $cat)
        <tr><td>{{ \Illuminate\Support\Str::limit($catName, 18) }}</td><td class="ct">{{ rtrim(rtrim(number_format($cat->qty, 2), '0'), '.') }}</td><td class="r">{{ number_format($cat->revenue, 2) }}</td></tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    @if($analytics->top_products->isNotEmpty())
    <div class="sec">Top Products</div>
    <table>
        @foreach($analytics->top_products->take(5) as $pname => $p)
        <tr><td>{{ \Illuminate\Support\Str::limit($pname, 18) }}</td><td class="ct">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td><td class="r">{{ number_format($p->revenue, 2) }}</td></tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    @if($analytics->restaurant_enabled && $analytics->order_types->isNotEmpty())
    <div class="sec">Order Types</div>
    <table>
        @foreach($analytics->order_types as $type => $ot)
        <tr><td>{{ ['dine_in' => 'Dine-In', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery', 'counter' => 'Counter'][$type] ?? ucfirst(str_replace('_', ' ', $type)) }}</td><td class="ct">{{ $ot->count }}</td><td class="r">{{ number_format($ot->revenue, 2) }}</td></tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    @if($cashierBreakdown->isNotEmpty())
    <div class="sec">Cashiers</div>
    <table>
        @foreach($cashierBreakdown as $name => $data)
        <tr><td>{{ \Illuminate\Support\Str::limit($name, 18) }}</td><td class="ct">{{ $data->count }}</td><td class="r">{{ number_format($data->revenue, 2) }}</td></tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    <div class="sec">Stats</div>
    <table>
        <tr><td>Average Bill</td><td class="r">{{ number_format($analytics->avg_bill, 2) }}</td></tr>
        <tr><td>Unique Customers</td><td class="r">{{ $analytics->unique_customers }}</td></tr>
        @if($analytics->discounts->total > 0)
        <tr><td>Discounts ({{ $analytics->discounts->bill_count }} bills)</td><td class="r">{{ number_format($analytics->discounts->total, 2) }}</td></tr>
        @endif
    </table>
    <div class="hr"></div>

    <table>
        <tr><td>First Inv</td><td class="r">{{ $report->first_invoice_number ?? '-' }}</td></tr>
        <tr><td>Last Inv</td><td class="r">{{ $report->last_invoice_number ?? '-' }}</td></tr>
    </table>
    <div class="hr2"></div>
    <div class="c sm">SHA-256 Integrity Hash</div>
    <div class="c sm wrap">{{ $report->hash }}</div>
    <div class="hr"></div>
    <div class="c sm">System-generated Z-Report — PRA Compliance</div>
    <div class="c sm b">Powered by NestPOS Enterprise</div>
</div>
</body>
</html>
