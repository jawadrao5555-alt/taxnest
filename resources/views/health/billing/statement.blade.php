@php
    use App\Models\HealthBill;
    use App\Models\HealthCharge;
    use App\Models\HealthPayment;
    use App\Models\HealthTaxCategory;

    $money = fn ($v) => number_format((float) $v, 2);

    // The statement is a READING of the same persisted rows the bills and
    // receipts print from. It never re-derives a total of its own, so a
    // statement handed to a patient can always be checked line by line against
    // the documents they already hold.
    $bills = $account['bills'];
    $payments = $account['payments'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $patient->name }} — {{ __('health.stmt_title') }}</title>
    @include('partials.urdu-print-font')
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 12mm; background: #fff; color: #111;
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 12px;
            max-width: 200mm; margin-inline: auto;
        }
        h1 { font-size: 19px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .04em; }
        .muted { color: #666; }
        .center { text-align: center; }
        .row { display: flex; justify-content: space-between; gap: 10px; }
        .rule { border-top: 1px solid #ccc; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; text-align: left; border-bottom: 1px solid #eee; vertical-align: top; }
        th { background: #f5f5f5; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .bold { font-weight: 700; }
        .small { font-size: 10px; }
        .cards { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .card { flex: 1 1 120px; border: 1px solid #ddd; border-radius: 6px; padding: 8px; }
        .card .k { font-size: 9px; text-transform: uppercase; color: #666; letter-spacing: .04em; }
        .card .v { font-size: 14px; font-weight: 800; margin-top: 2px; }
        .void { opacity: .5; text-decoration: line-through; }
        @media print { body { padding: 10mm; } .noprint { display: none !important; } @page { margin: 10mm; } }
    </style>
</head>
<body>

<div class="noprint center" style="margin-bottom:10px">
    <button onclick="window.print()" style="padding:6px 14px;font-weight:700;cursor:pointer">{{ __('health.print') }}</button>
</div>

<div class="center">
    <h1>{{ $company->name ?? '' }}</h1>
    @if($company->address ?? null)<div class="small muted">{{ $company->address }}</div>@endif
    <div class="bold" style="margin-top:6px">{{ __('health.stmt_heading') }}</div>
</div>

<div class="rule"></div>

<div class="row">
    <div>
        <div class="bold" style="font-size:14px">{{ $patient->name }}</div>
        <div class="small muted">
            {{ $patient->mrn }}
            @if($patient->phone) · {{ $patient->phone }} @endif
            @if($patient->gender) · {{ __(\App\Models\HealthPatient::genderLabelKey($patient->gender)) }} @endif
        </div>
    </div>
    <div class="small muted end">{{ __('health.stmt_generated') }}: {{ now()->format('d M Y, h:i A') }}</div>
</div>

<div class="cards">
    @foreach([
        ['health.acct_billed', $account['billed']],
        ['health.acct_collected', $account['collected']],
        ['health.acct_refunded', $account['refunded']],
        ['health.acct_credit', $account['credit']],
        ['health.acct_due_now', $account['due_now']],
    ] as [$label, $value])
        <div class="card">
            <div class="k">{{ __($label) }}</div>
            <div class="v">{{ $money($value) }}</div>
        </div>
    @endforeach
</div>

<h2>{{ __('health.bill_bills') }}</h2>
<table>
    <thead>
    <tr>
        <th>{{ __('health.bill_no') }}</th>
        <th>{{ __('health.date') }}</th>
        <th>{{ __('health.bill_scope') }}</th>
        <th>{{ __('health.status') }}</th>
        <th class="num">{{ __('health.bill_total') }}</th>
        <th class="num">{{ __('health.bill_paid') }}</th>
        <th class="num">{{ __('health.bill_outstanding') }}</th>
        <th>{{ __('health.fbr') }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($bills as $bill)
        <tr class="{{ $bill->status === HealthBill::STATUS_CANCELLED ? 'void' : '' }}">
            <td class="bold">{{ $bill->bill_no }}</td>
            <td>{{ optional($bill->bill_date)->format('d M Y') }}</td>
            <td>{{ __(HealthBill::scopeLabelKey($bill->scope)) }}</td>
            <td>{{ __(HealthBill::statusLabelKey($bill->status)) }}</td>
            <td class="num">{{ $money($bill->total_amount) }}</td>
            <td class="num">{{ $money($bill->paid_amount) }}</td>
            <td class="num">{{ $money($bill->outstanding_amount) }}</td>
            <td class="small" style="font-family:monospace">{{ $bill->fbr_invoice_number ?: '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="center muted">{{ __('health.bill_none_yet') }}</td></tr>
    @endforelse
    </tbody>
</table>

<h2>{{ __('health.pay_receipts') }}</h2>
<table>
    <thead>
    <tr>
        <th>{{ __('health.pay_receipt_no') }}</th>
        <th>{{ __('health.date') }}</th>
        <th>{{ __('health.pay_kind') }}</th>
        <th>{{ __('health.pay_method') }}</th>
        <th>{{ __('health.bill_no') }}</th>
        <th class="num">{{ __('health.amount') }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($payments as $p)
        <tr>
            <td>{{ $p->receipt_no }}</td>
            <td>{{ optional($p->received_at)->format('d M Y, h:i A') }}</td>
            <td>{{ __(HealthPayment::kindLabelKey($p->kind)) }}</td>
            <td>{{ __(HealthPayment::methodLabelKey($p->method)) }}</td>
            <td class="small">{{ optional($bills->firstWhere('id', $p->health_bill_id))->bill_no ?: '—' }}</td>
            <td class="num bold">{{ $p->isInflow() ? '' : '-' }}{{ $money($p->amount) }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="center muted">{{ __('health.pay_none_yet') }}</td></tr>
    @endforelse
    </tbody>
</table>

@if($account['unbilled']->isNotEmpty())
    <h2>{{ __('health.led_unbilled') }}</h2>
    <table>
        <thead>
        <tr>
            <th>{{ __('health.led_charge_no') }}</th>
            <th>{{ __('health.date') }}</th>
            <th>{{ __('health.description') }}</th>
            <th>{{ __('health.tax_treatment') }}</th>
            <th class="num">{{ __('health.total') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($account['unbilled'] as $c)
            <tr>
                <td>{{ $c->charge_no }}</td>
                <td>{{ optional($c->charge_date)->format('d M Y') }}</td>
                <td>{{ $c->description }} <span class="small muted">({{ __(HealthCharge::categoryLabelKey($c->category)) }})</span></td>
                <td class="small">{{ __(HealthTaxCategory::treatmentLabelKey($c->tax_treatment)) }}</td>
                <td class="num">{{ $money($c->total_amount) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="row bold" style="margin-top:6px">
        <span>{{ __('health.total') }}</span>
        <span>{{ $money($account['unbilled_totals']['total']) }}</span>
    </div>
@endif

<div class="rule"></div>
<div class="center small muted">{{ __('health.stmt_footer') }}</div>

</body>
</html>
