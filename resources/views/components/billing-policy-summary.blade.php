@props(['terms' => null, 'unavailable' => false])
<section data-billing-policy-summary data-policy-version="{{ $unavailable ? 'unavailable' : ($terms['version'] ?? 'legacy') }}" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 space-y-3 text-sm text-gray-800 dark:text-gray-100">
    <h3 class="font-semibold">{{ __('billing_policy.heading') }}</h3>
    @if($unavailable)
        <p data-policy-unavailable role="alert">{{ __('billing_policy.unavailable') }}</p>
    @elseif($terms === null)
        <p data-policy-legacy>{{ __('billing_policy.legacy_note') }}</p>
    @else
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><dt class="font-semibold">{{ __('billing_policy.version') }}</dt><dd>{{ $terms['version'] }}</dd></div>
            <div><dt class="font-semibold">{{ __('billing_policy.plan') }}</dt><dd>{{ $terms['selected_plan_name'] ?? __('billing_policy.not_recorded') }}</dd></div>
            <div><dt class="font-semibold">{{ __('billing_policy.state') }}</dt><dd>{{ __('billing_policy.phase_'.($terms['phase'] ?? 'unknown')) }}</dd></div>
            @foreach(['trial_starts_at'=>'trial_start','trial_ends_at'=>'trial_end','paid_starts_at'=>'paid_start','paid_ends_at'=>'paid_end','grace_ends_at'=>'grace_end'] as $field=>$label)
                <div><dt class="font-semibold">{{ __('billing_policy.'.$label) }}</dt><dd data-policy-date="{{ $field }}">@if(!empty($terms[$field]))<time datetime="{{ $terms[$field] }}">{{ $terms[$field] }}</time>@else{{ __('billing_policy.not_recorded') }}@endif</dd></div>
            @endforeach
        </dl>
        <p>{{ __('billing_policy.scope') }}</p>
        <p>{{ __('billing_policy.payment_rule') }}</p>
        <p>{{ __('billing_policy.grace_rule') }}</p>
    @endif
</section>
