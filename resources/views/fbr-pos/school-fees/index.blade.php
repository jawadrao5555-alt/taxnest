@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('pos.school_nav_collect') }}</h1>
<p class="muted">{{ __('pos.school_fee_desk') }}</p>
<p class="muted">{{ __('pos.school_pilot_scope') }}</p>
<div class="notice">{{ __('pos.fbr_missing_school_route') }} {{ __('pos.school_separate_ledger') }}</div>
<section class="card">
    <h2>{{ __('pos.school_collect_cash') }}</h2>
    @if($collectionClosed)<div role="alert" class="notice">{{ __('pos.school_closed_collection') }}</div>@endif
    @if($errors->any())<div role="alert" class="error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div id="school-recovery-notice" class="notice" role="alert" hidden></div>
    <form method="post" action="/fbr-pos/school-fees" id="school-collection" data-closed="{{ $collectionClosed ? '1' : '0' }}">
        @csrf
        <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
        <input type="hidden" name="payment_method" value="cash">
        <input type="hidden" name="expected_branch_key" value="{{ $schoolCampusId ?? 0 }}">
        <div class="grid">
            <div><label for="student_reference">{{ __('pos.school_student_reference') }}</label><input id="student_reference" name="student_reference" value="{{ old('student_reference') }}" maxlength="100" required autocomplete="off"><div class="muted">{{ __('pos.school_reference_hint') }}</div></div>
            <div><label for="fee_head">{{ __('pos.school_fee_head') }}</label><select id="fee_head" name="fee_head" required>@foreach(['tuition','admission','exam'] as $head)<option value="{{ $head }}" @selected(old('fee_head', 'tuition') === $head)>{{ __('pos.school_head_'.$head) }}</option>@endforeach</select></div>
            <div><label for="amount">{{ __('pos.school_amount') }} (PKR)</label><input id="amount" name="amount" type="number" min="0.01" max="99999999.99" step="0.01" value="{{ old('amount') }}" required inputmode="decimal"></div>
        </div>
        <label class="cash-confirm"><input type="checkbox" name="cash_confirmed" value="1" required @disabled($collectionClosed)> <span>{{ __('pos.school_confirm_cash') }}</span></label>
        <div class="actions"><button type="submit" @disabled($collectionClosed)>{{ __('pos.school_collect_cash') }}</button><span class="muted">{{ __('pos.school_cash_only') }}</span></div>
    </form>
</section>
<section class="card"><h2>{{ __('pos.school_receipt_history') }}</h2><div class="scroll">
<table><thead><tr><th>{{ __('pos.school_receipt') }}</th><th>{{ __('pos.school_student_reference') }}</th><th>{{ __('pos.school_fee_head') }}</th><th>PKR</th><th>{{ __('pos.school_collected_at') }}</th></tr></thead><tbody>
@forelse($receipts as $receipt)
<tr><td><a href="/fbr-pos/school-fees/{{ $receipt->id }}/receipt">SF-{{ $receipt->id }}</a></td><td>{{ $receipt->student_reference }}</td><td>{{ __('pos.school_head_'.$receipt->fee_head) }}</td><td>{{ number_format($receipt->amount_paisa / 100, 2) }}</td><td>{{ $receipt->collected_at }}</td></tr>
@empty<tr><td colspan="5">{{ __('pos.school_no_receipts') }}</td></tr>@endforelse
</tbody></table></div>{{ $receipts->links() }}</section>
<script>
(() => {
    const form = document.getElementById('school-collection');
    const button = form.querySelector('button');
    const confirmation = form.querySelector('[name="cash_confirmed"]');
    const key = form.querySelector('[name="request_key"]');
    const notice = document.getElementById('school-recovery-notice');
    const storageKey = @json('taxnest.school.pending.'.$company->id.'.'.($schoolCampusId ?? 0).'.'.auth('fbrpos')->id());
    const recoveryMessage = @json(__('pos.school_pending_recovery'));
    const storageMessage = @json(__('pos.school_pending_storage_unavailable'));
    let pending = null;
    let storageReady = true;
    let submitting = false;
    const show = message => { notice.textContent = message; notice.hidden = false; };
    try {
        pending = sessionStorage.getItem(storageKey);
        if (pending) {
            if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(pending)) throw new Error('Invalid recovery key');
            key.value = pending;
            show(recoveryMessage);
        }
    } catch (_) { storageReady = false; show(storageMessage); }
    const updateControls = () => {
        // A closed day blocks NEW cash, not an exact saved-request recovery.
        const blocked = !storageReady || (form.dataset.closed === '1' && !pending);
        button.disabled = blocked || submitting;
        confirmation.disabled = blocked;
    };
    updateControls();
    window.addEventListener('pageshow', updateControls);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submitting || !storageReady || (form.dataset.closed === '1' && !pending)) return;
        // Persist only a scoped UUID. Never retain student/payment details in
        // localStorage or sessionStorage, and never automatically retry cash.
        try {
            sessionStorage.setItem(storageKey, key.value);
            if (sessionStorage.getItem(storageKey) !== key.value) throw new Error('Recovery key not saved');
            pending = key.value;
        } catch (_) { storageReady = false; show(storageMessage); updateControls(); return; }
        submitting = true;
        updateControls();
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 30000);
        try {
            const response = await fetch(form.action, {
                method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' },
                body: new FormData(form), signal: abort.signal,
            });
            const result = await response.json();
            if (response.ok && result.success && /^\/fbr-pos\/school-fees\/\d+\/receipt$/.test(result.receipt_url)) {
                // Only the matching saved receipt may clear this recovery key.
                window.location.assign(result.receipt_url);
                return;
            }
            const errors = Object.values(result.errors || {}).flat().join(' ');
            show((errors || result.message || recoveryMessage) + ' ' + recoveryMessage);
        } catch (_) { show(recoveryMessage); }
        finally { clearTimeout(timeout); submitting = false; updateControls(); }
    });
})();
</script>
@endsection
