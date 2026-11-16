@if(isset($policySubscription) && $policySubscription)
    @php
        $policyTerms = \App\Services\ProspectiveSubscriptionPolicy::display($policySubscription);
        $policyUnavailable = $policyTerms === null && $policySubscription->hasLifecycleSnapshot();
    @endphp
    <x-billing-policy-summary :terms="$policyTerms" :unavailable="$policyUnavailable" />
@endif
