@if($bundle)
    @if($bundle['federal'])
        <span class="block">{{ \App\Services\PosAddonPricingService::definitions()[$bundle['federal']]['label'] }} · PKR {{ number_format($bundle['federal_amount'], 2) }}</span>
    @endif
    @if($bundle['branch_slots'] > 0)
        <span class="block">{{ __('pos_federal_addons.branch_summary', ['count' => $bundle['branch_slots']]) }} · PKR {{ number_format($bundle['branch_amount'], 2) }}</span>
    @endif
@endif
