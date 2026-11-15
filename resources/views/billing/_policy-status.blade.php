@if(isset($policySubscription) && $policySubscription)
    <x-billing-policy-summary :terms="\App\Services\ProspectiveSubscriptionPolicy::display($policySubscription)" />
@endif
