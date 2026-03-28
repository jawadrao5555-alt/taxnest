<!DOCTYPE html>
<html lang="en">
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
</head>
<body>
    <div class="header">
        <h1>{{ $company->name }}</h1>
        @if($company->address)<p>{{ $company->address }}</p>@endif
        @if($company->phone)<p>Tel: {{ $company->phone }}</p>@endif
        @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="report-title">
        <h2>Day Close Report (Z-Report)</h2>
        <p>FBR Rule 150R-4(f) — End-of-Day Summary</p>
        <p style="font-weight:bold; margin-top:4px;">{{ $report->report_date->format('l, d F Y') }}</p>
    </div>

    <div class="info-box">
        <div class="info-row">
            <div class="lbl">Report Number:</div>
            <div class="val">{{ $report->report_number }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">Report Date:</div>
            <div class="val">{{ $report->report_date->format('d/m/Y') }}</div>
        </div>
        @if($company->fbr_pos_id)
        <div class="info-row">
            <div class="lbl">POS Registration #:</div>
            <div class="val">{{ $company->fbr_pos_id }}</div>
        </div>
        @endif
        <div class="info-row">
            <div class="lbl">Generated:</div>
            <div class="val">{{ $report->created_at->format('d/m/Y h:i A') }}</div>
        </div>
    </div>

    <div class="section-title">Invoice Summary</div>
    <table class="data">
        <thead>
            <tr>
                <th>Category</th>
                <th class="c">Count</th>
                <th class="r">Amount (PKR)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>FBR Submitted Invoices</td>
                <td class="c">{{ $report->fbr_invoices }}</td>
                <td class="r">-</td>
            </tr>
            <tr>
                <td>Local Invoices</td>
                <td class="c">{{ $report->local_invoices }}</td>
                <td class="r">-</td>
            </tr>
            @if($report->failed_invoices > 0)
            <tr>
                <td>Failed/Pending Invoices</td>
                <td class="c">{{ $report->failed_invoices }}</td>
                <td class="r">-</td>
            </tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td>Total Invoices</td>
                <td class="c" style="font-weight:bold;">{{ $report->total_invoices }}</td>
                <td class="r">-</td>
            </tr>
        </tfoot>
    </table>

    <div class="section-title">Financial Summary</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:60%;">Description</th>
                <th class="r" style="width:40%;">Amount (PKR)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Gross Sales</td>
                <td class="r">{{ number_format($report->gross_sales, 2) }}</td>
            </tr>
            @if($report->total_discount > 0)
            <tr>
                <td>Total Discount</td>
                <td class="r" style="color:#dc2626;">-{{ number_format($report->total_discount, 2) }}</td>
            </tr>
            @endif
            <tr>
                <td>Net Sales</td>
                <td class="r">{{ number_format($report->net_sales, 2) }}</td>
            </tr>
            <tr>
                <td>Sales Tax Collected</td>
                <td class="r">{{ number_format($report->total_tax, 2) }}</td>
            </tr>
            @if($report->total_fbr_fee > 0)
            <tr>
                <td>FBR POS Fee (SRO 1279/2021)</td>
                <td class="r">{{ number_format($report->total_fbr_fee, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="summary-box">
        <div class="lbl">TOTAL REVENUE</div>
        <div class="val">PKR {{ number_format($report->total_amount, 2) }}</div>
    </div>

    <div class="section-title">Payment Method Breakdown</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:60%;">Payment Method</th>
                <th class="r" style="width:40%;">Amount (PKR)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Cash</td>
                <td class="r">{{ number_format($report->cash_amount, 2) }}</td>
            </tr>
            <tr>
                <td>Card</td>
                <td class="r">{{ number_format($report->card_amount, 2) }}</td>
            </tr>
            @if($report->other_amount > 0)
            <tr>
                <td>Other</td>
                <td class="r">{{ number_format($report->other_amount, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    @if($cashierBreakdown->isNotEmpty())
    <div class="section-title">Cashier Performance</div>
    <table class="data">
        <thead>
            <tr>
                <th>Cashier</th>
                <th class="c">Sales</th>
                <th class="r">Revenue (PKR)</th>
                <th class="r">Tax (PKR)</th>
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

    <div class="section-title">Invoice Range</div>
    <div class="info-box">
        <div class="info-row">
            <div class="lbl">First Invoice:</div>
            <div class="val">{{ $report->first_invoice_number ?? '-' }} @ {{ $report->first_invoice_time ? $report->first_invoice_time->format('h:i A') : '-' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">Last Invoice:</div>
            <div class="val">{{ $report->last_invoice_number ?? '-' }} @ {{ $report->last_invoice_time ? $report->last_invoice_time->format('h:i A') : '-' }}</div>
        </div>
    </div>

    @if($report->notes)
    <div class="section-title">Notes</div>
    <p style="font-size:10px; color:#374151; padding:4px 0;">{{ $report->notes }}</p>
    @endif

    <div class="hash-box">
        <div class="label">INTEGRITY HASH (SHA-256)</div>
        <p>{{ $report->hash }}</p>
    </div>

    <div class="footer">
        <p>This is a system-generated Day Close Report as required under FBR Rule 150R-4(f)</p>
        <div class="brand">Powered by TaxNest FBR POS</div>
        <p>Generated: {{ $report->created_at->format('d/m/Y h:i:s A') }}</p>
    </div>
</body>
</html>
