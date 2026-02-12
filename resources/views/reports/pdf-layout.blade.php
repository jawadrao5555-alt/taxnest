<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Report' }} - TaxNest</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #1a1a1a; font-size: 11px; line-height: 1.5; background: #fff; }
        .page { padding: 30px; max-width: 1100px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #059669; padding-bottom: 15px; margin-bottom: 20px; }
        .header-left h1 { font-size: 22px; font-weight: 800; color: #059669; letter-spacing: -0.5px; }
        .header-left .subtitle { font-size: 10px; color: #6b7280; margin-top: 2px; }
        .header-right { text-align: right; }
        .header-right .company-name { font-size: 14px; font-weight: 700; color: #111827; }
        .header-right .company-detail { font-size: 9px; color: #6b7280; margin-top: 1px; }
        .report-title { background: linear-gradient(135deg, #059669, #0d9488); color: white; padding: 12px 20px; border-radius: 8px; margin-bottom: 15px; }
        .report-title h2 { font-size: 16px; font-weight: 700; }
        .report-title .period { font-size: 10px; opacity: 0.9; margin-top: 2px; }
        .summary-grid { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .summary-card { flex: 1; min-width: 120px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; text-align: center; }
        .summary-card .value { font-size: 18px; font-weight: 800; color: #111827; }
        .summary-card .label { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
        .summary-card.highlight { background: #ecfdf5; border-color: #a7f3d0; }
        .summary-card.highlight .value { color: #059669; }
        .summary-card.warning { background: #fffbeb; border-color: #fde68a; }
        .summary-card.warning .value { color: #d97706; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table th { background: #f3f4f6; color: #374151; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 10px; text-align: left; border-bottom: 2px solid #d1d5db; }
        table th.right { text-align: right; }
        table th.center { text-align: center; }
        table td { padding: 7px 10px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
        table td.right { text-align: right; }
        table td.center { text-align: center; }
        table td.bold { font-weight: 700; }
        table td.green { color: #059669; }
        table td.amber { color: #d97706; }
        table tr:hover { background: #f9fafb; }
        table tfoot td { background: #ecfdf5; font-weight: 700; color: #065f46; border-top: 2px solid #a7f3d0; font-size: 10px; }
        .section-title { font-size: 13px; font-weight: 700; color: #111827; margin: 20px 0 10px; padding-bottom: 5px; border-bottom: 1px solid #e5e7eb; }
        .footer { margin-top: 30px; padding-top: 15px; border-top: 2px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
        .footer-left { font-size: 8px; color: #9ca3af; }
        .footer-right { font-size: 8px; color: #9ca3af; text-align: right; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: 600; }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .no-print { margin-bottom: 20px; text-align: center; }
        .no-print button { background: #059669; color: white; border: none; padding: 10px 30px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; margin: 0 5px; }
        .no-print button:hover { background: #047857; }
        .no-print button.secondary { background: #6b7280; }
        .no-print button.secondary:hover { background: #4b5563; }
        .party-header { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 15px; }
        .party-header h3 { font-size: 14px; font-weight: 700; color: #166534; }
        .party-header .detail { font-size: 10px; color: #4b5563; margin-top: 2px; }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 10px; }
            .page { padding: 15px; }
            @page { margin: 10mm; size: A4 landscape; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="no-print">
            <button onclick="window.print()">Print / Save as PDF</button>
            <button class="secondary" onclick="window.history.back()">Back to Report</button>
        </div>

        <div class="header">
            <div class="header-left">
                <h1>TaxNest</h1>
                <div class="subtitle">FBR Compliant Tax & Invoice Management</div>
            </div>
            <div class="header-right">
                <div class="company-name">{{ $company->name ?? 'Company' }}</div>
                <div class="company-detail">NTN: {{ $company->ntn ?? '-' }}</div>
                <div class="company-detail">{{ $company->address ?? '' }}</div>
                <div class="company-detail">{{ $company->phone ?? '' }} | {{ $company->email ?? '' }}</div>
            </div>
        </div>

        @yield('content')

        <div class="footer">
            <div class="footer-left">
                Generated by TaxNest | {{ now()->format('d M Y, h:i A') }}
            </div>
            <div class="footer-right">
                This is a computer-generated report. No signature required.
            </div>
        </div>
    </div>
</body>
</html>
