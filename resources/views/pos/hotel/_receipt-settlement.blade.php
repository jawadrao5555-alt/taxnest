@if(($transaction->hotel_money_from_folio ?? false) && ($transaction->transaction_type ?? 'sale') !== 'return')
@php
    $settlementStayId = \App\Models\HotelFolioEntry::where('company_id', $transaction->company_id)->where('pos_transaction_id', $transaction->id)->value('stay_id');
    $settlementStay = $settlementStayId ? \App\Models\HotelStay::where('company_id', $transaction->company_id)->find($settlementStayId) : null;
    $settlementViewer = auth('pos')->user();
    $settlementVisible = true;
    if ($settlementStay && $settlementViewer) {
        $settlementIds = \App\Models\HotelFolioEntry::where('company_id', $transaction->company_id)
            ->where('stay_id', $settlementStay->id)->pluck('pos_transaction_id')->filter()->unique();
        $settlementBills = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $transaction->company_id)->whereIn('id', $settlementIds)->get();
        $settlementVisible = (int) $settlementViewer->company_id === (int) $transaction->company_id
            && $settlementBills->count() === $settlementIds->count()
            && $settlementBills->every(fn ($bill) => $bill->allowedForBillingScopeOf($settlementViewer) && $bill->allowedForCashierIsolationOf($settlementViewer));
    }
    // Existing trusted internal/Agent renderers have no POS viewer; their company-
    // scoped invoice path is unchanged. Partial-scope web viewers see only their bill.
    $settlementSummary = $settlementStay && $settlementVisible ? app(\App\Services\HotelDeskService::class)->summary($settlementStay, $transaction->payment_method) : null;
@endphp
@if($settlementSummary)
<tr data-hotel-receipt-money="1"><td class="tot-label">{{ __('hotel_preview.stay_paid') }}:</td><td class="tot-value">PKR {{ number_format($settlementSummary['paid'], 2) }}</td></tr>
<tr data-hotel-receipt-money="1"><td class="tot-label">{{ __('hotel_preview.stay_balance') }}:</td><td class="tot-value">PKR {{ number_format($settlementSummary['balance'], 2) }}</td></tr>
@endif
@endif

