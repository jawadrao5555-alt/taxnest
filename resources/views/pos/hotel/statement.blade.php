<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('hotel_bill.statement') }} · {{ $stay->stay_number }}</title>
    @include('partials.urdu-font')
    <style>
        body { font: 14px system-ui, sans-serif; color: #172033; margin: 0; background: #f4f6f7; }
        .sheet { max-width: 700px; margin: 24px auto; padding: 28px; background: white; border-radius: 12px; }
        .actions { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .actions a, .actions button { border: 1px solid #ced6dc; border-radius: 7px; background: white; padding: 9px 14px; color: #123b50; cursor: pointer; text-decoration: none; font: inherit; }
        .actions button { background: #0f766e; color: white; border-color: #0f766e; }
        h1 { margin: 8px 0; font-size: 24px; }
        .muted { color: #586778; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px 20px; margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border-bottom: 1px solid #e5e9ed; padding: 9px 4px; text-align: left; }
        .right { text-align: right; }
        .totals { margin: 20px 0 0 auto; max-width: 290px; }
        .totals p { display: flex; justify-content: space-between; gap: 15px; margin: 8px 0; }
        .notice { background: #fff8e8; padding: 10px; border-radius: 8px; margin-top: 20px; }
        @media print { body { background: white; } .sheet { margin: 0; max-width: none; padding: 0; } .actions { display: none; } }
    </style>
</head>
<body>
<div class="sheet" data-hotel-statement="1">
    <div class="actions">
        <button type="button" onclick="window.print()">{{ __('hotel_bill.print') }}</button>
        <a href="{{ route('pos.hotel.stays.show', $stay->id) }}">{{ __('hotel_bill.back') }}</a>
        <a href="{{ route('pos.hotel.folios') }}">{{ __('pos.hotel_menu_bills') }}</a>
    </div>
    <strong>{{ $stay->company?->name ?? auth('pos')->user()?->company?->name }}</strong>
    <h1>{{ __('hotel_bill.statement') }}</h1>
    <p class="muted">{{ __('hotel_bill.number') }} {{ $stay->stay_number }} · {{ \App\Services\HotelShell::statusLabel($stay->status) }}</p>
    <div class="grid">
        <div>{{ __('pos.hotel_guest') }}: <strong>{{ $stay->guest_name }}</strong></div>
        <div>{{ __('pos.hotel_room') }}: <strong>{{ $stay->room?->room_number }}</strong></div>
        <div>{{ __('pos.hotel_check_in') }}: {{ $stay->check_in_date->format('d M Y') }}</div>
        <div>{{ __('pos.hotel_check_out') }}: {{ $stay->check_out_date->format('d M Y') }}</div>
        <div>{{ __('pos.hotel_nights') }}: {{ $stay->nights }}</div>
        <div>{{ __('pos.hotel_agreed_rate') }}: Rs {{ number_format((float) $stay->rate_amount, 2) }}</div>
    </div>
    <table>
        <thead><tr><th>{{ __('pos.hotel_folio') }}</th><th class="right">{{ __('pos.hotel_amount') }}</th></tr></thead>
        <tbody>
        @foreach($stay->folioEntries->whereIn('entry_type', ['charge', 'adjustment']) as $entry)
            <tr><td>{{ $entry->description }}</td><td class="right">Rs {{ number_format((float) $entry->amount, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <div class="totals">
        <p><span>{{ __('pos.hotel_total') }}</span><strong>Rs {{ number_format((float) $summary['total_stay'], 2) }}</strong></p>
        <p><span>{{ __('pos.hotel_folio_paid') }}</span><strong>Rs {{ number_format((float) ($totals['payments'] - $totals['refunds']), 2) }}</strong></p>
        <p><span>{{ __('pos.hotel_folio_due') }}</span><strong>Rs {{ number_format((float) $summary['balance'], 2) }}</strong></p>
    </div>
    <p class="notice">{{ __('hotel_bill.not_fiscal') }} {{ __('hotel_bill.cash_estimate') }}</p>
    @php $receiptIds = $stay->folioEntries->pluck('pos_transaction_id')->filter()->unique(); @endphp
    @if($receiptIds->isNotEmpty())
    <div class="actions">
        @foreach($receiptIds as $receiptId)
        <a href="{{ route('pos.receipt', $receiptId) }}" target="_blank" rel="noopener">{{ __('hotel_bill.issued_receipt') }} #{{ $loop->iteration }}</a>
        @endforeach
    </div>
    @endif
</div>
@if(request()->boolean('print'))
<script>window.addEventListener('load', () => window.print(), { once: true });</script>
@endif
</body>
</html>
