@php
    $imsSnapshot = json_decode($imsReceipt->fiscal_route_snapshot ?? 'null', true);
    $imsEligible = ($imsSnapshot['route'] ?? null) === 'ims' && !empty($imsSnapshot['eligible']);
    $imsState = $imsReceipt->fbr_status ?? null;
@endphp
@if(!($preview ?? false))
    <div class="no-print">
        @if(($showImsFlash ?? true) && session('ims_status'))
            <p role="status" class="notice">{{ session('ims_status') }}</p>
        @endif
        @if($imsEligible && empty($imsReceipt->fbr_pos_transaction_id))
            <p class="notice">IMS was selected for this receipt. The submission queue entry has not been saved.</p>
        @elseif(in_array($imsState, ['failed', 'config_error'], true))
            <p class="notice">IMS submission failed. Check setup and retry this receipt.</p>
        @endif
        @if($imsEligible && $imsState === 'verification_pending' && !empty($imsReceipt->fbr_pos_transaction_id)
            && empty($imsReceipt->fbr_invoice_number) && auth('fbrpos')->user()?->role === 'company_admin'
            && !session('impersonation.readonly'))
            <a href="{{ route('fbrpos.reconcile', $imsReceipt->fbr_pos_transaction_id) }}">Record existing FBR invoice number</a>
        @endif
        @if($imsEligible && empty($imsReceipt->fbr_invoice_number)
            && (empty($imsReceipt->fbr_pos_transaction_id) || in_array($imsState, ['failed', 'config_error'], true))
            && app(\App\Services\BranchContextService::class)->isOwner(auth('fbrpos')->user())
            && !session('impersonation.readonly'))
            <form method="post" action="{{ route($imsRetryRoute, $imsReceipt->id) }}">
                @csrf
                <button type="submit">Retry FBR IMS</button>
            </form>
        @endif
    </div>
@endif
