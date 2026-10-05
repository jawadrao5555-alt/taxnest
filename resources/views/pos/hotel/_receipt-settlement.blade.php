@if(($transaction->hotel_money_from_folio ?? false) && ($transaction->transaction_type ?? 'sale') !== 'return')
@php
    $settlementStayId = \App\Models\HotelFolioEntry::where('company_id', $transaction->company_id)->where('pos_transaction_id', $transaction->id)->value('stay_id');
    $settlementStay = $settlementStayId ? \App\Models\HotelStay::where('company_id', $transaction->company_id)->find($settlementStayId) : null;
    $settlementSummary = $settlementStay ? app(\App\Services\HotelDeskService::class)->summary($settlementStay, $transaction->payment_method) : null;
@endphp
@if($settlementSummary)
<tr><td class="tot-label">{{ __('hotel_preview.stay_paid') }}:</td><td class="tot-value">PKR {{ number_format($settlementSummary['paid'], 2) }}</td></tr>
<tr><td class="tot-label">{{ __('hotel_preview.stay_balance') }}:</td><td class="tot-value">PKR {{ number_format($settlementSummary['balance'], 2) }}</td></tr>
@endif
@endif
