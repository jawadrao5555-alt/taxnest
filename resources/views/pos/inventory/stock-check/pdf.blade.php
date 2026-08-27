<!DOCTYPE html>
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>Stock Check {{ $check->code }}</title>
    <style>
        @page { margin: 10mm 14mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 11px; color: #000000; line-height: 1.5; background: #fff; }

        .header { background-color: #0A4D5C; padding: 16px 20px; text-align: center; margin-bottom: 14px; }
        .header h1 { font-size: 16px; font-weight: bold; color: #ffffff; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 4px; }
        .header p { font-size: 10px; color: #dbeafe; }

        .report-title { text-align: center; margin-bottom: 14px; }
        .report-title h2 { font-size: 14px; font-weight: bold; color: #0A4D5C; text-transform: uppercase; letter-spacing: 1px; }
        .report-title p { font-size: 10px; color: #374151; margin-top: 2px; }

        .info-box { border: 2px solid #0A4D5C; padding: 8px 14px; margin-bottom: 14px; }
        .info-row { display: table; width: 100%; }
        .info-row .lbl { display: table-cell; width: 45%; font-size: 10px; font-weight: bold; padding: 3px 0; color: #000000; }
        .info-row .val { display: table-cell; width: 55%; font-size: 10px; text-align: right; padding: 3px 0; color: #000000; font-weight: 700; }

        .section-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #0A4D5C; margin: 12px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #0A4D5C; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.data thead th { font-size: 9px; text-transform: uppercase; padding: 6px 5px; text-align: left; font-weight: bold; color: #ffffff; background-color: #0A4D5C; }
        table.data thead th.r { text-align: right; }
        table.data thead th.c { text-align: center; }
        table.data tbody td { font-size: 10px; padding: 5px 5px; border-bottom: 1px solid #d1d5db; color: #000000; }
        table.data tbody td.r { text-align: right; font-weight: 700; }
        table.data tbody td.c { text-align: center; }
        table.data tbody tr:nth-child(even) { background-color: #f1f7f8; }
        .short { color: #b91c1c; }
        .excess { color: #047857; }
        .muted { font-size: 9px; color: #6b7280; }

        .summary-box { background-color: #0A4D5C; padding: 10px 16px; margin: 10px 0; display: table; width: 100%; }
        .summary-box .lbl { display: table-cell; text-align: left; font-size: 13px; font-weight: bold; color: #ffffff; vertical-align: middle; }
        .summary-box .val { display: table-cell; text-align: right; font-size: 13px; font-weight: bold; color: #ffffff; vertical-align: middle; }

        .sign-row { display: table; width: 100%; margin-top: 26px; }
        .sign-row .cell { display: table-cell; width: 33%; padding: 0 8px; text-align: center; font-size: 9px; color: #374151; }
        .sign-row .line { border-top: 1px solid #6b7280; margin-bottom: 4px; height: 26px; }

        .footer { margin-top: 14px; text-align: center; padding-top: 8px; border-top: 1.5px solid #9ca3af; }
        .footer p { font-size: 9px; color: #374151; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #0A4D5C; margin-top: 3px; }
    </style>
    @if($pdfUrdu ?? false)
    <style>
        body { font-family: 'Jameel Noori Nastaleeq', 'XB Riyaz', 'DejaVu Sans', sans-serif; direction: rtl; }
        table.data, .info-box, .info-row { direction: rtl; }
        .section-title, table.data thead th, .header h1, .report-title h2 { text-transform: none; letter-spacing: 0; }
    </style>
    @endif
</head>
<body>
    <div class="header">
        <h1>{{ $company->name }}</h1>
        @if($company->address)<p>{{ $company->address }}</p>@endif
        @if($branchLabel)<p>{{ $branchLabel }}</p>@endif
    </div>

    <div class="report-title">
        <h2>{{ __('pos.stock_check_variance_report') }}</h2>
        <p>{{ $check->code }} — {{ __('pos.stock_check_scope_' . $check->scope) }}</p>
        <p>{{ __('pos.dcp_generated') }} {{ now()->format('d M Y h:i A') }}</p>
    </div>

    <div class="info-box">
        <div class="info-row">
            <span class="lbl">{{ __('pos.stock_check_started_at') }}</span>
            <span class="val">{{ optional($check->started_at)->format('d M Y, h:i A') ?? '—' }}</span>
        </div>
        <div class="info-row">
            <span class="lbl">{{ __('pos.stock_check_posted_at') }}</span>
            <span class="val">{{ optional($check->posted_at)->format('d M Y, h:i A') ?? '—' }}</span>
        </div>
        <div class="info-row">
            <span class="lbl">{{ __('pos.stock_check_items') }}</span>
            <span class="val">{{ $check->total_lines }}</span>
        </div>
        <div class="info-row">
            <span class="lbl">{{ __('pos.stock_check_counted') }}</span>
            <span class="val">{{ $check->counted_lines }}</span>
        </div>
        <div class="info-row">
            <span class="lbl">{{ __('pos.stock_check_gaps') }}</span>
            <span class="val">{{ $check->variance_lines }}</span>
        </div>
    </div>

    <div class="section-title">{{ __('pos.stock_check_gap_lines') }}</div>

    @if($lines->isEmpty())
    <p style="font-size:11px; padding:10px 0;">{{ __('pos.stock_check_no_gaps') }}</p>
    @else
    <table class="data">
        <thead>
            <tr>
                <th style="width:34%">{{ __('pos.item_word') }}</th>
                <th class="c" style="width:10%">{{ __('pos.unit_label') }}</th>
                <th class="r" style="width:12%">{{ __('pos.stock_check_expected') }}</th>
                <th class="r" style="width:12%">{{ __('pos.stock_check_physical') }}</th>
                <th class="r" style="width:12%">{{ __('pos.stock_check_difference') }}</th>
                <th class="r" style="width:10%">{{ __('pos.amount_label') }}</th>
                <th style="width:10%">{{ __('pos.stock_check_reason') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
            @php
                $v = (float) $line->variance;
                $cls = $v < 0 ? 'short' : 'excess';
                $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
            @endphp
            <tr>
                <td>
                    {{ $line->item_name }}
                    @if($line->item_code)<div class="muted">{{ $line->item_code }}</div>@endif
                </td>
                <td class="c">{{ $line->unit ?: '—' }}</td>
                <td class="r">{{ $fmt($line->expected_quantity) }}</td>
                <td class="r">{{ $fmt($line->counted_quantity) }}</td>
                <td class="r {{ $cls }}">{{ ($v > 0 ? '+' : '') . $fmt($v) }}</td>
                <td class="r {{ $cls }}">{{ number_format(abs((float) $line->variance_value), 0) }}</td>
                <td>{{ $line->reason ? __('pos.stock_check_reason_' . $line->reason) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="summary-box">
        <span class="lbl">{{ __('pos.stock_check_short_value') }}</span>
        <span class="val">{{ number_format($check->short_value, 0) }}</span>
    </div>
    <div class="summary-box">
        <span class="lbl">{{ __('pos.stock_check_excess_value') }}</span>
        <span class="val">{{ number_format($check->excess_value, 0) }}</span>
    </div>
    <div class="summary-box">
        <span class="lbl">{{ __('pos.stock_check_net_value') }}</span>
        <span class="val">{{ ($check->netValue() < 0 ? '-' : '+') . number_format(abs($check->netValue()), 0) }}</span>
    </div>

    @if($check->notes)
    <div class="section-title">{{ __('pos.notes_label') }}</div>
    <p style="font-size:10px;">{{ $check->notes }}</p>
    @endif

    <div class="sign-row">
        <div class="cell"><div class="line"></div>{{ __('pos.stock_check_sign_counted') }}</div>
        <div class="cell"><div class="line"></div>{{ __('pos.stock_check_sign_verified') }}</div>
        <div class="cell"><div class="line"></div>{{ __('pos.stock_check_sign_approved') }}</div>
    </div>

    <div class="footer">
        <p>{{ __('pos.dcp_generated') }} {{ now()->format('d M Y h:i A') }}</p>
        <p class="brand">NestPOS</p>
    </div>
</body>
</html>
