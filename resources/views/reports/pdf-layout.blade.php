<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title ?? 'Report' }} - TaxNest</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; font-size: 10px; line-height: 1.4; background: #fff; }
        .page { padding: 20px; }
        .header { width: 100%; border-bottom: 3px solid #059669; padding-bottom: 12px; margin-bottom: 15px; }
        .header-table { width: 100%; }
        .header-table td { vertical-align: top; }
        .header-left h1 { font-size: 20px; font-weight: 800; color: #059669; }
        .header-left .subtitle { font-size: 9px; color: #6b7280; margin-top: 2px; }
        .header-right { text-align: right; }
        .header-right .company-name { font-size: 13px; font-weight: 700; color: #111827; }
        .header-right .company-detail { font-size: 8px; color: #6b7280; margin-top: 1px; }
        .report-title { background-color: #059669; color: white; padding: 10px 16px; border-radius: 6px; margin-bottom: 12px; }
        .report-title h2 { font-size: 14px; font-weight: 700; }
        .report-title .period { font-size: 9px; opacity: 0.9; margin-top: 2px; }
        .summary-table { width: 100%; margin-bottom: 15px; }
        .summary-table td { padding: 4px; }
        .summary-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; text-align: center; }
        .summary-card .value { font-size: 15px; font-weight: 800; color: #111827; }
        .summary-card .label { font-size: 7px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
        .summary-card.highlight { background: #ecfdf5; border-color: #a7f3d0; }
        .summary-card.highlight .value { color: #059669; }
        .summary-card.warning { background: #fffbeb; border-color: #fde68a; }
        .summary-card.warning .value { color: #d97706; }
        table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        table.data-table th { background: #f3f4f6; color: #374151; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; padding: 6px 8px; text-align: left; border-bottom: 2px solid #d1d5db; }
        table.data-table th.right { text-align: right; }
        table.data-table th.center { text-align: center; }
        table.data-table td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
        table.data-table td.right { text-align: right; }
        table.data-table td.center { text-align: center; }
        table.data-table td.bold { font-weight: 700; }
        table.data-table td.green { color: #059669; }
        table.data-table td.amber { color: #d97706; }
        table.data-table tfoot td { background: #ecfdf5; font-weight: 700; color: #065f46; border-top: 2px solid #a7f3d0; font-size: 9px; }
        .section-title { font-size: 12px; font-weight: 700; color: #111827; margin: 15px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #e5e7eb; }
        .footer { margin-top: 25px; padding-top: 10px; border-top: 2px solid #e5e7eb; width: 100%; }
        .footer-table { width: 100%; }
        .footer-table td { font-size: 7px; color: #9ca3af; vertical-align: top; }
        .badge { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 8px; font-weight: 600; }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .party-header { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 14px; margin-bottom: 10px; margin-top: 15px; }
        .party-header h3 { font-size: 12px; font-weight: 700; color: #166534; }
        .party-header .detail { font-size: 9px; color: #4b5563; margin-top: 2px; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <table class="header-table">
                <tr>
                    <td class="header-left" style="width: 50%;">
                        <h1>TaxNest</h1>
                        <div class="subtitle">FBR Compliant Tax & Invoice Management</div>
                    </td>
                    <td class="header-right" style="width: 50%;">
                        <div class="company-name">{{ $company->name ?? 'Company' }}</div>
                        <div class="company-detail">NTN: {{ $company->ntn ?? '-' }}</div>
                        <div class="company-detail">{{ $company->address ?? '' }}</div>
                        <div class="company-detail">{{ $company->phone ?? '' }} | {{ $company->email ?? '' }}</div>
                    </td>
                </tr>
            </table>
        </div>

        @yield('content')

        <div class="footer">
            <table class="footer-table">
                <tr>
                    <td style="width: 50%;">Generated by TaxNest | {{ now()->format('d M Y, h:i A') }}</td>
                    <td style="width: 50%; text-align: right;">This is a computer-generated report. No signature required.</td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
