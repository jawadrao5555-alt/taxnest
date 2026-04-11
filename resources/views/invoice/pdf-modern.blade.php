<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number ?? $invoice->id }}</title>
    <style>
        @page {
            margin: 12mm 14mm 10mm 14mm;
            size: A4 portrait;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', 'Helvetica', Arial, sans-serif;
            color: #1e293b;
            font-size: 11px;
            line-height: 1.5;
            width: 100%;
        }

        .top-band {
            background: #0f172a;
            color: #ffffff;
            padding: 16px 20px 14px 20px;
        }
        .top-band table { width: 100%; border-collapse: collapse; }
        .top-band td { vertical-align: middle; }
        .b-name { font-size: 22px; font-weight: 900; letter-spacing: 1px; color: #ffffff; }
        .b-sub { font-size: 9px; color: #94a3b8; margin-top: 2px; line-height: 1.4; }
        .inv-label { font-size: 24px; font-weight: 900; color: #38bdf8; letter-spacing: 2px; text-align: right; }
        .inv-num { font-size: 10px; color: #cbd5e1; text-align: right; margin-top: 2px; font-weight: 600; }

        .color-bar { height: 4px; background: #0ea5e9; }

        .section-title {
            font-size: 8px;
            text-transform: uppercase;
            color: #0ea5e9;
            font-weight: 800;
            letter-spacing: 1.5px;
            border-bottom: 2px solid #0ea5e9;
            padding-bottom: 3px;
            margin-bottom: 5px;
        }

        .info-block { width: 100%; border-collapse: collapse; margin-top: 12px; margin-bottom: 10px; }
        .info-block > tbody > tr > td { vertical-align: top; padding: 0; }

        .card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 8px 10px;
        }
        .card-name { font-size: 12px; font-weight: 800; color: #0f172a; margin-bottom: 2px; }
        .card-row { font-size: 9.5px; color: #475569; line-height: 1.6; }
        .card-row strong { color: #1e293b; font-weight: 700; }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            font-size: 7px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #ffffff;
            background: #0ea5e9;
            margin-bottom: 4px;
        }

        .dtable { width: 100%; border-collapse: collapse; }
        .dtable td { padding: 3px 0; font-size: 9.5px; border-bottom: 1px dashed #e2e8f0; }
        .dtable .dl { color: #64748b; font-weight: 600; }
        .dtable .dv { color: #0f172a; font-weight: 700; text-align: right; }

        .items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .items thead th {
            background: #0f172a;
            padding: 7px 6px;
            font-size: 8px;
            text-transform: uppercase;
            color: #e2e8f0;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-align: left;
            border: 1px solid #0f172a;
        }
        .items thead th.ar { text-align: right; }
        .items thead th.ac { text-align: center; }
        .items tbody td {
            padding: 7px 6px;
            font-size: 10px;
            color: #334155;
            border: 1px solid #e2e8f0;
        }
        .items tbody tr:nth-child(even) td { background: #f8fafc; }
        .items tbody td.ar { text-align: right; font-weight: 700; color: #0f172a; }
        .items tbody td.ac { text-align: center; }
        .items tbody td.hs { font-weight: 700; color: #0f172a; letter-spacing: 0.3px; }
        .items tbody td.desc { font-weight: 600; color: #1e293b; }

        .totals-wrap { width: 100%; border-collapse: collapse; }
        .totals-wrap td { vertical-align: top; }
        .totals-box { border: 1px solid #e2e8f0; overflow: hidden; }
        .totals-box table { width: 100%; border-collapse: collapse; }
        .totals-box td { padding: 5px 10px; font-size: 10px; }
        .totals-box .tl { text-align: right; color: #64748b; font-weight: 600; width: 55%; }
        .totals-box .tv { text-align: right; color: #0f172a; font-weight: 700; width: 45%; white-space: nowrap; }
        .totals-box tr { border-bottom: 1px solid #f1f5f9; }
        .totals-box tr.grand { background: #0f172a; }
        .totals-box tr.grand td { border-bottom: none; padding: 9px 10px; }
        .totals-box tr.grand .tl { color: #94a3b8; font-size: 12px; font-weight: 800; }
        .totals-box tr.grand .tv { color: #38bdf8; font-size: 14px; font-weight: 900; }

        .sched { font-size: 9px; color: #64748b; line-height: 1.5; margin-top: 6px; }
        .sched strong { color: #475569; font-weight: 700; }

        .foot {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 2px solid #0f172a;
            text-align: center;
        }
        .foot-line { font-size: 8px; color: #94a3b8; }
        .foot-brand { font-size: 9px; color: #0f172a; font-weight: 800; margin-top: 2px; letter-spacing: 0.5px; }
    </style>
</head>
<body>

    <div class="top-band">
        <table>
            <tr>
                <td style="width: 58%;">
                    <div class="b-name">{{ $invoice->company->name ?? 'Company' }}</div>
                    <div class="b-sub">
                        @if($invoice->company->address)
                        {{ $invoice->company->address }}@if($invoice->company->city), {{ $invoice->company->city }}@endif
                        <br>
                        @endif
                        @if($invoice->company->ntn)
                        NTN: {{ $invoice->company->ntn }}
                        @endif
                        @if($invoice->company->registration_no)
                        &bull; Reg #: {{ $invoice->company->registration_no }}
                        @endif
                    </div>
                </td>
                <td style="width: 42%;">
                    <div class="inv-label">INVOICE</div>
                    <div class="inv-num"># {{ $invoice->internal_invoice_number ?? $invoice->invoice_number }}</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="color-bar"></div>

    <table class="info-block">
        <tr>
            <td style="width: 36%; padding-right: 8px;">
                <div class="card">
                    <div class="section-title">Bill To</div>
                    <div class="tag">{{ $invoice->buyer_registration_type ?? 'UNREGISTERED' }}</div>
                    <div class="card-name">{{ $invoice->buyer_name }}</div>
                    <div class="card-row">
                        @if($invoice->buyer_ntn)
                        <strong>NTN:</strong> {{ $invoice->buyer_ntn }}<br>
                        @endif
                        @if($invoice->buyer_cnic)
                        <strong>CNIC:</strong> {{ $invoice->buyer_cnic }}<br>
                        @endif
                        @if($invoice->destination_province)
                        {{ $invoice->destination_province }}, Pakistan
                        @endif
                    </div>
                </div>
            </td>
            <td style="width: 36%; padding-right: 8px;">
                <div class="card">
                    <div class="section-title">Invoice Details</div>
                    <table class="dtable">
                        <tr><td class="dl">Date</td><td class="dv">{{ $invoice->created_at->format('d M Y') }}</td></tr>
                        <tr><td class="dl">Status</td><td class="dv">{{ $invoice->fbr_invoice_number ? 'Verified' : ($invoice->status === 'locked' ? 'Production' : ucfirst($invoice->status)) }}</td></tr>
                        <tr><td class="dl">NTN</td><td class="dv">{{ $invoice->company->ntn ?? 'N/A' }}</td></tr>
                        @if($invoice->supplier_province)
                        <tr><td class="dl">Origin</td><td class="dv">{{ $invoice->supplier_province }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
            <td style="width: 28%;">
                @if(!empty($qrBase64))
                <div class="card" style="text-align: center;">
                    <div class="section-title" style="text-align: center;">Scan QR</div>
                    <img src="{{ $qrBase64 }}" alt="QR" style="width: 70px; height: 70px;">
                    @if($invoice->fbr_invoice_number)
                    <div style="font-size: 7px; color: #64748b; font-weight: 700; letter-spacing: 0.5px; margin-top: 3px;">{{ $invoice->fbr_invoice_number }}</div>
                    @endif
                </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 28px;">SR</th>
                <th style="width: 78px;">HS CODE</th>
                <th>DESCRIPTION</th>
                <th class="ac" style="width: 48px;">UOM</th>
                <th class="ar" style="width: 58px;">QTY</th>
                <th class="ar" style="width: 68px;">RATE</th>
                <th class="ar" style="width: 82px;">AMOUNT</th>
                <th class="ar" style="width: 38px;">TAX</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            <tr>
                <td class="ac">{{ $index + 1 }}</td>
                <td class="hs">{{ $item->hs_code }}</td>
                <td class="desc">{{ $item->description }}</td>
                <td class="ac" style="font-size: 9px;">{{ $item->default_uom ?? 'PCS' }}</td>
                <td class="ar">{{ number_format($item->quantity, 2) }}</td>
                <td class="ar">{{ number_format($item->price, 2) }}</td>
                <td class="ar" style="font-weight: 800;">{{ number_format($item->price * $item->quantity, 2) }}</td>
                <td class="ar" style="font-size: 9px;">{{ number_format($item->tax_rate ?? 0, 0) }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-wrap">
        <tr>
            <td style="width: 50%; padding-right: 14px;">
                @php
                    $scheduleTypes = $invoice->items->pluck('schedule_type')->unique()->filter();
                    $sroNumbers = $invoice->items->pluck('sro_schedule_no')->unique()->filter();
                    $serialNumbers = $invoice->items->pluck('serial_no')->unique()->filter();
                @endphp
                @if($scheduleTypes->count() > 0 || $sroNumbers->count() > 0)
                <div class="sched">
                    @if($scheduleTypes->count() > 0)
                    <strong>Schedule:</strong> {{ $scheduleTypes->map(fn($t) => ucfirst(str_replace('_', ' ', $t)))->join(', ') }}<br>
                    @endif
                    @if($sroNumbers->count() > 0)
                    <strong>SRO:</strong> {{ $sroNumbers->join(', ') }}<br>
                    @endif
                    @if($serialNumbers->count() > 0)
                    <strong>Serial No:</strong> {{ $serialNumbers->join(', ') }}
                    @endif
                </div>
                @endif
            </td>
            <td style="width: 50%;">
                <div class="totals-box">
                    <table>
                        <tr>
                            <td class="tl">Sub Total</td>
                            <td class="tv">PKR {{ number_format($subtotal, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="tl">Sales Tax (GST)</td>
                            <td class="tv">PKR {{ number_format($totalTax, 2) }}</td>
                        </tr>
                        @php $totalFurtherTax = $invoice->items->sum('further_tax'); @endphp
                        @if($totalFurtherTax > 0)
                        <tr>
                            <td class="tl">Further Tax (4%)</td>
                            <td class="tv" style="color: #f97316;">PKR {{ number_format($totalFurtherTax, 2) }}</td>
                        </tr>
                        @endif
                        @if(($wht_rate ?? 0) > 0)
                        <tr>
                            <td class="tl">WHT ({{ $wht_rate }}%)</td>
                            <td class="tv">PKR {{ number_format($wht_amount ?? 0, 2) }}</td>
                        </tr>
                        @endif
                        <tr class="grand">
                            <td class="tl">TOTAL</td>
                            <td class="tv">PKR {{ number_format(($wht_rate ?? 0) > 0 ? ($net_receivable ?? $invoice->total_amount) : $invoice->total_amount, 2) }}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <div class="foot">
        <div class="foot-line">This is a computer-generated invoice. | {{ $invoice->created_at->format('d M Y, h:i A') }}</div>
        <div class="foot-brand">TaxNest &mdash; Tax & Invoice Management System</div>
    </div>

</body>
</html>
