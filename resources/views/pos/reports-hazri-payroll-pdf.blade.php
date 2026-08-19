<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <title>{{ __('pos.payroll_pdf_title') }} — {{ $dateFrom }} to {{ $dateTo }}</title>
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
        .info-row .lbl { display: table-cell; width: 36%; font-size: 10px; font-weight: bold; padding: 3px 0; color: #000000; }
        .info-row .val { display: table-cell; width: 64%; font-size: 10px; text-align: right; padding: 3px 0; color: #000000; font-weight: 700; }

        .section-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #1e1b4b; margin: 12px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #1e1b4b; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.data thead th { font-size: 9px; text-transform: uppercase; padding: 6px 5px; text-align: left; font-weight: bold; color: #ffffff; background-color: #1e1b4b; }
        table.data thead th.r { text-align: right; }
        table.data thead th.c { text-align: center; }
        table.data tbody td { font-size: 10px; padding: 5px 5px; border-bottom: 1px solid #d1d5db; color: #000000; }
        table.data tbody td.r { text-align: right; font-weight: 700; }
        table.data tbody td.c { text-align: center; }
        table.data tbody td.duty { text-align: center; font-weight: bold; color: #312e81; }
        table.data tbody tr:nth-child(even) { background-color: #f5f3ff; }
        table.data tfoot td { font-size: 10px; padding: 6px 5px; font-weight: bold; border-top: 2px solid #1e1b4b; color: #000000; }
        table.data tfoot td.r { text-align: right; }
        table.data tfoot td.c { text-align: center; }
        table.data tfoot td.duty { text-align: center; color: #312e81; }

        .open-star { color: #d97706; font-weight: bold; }

        .footnote { font-size: 8px; color: #6b7280; margin-top: 4px; margin-bottom: 10px; }

        .footer { margin-top: 14px; text-align: center; padding-top: 8px; border-top: 1.5px solid #9ca3af; }
        .footer p { font-size: 9px; color: #374151; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #1e1b4b; margin-top: 3px; }
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
        <h2>{{ __('pos.payroll_pdf_title') }}</h2>
        <p>{{ __('pos.payroll_subtitle', ['from' => \Carbon\Carbon::parse($dateFrom)->format('d M Y'), 'to' => \Carbon\Carbon::parse($dateTo)->format('d M Y')]) }}</p>
    </div>

    <div class="info-box">
        <div class="info-row">
            <div class="lbl">{{ __('pos.payroll_from') }}:</div>
            <div class="val">{{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.payroll_to') }}:</div>
            <div class="val">{{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.payroll_generated_at') }}:</div>
            <div class="val">{{ now()->format('d M Y, h:i A') }}</div>
        </div>
    </div>

    {{-- ── POS Session Summary ──────────────────────────────────────── --}}
    @if(!empty($rangeRows))
    <div class="section-title">{{ __('pos.payroll_session_summary') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.th_staff') }}</th>
                <th class="c">{{ __('pos.th_days_present') }}</th>
                <th class="c">{{ __('pos.th_total_duty') }}</th>
                <th class="c">{{ __('pos.th_bills') }}</th>
                <th class="r">{{ __('pos.th_sales_rs') }}</th>
            </tr>
        </thead>
        <tbody>
            @php
                $roleLabels = [
                    'pos_admin'    => 'Admin',  'pos_manager'  => 'Manager', 'pos_cashier'  => 'Cashier',
                    'pos_waiter'   => 'Waiter', 'pos_kitchen'  => 'Kitchen', 'pos_delivery' => 'Delivery',
                    'pos_rider'    => 'Rider',  'archive_viewer' => 'Viewer','local_viewer' => 'Viewer',
                ];
                $totalDutyMin = 0; $totalBills = 0; $totalRevenue = 0;
            @endphp
            @foreach($rangeRows as $r)
            @php $totalDutyMin += $r->total_minutes; $totalBills += $r->total_bills; $totalRevenue += $r->total_revenue; @endphp
            <tr>
                <td>
                    {{ $r->name }}
                    @if($r->pos_role)
                    <span style="font-size:8px; color:#6b7280;">({{ $roleLabels[$r->pos_role] ?? $r->pos_role }})</span>
                    @endif
                </td>
                <td class="c">{{ $r->days_present }}</td>
                <td class="duty">
                    {{ \App\Support\PosHazriDutyHours::format($r->total_minutes) }}<span class="open-star">{{ $r->any_open ? '*' : '' }}</span>
                </td>
                <td class="c">{{ $r->total_bills ?: '—' }}</td>
                <td class="r">{{ $r->total_revenue > 0 ? number_format($r->total_revenue, 0) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td class="c">—</td>
                <td class="duty">{{ \App\Support\PosHazriDutyHours::format($totalDutyMin) }}</td>
                <td class="c">{{ $totalBills ?: '—' }}</td>
                <td class="r">{{ $totalRevenue > 0 ? number_format($totalRevenue, 0) : '—' }}</td>
            </tr>
        </tfoot>
    </table>
    <p class="footnote">{{ __('pos.payroll_open_footnote') }}</p>
    @else
    <p style="font-size:10px; color:#6b7280; margin-bottom:12px;">{{ __('pos.payroll_no_data') }}</p>
    @endif

    {{-- ── Biometric Summary ────────────────────────────────────────── --}}
    @if(!empty($rangeBioRows))
    <div class="section-title">{{ __('pos.payroll_bio_summary') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.th_staff') }}</th>
                <th class="c">{{ __('pos.th_days_present') }}</th>
                {{-- Late Days: only when the calling controller enables the
                     late-arrival feature (FBR, Task #1274). PRA passes no
                     $bioLateEnabled, so its PDF is byte-identical. --}}
                @if(!empty($bioLateEnabled))
                <th class="c">{{ __('pos.bio_late_days') }}</th>
                @endif
                <th class="c">{{ __('pos.th_total_duty') }}</th>
            </tr>
        </thead>
        <tbody>
            @php $bioTotalMin = 0; $bioTotalLate = 0; @endphp
            @foreach($rangeBioRows as $b)
            @php $bioTotalMin += $b->total_minutes; $bioTotalLate += ($b->late_days ?? 0); @endphp
            <tr>
                <td>
                    @if($b->name)
                        {{ $b->name }}
                    @else
                        <em style="color:#6b7280;">PIN: {{ $b->device_pin ?? '?' }}</em>
                    @endif
                </td>
                <td class="c">{{ $b->days_present }}</td>
                @if(!empty($bioLateEnabled))
                <td class="c" style="{{ ($b->late_days ?? 0) > 0 ? 'color:#dc2626; font-weight:bold;' : 'color:#9ca3af;' }}">
                    {{ ($b->late_days ?? 0) > 0 ? $b->late_days : '—' }}
                </td>
                @endif
                <td class="duty">
                    {{ \App\Support\PosHazriDutyHours::format($b->total_minutes) }}<span class="open-star">{{ $b->any_open ? '*' : '' }}</span>
                </td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td class="c">—</td>
                @if(!empty($bioLateEnabled))
                <td class="c" style="{{ $bioTotalLate > 0 ? 'color:#dc2626; font-weight:bold;' : '' }}">{{ $bioTotalLate ?: '—' }}</td>
                @endif
                <td class="duty">{{ \App\Support\PosHazriDutyHours::format($bioTotalMin) }}</td>
            </tr>
        </tfoot>
    </table>
    <p class="footnote">{{ __('pos.payroll_open_footnote') }}</p>
    @endif

    <div class="footer">
        <p>{{ $company->name }} &mdash; {{ __('pos.payroll_pdf_title') }}</p>
        <p class="brand">NestPOS</p>
    </div>

</body>
</html>
