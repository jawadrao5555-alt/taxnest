@php
    $account = $accountOverview;
    $sub = $account['current'];
    $date = fn ($value) => $value ? $value->format('d M Y, H:i T') : 'Not recorded';
    $price = fn ($row) => $row->getRawOriginal('final_price') === null ? 'Not recorded' : 'PKR '.number_format((float) $row->final_price, 2);
    $note = fn ($metadata, $key) => is_string($metadata[$key] ?? null) && trim($metadata[$key]) !== '' ? $metadata[$key] : 'Not recorded';
    $panelClass = 'tn-account-panel rounded-xl border p-4 sm:p-5';
@endphp
<section id="company-account-overview" data-company-account data-company-control="{{ $account['control'] }}" data-subscription-allowed="{{ $account['access']['allowed'] === null ? 'unknown' : ($account['access']['allowed'] ? 'yes' : 'no') }}" class="mb-6 space-y-4 min-w-0">
    <div class="{{ $panelClass }}">
        <h2 class="text-lg font-bold">Subscription and access</h2>
        <p class="text-sm mt-1">As of {{ $date($account['as_of']) }}. Company control, subscription allowance and payment evidence are separate.</p>
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 text-sm">
            <div><dt class="font-semibold">Company control</dt><dd data-company-control-label class="break-words">{{ ucfirst($account['control']) }} — approval status: {{ $company->status ?? 'Not recorded' }}; company status: {{ $company->company_status ?? 'Not recorded' }}.</dd>
                @if($account['control'] === 'suspended')<dd class="font-semibold">Company-wide suspension remains in place even when a subscription or grant is valid.</dd>@endif
                @if($account['control'] === 'pending')<dd>Approval is pending; product-specific read-only and login rules still apply.</dd>@endif
            </div>
            <div><dt class="font-semibold">Subscription decision</dt><dd data-subscription-decision>{{ $account['access']['allowed'] === null ? 'Unavailable' : ($account['access']['allowed'] ? 'Allowed by subscription rules' : 'Locked by subscription rules') }}</dd><dd class="break-words">{{ $account['access']['reason'] }}</dd></div>
            <div><dt class="font-semibold">Internal account</dt><dd>{{ $company->is_internal_account ? 'Yes — existing internal-account exceptions apply where supported; this does not remove company suspension.' : 'No' }}</dd></div>
            <div><dt class="font-semibold">Recorded branches</dt><dd>{{ $account['branches'] === null ? 'Unavailable' : number_format($account['branches']) }}</dd></div>
        </dl>
        <p class="text-sm mt-3">These are account-level checks, not a guarantee that every action is enabled. Each product still enforces its roles, features, quotas, login rules and system restrictions.</p>
        @if(($account['active_count'] ?? 0) > 1)
        <p role="alert" class="mt-3 font-semibold">{{ $account['active_count'] }} active subscription rows need review. The newest active row below is the one used by SubscriptionAccessService. No rows were changed.</p>
        @endif
        @if($sub)
        <dl data-current-subscription="{{ $sub->id }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5 text-sm">
            <div><dt class="font-semibold">Linked package</dt><dd class="break-words">{{ $sub->pricingPlan?->name ?? 'Plan record unavailable' }} (subscription #{{ $sub->id }})</dd></div>
            <div><dt class="font-semibold">Record status / billing cycle</dt><dd>{{ $sub->active ? 'Active row' : 'Inactive row' }} / {{ $sub->billing_cycle ? ucfirst(str_replace('_', ' ', $sub->billing_cycle)) : 'Not recorded' }}</dd></div>
            <div><dt class="font-semibold">Stored period start</dt><dd data-account-start>{{ $date($sub->start_date) }}</dd></div>
            <div><dt class="font-semibold">Stored period end / renewal review</dt><dd data-account-end>{{ $date($sub->end_date) }}</dd></div>
            <div><dt class="font-semibold">Stored subscription price</dt><dd data-account-price>{{ $price($sub) }}</dd></div>
            <div><dt class="font-semibold">Trial until</dt><dd>{{ $date($sub->trial_ends_at) }}</dd></div>
            <div><dt class="font-semibold">Recorded grant</dt><dd>{{ $sub->override_type ?: 'None recorded' }}; granted: {{ $date($sub->override_granted_at) }}; until: {{ $date($sub->override_until) }}</dd></div>
            <div><dt class="font-semibold">Recorded grant note</dt><dd class="whitespace-pre-line break-words">{{ $sub->override_reason ?: 'Not recorded' }}</dd></div>
        </dl>
        <p class="text-sm mt-3">Dates and price come from the stored subscription, not today's package price. Package names follow the linked catalogue; historical names are not reconstructed. A blank price is not treated as zero. Renewal review uses the recorded end date; no automatic renewal or extra grace is implied. Grant notes do not override the actual rules.</p>
        @else
        <p class="mt-4 font-semibold">{{ $account['subscriptions_available'] ? 'No active subscription row. Historical rows, if any, remain below.' : 'Subscription records are unavailable.' }}</p>
        @endif
        @if($account['control'] === 'suspended')
        @php $suspension = $account['suspension']; @endphp
        <div data-current-suspension class="mt-4 rounded-lg border p-3 text-sm">
            <h3 class="font-bold">Recorded suspension</h3>
            <p>Company timestamp: {{ $date($company->suspended_at) }}</p>
            <p>Actor: {{ $suspension?->admin?->name ?? 'Not recorded for this suspension' }}</p>
            <p class="whitespace-pre-line break-words">Reason: {{ $suspension ? $note($suspension->metadata, 'reason') : 'Not recorded for this suspension' }}</p>
            <p class="whitespace-pre-line break-words">Restoration condition: {{ $suspension ? $note($suspension->metadata, 'restoration_condition') : 'Not recorded for this suspension' }}</p>
            <p class="mt-1">Details require a matching recorded suspension timestamp. Older or missing reasons are not reconstructed. Activation is a separate manual action and does not extend the subscription.</p>
        </div>
        @endif
    </div>

    <details data-account-history class="{{ $panelClass }}">
        <summary class="font-bold cursor-pointer">Subscription history</summary>
        <p class="text-sm mt-2">Active is a stored row flag, not proof of a valid term. Expired, inactive and historical prices remain visible.</p>
        <div class="overflow-x-auto mt-3" role="region" tabindex="0" aria-label="Company subscription history">
            <table class="w-full text-sm"><thead><tr class="text-left"><th class="p-2">Record / package</th><th class="p-2">Flag / cycle</th><th class="p-2">Period</th><th class="p-2">Trial / grant</th><th class="p-2">Stored price</th></tr></thead><tbody>
            @forelse($account['history'] as $row)
                <tr class="border-t"><td class="p-2">#{{ $row->id }} {{ $row->pricingPlan?->name ?? 'Plan record unavailable' }}</td><td class="p-2">{{ $row->active ? 'Active row' : 'Inactive row' }} / {{ $row->billing_cycle ?: 'Not recorded' }}</td><td class="p-2">{{ $date($row->start_date) }} → {{ $date($row->end_date) }}</td><td class="p-2">Trial: {{ $date($row->trial_ends_at) }}; {{ $row->override_type ?: 'None recorded' }} until {{ $date($row->override_until) }}</td><td class="p-2">{{ $price($row) }}</td></tr>
            @empty<tr><td colspan="5" class="p-3">{{ $account['subscriptions_available'] ? 'No subscription records.' : 'Subscription records unavailable.' }}</td></tr>@endforelse
            </tbody></table>
        </div>
        @if($account['subscriptions_available'])<div class="mt-3">{{ $account['history']->fragment('company-account-overview')->links() }}</div>@endif
    </details>

    <details data-account-payments class="{{ $panelClass }}">
        <summary class="font-bold cursor-pointer">Charges, recorded payments and payment proofs</summary>
        <p class="text-sm mt-2">These are TaxNest subscription records for this company, not the shop's sales. A proof amount or verification status alone does not establish payment allocation, settlement or a reconciled balance.</p>
        <h3 class="font-semibold mt-4">Recorded subscription charges</h3>
        <div class="overflow-x-auto" role="region" tabindex="0" aria-label="Company subscription charges"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-2">Charge</th><th class="p-2">Amount</th><th class="p-2">Status</th><th class="p-2">Due / paid</th></tr></thead><tbody>
        @forelse($account['charges'] as $charge)<tr class="border-t"><td class="p-2">#{{ $charge->id }} / subscription #{{ $charge->subscription_id }}</td><td class="p-2">PKR {{ number_format($charge->amount, 2) }}</td><td class="p-2">{{ $charge->status }}</td><td class="p-2">{{ $date($charge->due_date) }} / {{ $date($charge->paid_at) }}</td></tr>@empty<tr><td colspan="4" class="p-3">{{ $account['charges_available'] ? 'No recorded charges.' : 'Charge records unavailable.' }}</td></tr>@endforelse
        </tbody></table></div>
        @if($account['charges_available']){{ $account['charges']->fragment('company-account-overview')->links() }}@endif
        <h3 class="font-semibold mt-4">Recorded payments</h3>
        <div class="overflow-x-auto" role="region" tabindex="0" aria-label="Company recorded payments"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-2">Payment / charge</th><th class="p-2">Amount</th><th class="p-2">Method / reference</th><th class="p-2">Paid at</th></tr></thead><tbody>
        @forelse($account['payments'] as $payment)<tr class="border-t"><td class="p-2">#{{ $payment->id }} / #{{ $payment->subscription_invoice_id }}</td><td class="p-2">PKR {{ number_format($payment->amount, 2) }}</td><td class="p-2 break-all">{{ $payment->payment_method ?: 'Not recorded' }} / {{ $payment->transaction_ref ?: 'Not recorded' }}</td><td class="p-2">{{ $date($payment->paid_at) }}</td></tr>@empty<tr><td colspan="4" class="p-3">{{ $account['payments_available'] ? 'No recorded payments.' : 'Payment records unavailable.' }}</td></tr>@endforelse
        </tbody></table></div>
        @if($account['payments_available']){{ $account['payments']->fragment('company-account-overview')->links() }}@endif
        <h3 class="font-semibold mt-4">Payment proofs</h3>
        <a data-company-proofs-link class="inline-block underline font-semibold text-sm my-2" href="{{ route('saas.admin.payment-proofs', ['company_id' => $company->id, 'status' => 'all']) }}">Open this company's payment proofs</a>
        <div class="overflow-x-auto" role="region" tabindex="0" aria-label="Company payment proofs"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-2">Proof / request</th><th class="p-2">Amount / method</th><th class="p-2">Reference / paid on</th><th class="p-2">Verification</th></tr></thead><tbody>
        @forelse($account['proofs'] as $proof)<tr class="border-t"><td class="p-2">#{{ $proof->id }} / {{ $proof->request_type ?: 'subscription' }}</td><td class="p-2">PKR {{ number_format((float) $proof->amount, 2) }} / {{ $proof->payment_method ?: 'Not recorded' }}</td><td class="p-2 break-all">{{ $proof->reference ?: 'Not recorded' }} / {{ $date($proof->payment_date) }}</td><td class="p-2">{{ $proof->status }}; admin {{ $proof->verified_by ?: 'not recorded' }}; {{ $date($proof->verified_at) }}</td></tr>@empty<tr><td colspan="4" class="p-3">{{ $account['proofs_available'] ? 'No payment proofs recorded.' : 'Payment-proof records unavailable.' }}</td></tr>@endforelse
        </tbody></table></div>
        @if($account['proofs_available']){{ $account['proofs']->fragment('company-account-overview')->links() }}@endif
    </details>

    <details data-account-audit class="{{ $panelClass }}">
        <summary class="font-bold cursor-pointer">Admin account history</summary>
        <p class="text-sm mt-2">Company and subscription admin events only. Viewing/impersonation events are admin activity, not proof of company staff work. Missing legacy actors, reasons or state snapshots stay unknown.</p>
        <div class="mt-3 space-y-3">
        @forelse($account['events'] as $event)
            <article data-account-event="{{ $event->id }}" class="border-t pt-3 text-sm min-w-0">
                <h3 class="font-semibold break-words">{{ $event->action }}</h3>
                <p>{{ $date($event->created_at) }} — {{ $event->admin?->name ?? 'Admin record unavailable' }} (admin #{{ $event->admin_id ?? 'unknown' }}); {{ $event->target_type }} #{{ $event->target_id }}</p>
                @if(in_array($event->action, \App\Services\CompanyAccountStateService::ACTIONS, true))
                <p class="whitespace-pre-line break-words">Reason: {{ $note($event->metadata, 'reason') }}</p>
                @if($event->action === 'Company suspended')<p class="whitespace-pre-line break-words">Restoration condition: {{ $note($event->metadata, 'restoration_condition') }}</p>@endif
                @if(is_array($event->metadata['before'] ?? null) && is_array($event->metadata['after'] ?? null))
                <p>Company state: {{ $note($event->metadata['before'], 'status') }} / {{ $note($event->metadata['before'], 'company_status') }} → {{ $note($event->metadata['after'], 'status') }} / {{ $note($event->metadata['after'], 'company_status') }}.</p>
                @else<p>Prior state snapshot not recorded.</p>@endif
                @endif
            </article>
        @empty<p class="text-sm">{{ $account['audit_available'] ? 'No admin account events recorded.' : 'Admin account history unavailable.' }}</p>@endforelse
        </div>
        @if($account['audit_available'])<div class="mt-3">{{ $account['events']->fragment('company-account-overview')->links() }}</div>@endif
    </details>
</section>
