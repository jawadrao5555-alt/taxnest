@php
    use App\Models\HealthCharge;
    use App\Models\HealthPayment;
    use App\Models\HealthTaxCategory;
    use App\Support\QrImage;

    $money = fn ($v) => number_format((float) $v, 2);

    // Paper. 58mm and 80mm are thermal rolls; a4 is the counter's laser sheet.
    // The CONTENT is identical across all three — a receipt that says one thing
    // on the roll and another on the sheet is not a receipt.
    $isThermal = in_array($size, ['58', '80'], true);
    $paperWidth = $size === '58' ? '54mm' : ($size === '80' ? '76mm' : 'auto');

    // QR carries the BARE FBR invoice number. Tax Asaan reads a scanned QR AS
    // the number; a JSON payload verifies nowhere. Rendered locally — never via
    // an external QR service, which would leak the invoice and die offline.
    $qr = null;
    if ($bill->fbr_invoice_number) {
        try {
            $qr = QrImage::dataUri((string) $bill->fbrQrPayload(), 4);
        } catch (\Throwable $e) {
            $qr = null;
        }
    }

    $livePayments = $bill->payments->filter(fn ($p) => !$p->reversed_at);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $bill->bill_no }}</title>
    @include('partials.urdu-print-font')
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: {{ $isThermal ? '4mm 2mm' : '12mm' }};
            font-family: {{ $isThermal ? "'Courier New', monospace" : "system-ui, -apple-system, 'Segoe UI', sans-serif" }};
            font-size: {{ $size === '58' ? '10px' : ($size === '80' ? '11px' : '13px') }};
            color: #000;
            background: #fff;
            /* Thermal: never force the BODY to the paper width — the printer
               already owns the width and forcing it clips the right edge. */
            {!! $isThermal ? "max-width: {$paperWidth};" : 'max-width: 190mm;' !!}
            margin-inline: auto;
        }
        h1 { font-size: {{ $isThermal ? '13px' : '19px' }}; margin: 0 0 2px; }
        .muted { color: #555; }
        .center { text-align: center; }
        .end { text-align: right; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .rule { border-top: 1px dashed #000; margin: 5px 0; }
        .solid { border-top: 1px solid #000; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: {{ $isThermal ? '2px 1px' : '6px 4px' }}; text-align: left; vertical-align: top; }
        th { border-bottom: 1px solid #000; font-size: {{ $isThermal ? '9px' : '11px' }}; text-transform: uppercase; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .bold { font-weight: 700; }
        .small { font-size: {{ $isThermal ? '9px' : '11px' }}; }
        .tag { display: inline-block; border: 1px solid #000; padding: 0 3px; font-size: 9px; }
        @media print {
            body { padding: {{ $isThermal ? '0' : '10mm' }}; }
            .noprint { display: none !important; }
            @page { margin: {{ $isThermal ? '2mm' : '10mm' }}; }
        }
    </style>
</head>
<body>

<div class="noprint center" style="margin-bottom:8px">
    <button onclick="window.print()" style="padding:6px 14px;font-weight:700;cursor:pointer">{{ __('health.print') }}</button>
</div>

<div class="center">
    <h1>{{ $company->name ?? '' }}</h1>
    @if($company->address ?? null)<div class="small muted">{{ $company->address }}</div>@endif
    @if($company->phone ?? null)<div class="small muted">{{ $company->phone }}</div>@endif
    @if($company->ntn ?? null)<div class="small muted">{{ __('health.ntn') }}: {{ $company->ntn }}</div>@endif
    <div class="bold" style="margin-top:4px">
        {{ __($bill->isEstimate() ? 'health.rcpt_estimate_heading' : 'health.rcpt_heading') }}
    </div>
</div>

<div class="rule"></div>

<div class="row small"><span>{{ __('health.bill_no') }}</span><span class="bold">{{ $bill->bill_no }}</span></div>
<div class="row small"><span>{{ __('health.date') }}</span><span>{{ optional($bill->bill_date)->format('d M Y') }} {{ optional($bill->finalized_at ?: $bill->created_at)->format('h:i A') }}</span></div>
<div class="row small"><span>{{ __('health.patient') }}</span><span class="bold">{{ $bill->patient->name ?? '—' }}</span></div>
@if($bill->patient->mrn ?? null)
    <div class="row small"><span>{{ __('health.mrn') }}</span><span>{{ $bill->patient->mrn }}</span></div>
@endif
@if($bill->department)
    <div class="row small"><span>{{ __('health.department') }}</span><span>{{ $bill->department->name }}</span></div>
@endif
<div class="row small"><span>{{ __('health.bill_scope') }}</span><span>{{ __(\App\Models\HealthBill::scopeLabelKey($bill->scope)) }}</span></div>

<div class="rule"></div>

<table>
    <thead>
    <tr>
        <th>{{ __('health.description') }}</th>
        <th class="num">{{ __('health.qty') }}</th>
        <th class="num">{{ __('health.rate') }}</th>
        <th class="num">{{ __('health.total') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach($bill->lines as $line)
        <tr>
            <td>
                {{ $line->description }}
                <div class="small muted">
                    {{ __(HealthCharge::categoryLabelKey($line->category)) }}
                    @if($line->department_name) · {{ $line->department_name }} @endif
                    @if($line->tax_treatment === HealthTaxCategory::TREATMENT_FBR)
                        <span class="tag">{{ __('health.tax_treatment_fbr') }}</span>
                    @elseif($line->tax_treatment === HealthTaxCategory::TREATMENT_EXEMPT)
                        <span class="tag">{{ __('health.tax_treatment_exempt') }}</span>
                    @endif
                </div>
            </td>
            <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}</td>
            <td class="num">{{ $money($line->unit_price) }}</td>
            <td class="num">{{ $money($line->total_amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="solid"></div>

@foreach([
    ['health.bill_gross', $bill->gross_amount, false],
    ['health.concession', $bill->concession_amount, false],
    ['health.tax', $bill->tax_amount, false],
    ['health.bill_total', $bill->total_amount, true],
    ['health.bill_insurance', $bill->insurance_amount, false],
    ['health.bill_corporate', $bill->corporate_amount, false],
    ['health.bill_patient_payable', $bill->patient_payable, true],
    ['health.bill_deposit_applied', $bill->deposit_applied, false],
    ['health.bill_paid', $bill->paid_amount, false],
    ['health.bill_refunded', $bill->refunded_amount, false],
    ['health.bill_outstanding', $bill->outstanding_amount, true],
] as [$label, $value, $strong])
    @if($strong || (float) $value != 0.0)
        <div class="row {{ $strong ? 'bold' : 'small' }}">
            <span>{{ __($label) }}</span><span>{{ $money($value) }}</span>
        </div>
    @endif
@endforeach

@if($livePayments->isNotEmpty())
    <div class="rule"></div>
    <div class="small bold">{{ __('health.pay_receipts') }}</div>
    @foreach($livePayments as $p)
        <div class="row small">
            <span>{{ $p->receipt_no }} · {{ __(HealthPayment::methodLabelKey($p->method)) }}</span>
            <span>{{ $p->isInflow() ? '' : '-' }}{{ $money($p->amount) }}</span>
        </div>
    @endforeach
@endif

@if($bill->fbr_invoice_number)
    <div class="solid"></div>
    <div class="center">
        <div class="small bold">{{ __('health.fbr_invoice_number') }}</div>
        <div class="bold" style="font-family:monospace">{{ $bill->fbr_invoice_number }}</div>
        @if($qr)
            <img src="{{ $qr }}" alt="QR" style="width:{{ $isThermal ? '26mm' : '32mm' }};height:auto;margin-top:4px">
        @endif
        <div class="small muted">{{ __('health.fbr_verify_hint') }}</div>
    </div>
@endif

<div class="rule"></div>
<div class="center small muted">
    {{ __('health.rcpt_footer') }}
    <div>{{ now()->format('d M Y, h:i A') }}</div>
</div>

</body>
</html>
