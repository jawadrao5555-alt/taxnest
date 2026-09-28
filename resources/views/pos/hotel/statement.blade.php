<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('hotel_bill.statement') }} · {{ $stay->stay_number }}</title>
    @if(empty($agentPrint))
        @include('partials.urdu-font')
    @endif
    <style>
        @if(app()->getLocale() === 'ur')
            @include('partials.urdu-print-font')
        @endif
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9edf1; color: #161b22; font: 14px Arial, sans-serif; }
        .toolbar { max-width: 760px; margin: 16px auto; padding: 12px; background: white; border-radius: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .toolbar a, .toolbar button { color: #134357; font: inherit; background: white; border: 1px solid #c7d2db; border-radius: 6px; padding: 8px 10px; text-decoration: none; cursor: pointer; }
        .toolbar .selected, .toolbar button.primary { color: white; background: #0f766e; border-color: #0f766e; }
        .toolbar button:disabled { opacity: .55; cursor: wait; }
        .toolbar .status { flex-basis: 100%; min-height: 18px; font-size: 12px; }
        .bill { background: white; width: min(100% - 24px, {{ $paper === 'a4' ? '720px' : ($paper === '58mm' ? '58mm' : '80mm') }}); margin: 16px auto; padding: {{ $paper === 'a4' ? '28px' : '4mm' }}; box-shadow: 0 2px 12px #0002; overflow-wrap: anywhere; }
        .bill.thermal { font-size: {{ $paper === '58mm' ? '10px' : '11px' }}; }
        @if(app()->getLocale() === 'ur')
        .bill { font-family: 'Jameel Noori Nastaleeq', Arial, sans-serif; }
        @endif
        .brand { text-align: center; border-bottom: 2px solid #20252a; padding-bottom: 12px; }
        .brand strong { display: block; font-size: {{ $paper === 'a4' ? '21px' : '14px' }}; }
        .brand h1 { margin: 7px 0 0; font-size: {{ $paper === 'a4' ? '18px' : '13px' }}; }
        .muted { color: #4b5563; }
        .meta { display: flex; justify-content: space-between; gap: 6px; padding: 4px 0; }
        .meta span:last-child { text-align: right; font-weight: 600; }
        .details { padding: 10px 0; border-bottom: 1px dashed #555; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; table-layout: fixed; }
        td, th { padding: 6px 0; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        td:last-child, th:last-child { text-align: right; width: 39%; }
        .totals { margin: 12px 0; border-top: 2px solid #20252a; padding-top: 5px; }
        .totals .meta:last-child { border-top: 1px dashed #555; padding-top: 8px; font-size: 1.15em; }
        .note { border-top: 1px dashed #777; padding-top: 10px; font-size: .85em; line-height: 1.4; }
        @if($paper === 'a4')
        @page { size: A4; margin: 14mm; }
        @elseif($paper === '58mm')
        @page { size: 58mm auto; margin: 0; }
        @else
        @page { size: 80mm auto; margin: 0; }
        @endif
        @media print {
            html, body { margin: 0; padding: 0; background: white; }
            .toolbar { display: none !important; }
            .bill { margin: 0 auto; box-shadow: none; border-radius: 0; }
            @if($paper !== 'a4')
            .bill { width: {{ $paper }}; max-width: {{ $paper }}; margin: 0; padding: 3mm 4mm; }
            @endif
        }
    </style>
</head>
<body>
@if(empty($agentPrint))
<nav class="toolbar" aria-label="{{ __('hotel_bill.paper_size') }}">
    <span>{{ __('hotel_bill.paper_size') }}:</span>
    @foreach(['a4' => 'A4', '80mm' => '80mm', '58mm' => '58mm'] as $value => $label)
        <a class="{{ $paper === $value ? 'selected' : '' }}" href="{{ route('pos.hotel.stays.statement', ['id' => $stay->id, 'paper' => $value]) }}">{{ $label }}</a>
    @endforeach
    <button type="button" class="primary" onclick="window.print()">{{ __('hotel_bill.print') }}</button>
    @if($paper !== 'a4')
        <button type="button" id="silent-print">{{ __('hotel_bill.silent_print') }}</button>
    @endif
    <a href="{{ route('pos.hotel.stays.show', $stay->id) }}">{{ __('hotel_bill.back') }}</a>
    <a href="{{ route('pos.hotel.folios') }}">{{ __('pos.hotel_menu_bills') }}</a>
    <span class="status" id="print-status" role="status" aria-live="polite"></span>
</nav>
@endif
<main class="bill {{ $paper === 'a4' ? 'a4' : 'thermal' }}" data-hotel-statement="1">
    <header class="brand">
        <strong>{{ $company->name }}</strong>
        <h1>{{ __('hotel_bill.statement') }}</h1>
        <div class="muted">{{ __('hotel_bill.number') }} #{{ $stay->stay_number }} · {{ \App\Services\HotelShell::statusLabel($stay->status) }}</div>
    </header>
    <section class="details">
        <div class="meta"><span>{{ __('pos.hotel_guest') }}</span><span>{{ $stay->guest_name }}</span></div>
        <div class="meta"><span>{{ __('pos.hotel_room') }}</span><span>{{ $stay->room?->room_number }}</span></div>
        <div class="meta"><span>{{ __('pos.hotel_check_in') }}</span><span>{{ $stay->check_in_date->format('d M Y') }}</span></div>
        <div class="meta"><span>{{ __('pos.hotel_check_out') }}</span><span>{{ $stay->check_out_date->format('d M Y') }}</span></div>
        <div class="meta"><span>{{ __('pos.hotel_nights') }}</span><span>{{ $stay->nights }}</span></div>
        <div class="meta"><span>{{ __('pos.hotel_agreed_rate') }}</span><span>Rs {{ number_format((float) $stay->rate_amount, 2) }}</span></div>
    </section>
    <table>
        <thead><tr><th>{{ __('pos.hotel_folio') }}</th><th>{{ __('pos.hotel_amount') }}</th></tr></thead>
        <tbody>
        @foreach($stay->folioEntries->whereIn('entry_type', ['charge', 'adjustment']) as $entry)
            <tr><td>{{ $entry->description }}</td><td>Rs {{ number_format((float) $entry->amount, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <section class="totals">
        <div class="meta"><span>{{ __('pos.hotel_total') }}</span><span>Rs {{ number_format((float) $summary['total_stay'], 2) }}</span></div>
        <div class="meta"><span>{{ __('pos.hotel_folio_paid') }}</span><span>Rs {{ number_format((float) ($totals['payments'] - $totals['refunds']), 2) }}</span></div>
        <div class="meta"><span>{{ __('pos.hotel_folio_due') }}</span><span>Rs {{ number_format((float) $summary['balance'], 2) }}</span></div>
    </section>
    <footer class="note">{{ __('hotel_bill.not_fiscal') }} {{ __('hotel_bill.cash_estimate') }}</footer>
</main>
@if(empty($agentPrint))
@php $receiptIds = $stay->folioEntries->pluck('pos_transaction_id')->filter()->unique(); @endphp
@if($receiptIds->isNotEmpty())
<div class="toolbar">
    @foreach($receiptIds as $receiptId)
        <a href="{{ route('pos.receipt', $receiptId) }}" target="_blank" rel="noopener">{{ __('hotel_bill.issued_receipt') }} #{{ $loop->iteration }}</a>
    @endforeach
</div>
@endif
@if($paper !== 'a4')
<script>
(() => {
    const button = document.getElementById('silent-print');
    const status = document.getElementById('print-status');
    let attempt = null;
    button.addEventListener('click', async () => {
        attempt ??= crypto.randomUUID();
        button.disabled = true;
        status.textContent = @json(__('hotel_bill.sending'));
        try {
            const response = await fetch(@json(route('pos.hotel.stays.statement.silent-print', $stay->id)), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                body: JSON.stringify({ paper: @json($paper), print_attempt_uuid: attempt }),
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                if (response.status === 409) attempt = null;
                throw new Error(result.reason || @json(__('hotel_bill.unavailable')));
            }
            status.textContent = @json(__('hotel_bill.queued')) + ' #' + result.job_id;
            attempt = null;
        } catch (error) {
            status.textContent = @json(__('hotel_bill.unavailable')) + ': ' + error.message;
        } finally {
            button.disabled = false;
        }
    });
})();
</script>
@endif
@if(request()->boolean('print'))
<script>window.addEventListener('load', () => window.print(), { once: true });</script>
@endif
@endif
</body>
</html>
