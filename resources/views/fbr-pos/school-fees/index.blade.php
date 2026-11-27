@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('pos.school_nav_collect') }}</h1>
<p class="muted">{{ __('pos.school_fee_desk') }}</p>
<p class="muted">{{ __('school_ledger.pilot_scope') }}</p>
<div class="notice">{{ __('pos.fbr_missing_school_route') }} {{ __('pos.school_separate_ledger') }}</div>
<section class="card">
    <h2>{{ __('school_ledger.record_payment') }}</h2>
    @if($collectionClosed)<div role="alert" class="notice">{{ __($erpCutover ? 'school_ledger.erp_cutover_closed' : 'pos.school_closed_collection') }}</div>@endif
    @if($errors->any())<div role="alert" class="error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div id="school-recovery-notice" class="notice" role="alert" hidden></div>
    <button type="button" id="school-recover-payment" hidden>{{ __('school_ledger.recover') }}</button>
    <form method="post" action="/fbr-pos/school-fees" id="school-collection" data-closed="{{ $collectionClosed ? '1' : '0' }}">
        @csrf
        <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
        <input type="hidden" name="account_id" id="school-account-id" value="">
        <input type="hidden" name="expected_branch_key" value="{{ $schoolCampusId ?? 0 }}">
        <div class="grid">
            <div><label for="student_name">{{ __('school_ledger.student_name') }}</label><input id="student_name" name="student_name" maxlength="100" value="{{ old('student_name') }}"></div>
            <div><label for="payment_method">{{ __('pos.school_payment') }}</label><select id="payment_method" name="payment_method"><option value="cash" @selected(old('payment_method', 'cash') === 'cash')>{{ __('pos.school_cash') }}</option><option value="card" @selected(old('payment_method') === 'card')>{{ __('school_ledger.card') }}</option><option value="bank" @selected(old('payment_method') === 'bank')>{{ __('school_ledger.bank') }}</option></select></div>
            <div><label for="payment_reference">{{ __('school_audit.reference') }}</label><input id="payment_reference" name="payment_reference" maxlength="100" value="{{ old('payment_reference') }}"><p class="muted">{{ __('school_audit.reference_help') }}</p></div>
            <div><label for="student_reference">{{ __('pos.school_student_reference') }}</label><input id="student_reference" list="school-student-references" name="student_reference" value="{{ old('student_reference') }}" maxlength="100" required autocomplete="off"><datalist id="school-student-references">@foreach($studentReferences as $reference)<option value="{{ $reference }}">@endforeach</datalist><div class="muted">{{ __('pos.school_reference_hint') }} {{ __('school_ledger.legacy_lookup') }}</div></div>
            <div id="school-single-head"><label for="fee_head">{{ __('pos.school_fee_head') }}</label><select id="fee_head" name="fee_head" required>@foreach($feeHeads as $head)<option value="{{ $head['code'] }}" @selected(old('fee_head', 'tuition') === $head['code'])>{{ $head['label'] }}</option>@endforeach</select></div>
            <div><label for="fee_period">{{ __('school.period') }}</label><input type="month" id="fee_period" name="fee_period" value="{{ old('fee_period', now()->format('Y-m')) }}" required><div class="muted">{{ __('school.default_month') }}</div></div>
            <div><label for="amount">{{ __('school_print.this_payment') }} (PKR)</label><input id="amount" name="amount" type="number" min="0.01" max="99999999.99" step="0.01" value="{{ old('amount') }}" required inputmode="decimal"></div>
        </div>
        <fieldset id="school-multi-lines"><legend>{{ __('school_ledger.lines') }}</legend>
<label><input style="width:auto" type="checkbox" id="school-multi-mode"> {{ __('school_ledger.itemized') }}</label>
<div id="school-line-editor" hidden><div id="school-lines"></div><button id="school-add-line" type="button">{{ __('school_ledger.add_head') }}</button><p id="school-payable"></p></div>
<p class="muted">{{ __('school_ledger.scope') }}</p></fieldset>
<label class="cash-confirm"><input type="checkbox" name="cash_confirmed" value="1" required @disabled($collectionClosed)> <span>{{ __('school_ledger.confirm_received') }}</span></label>
        <div class="actions"><button type="submit" @disabled($collectionClosed)>{{ __('school_ledger.record_payment') }}</button><span class="muted">{{ __('school_ledger.record_only') }}</span></div>
    </form>
</section>
<section class="card"><h2>{{ __('school_ledger.lookup') }}</h2>
<form method="get"><label for="student-search">{{ __('school_ledger.lookup') }}</label><input id="student-search" name="student_search" maxlength="100" value="{{ request('student_search') }}"><button>{{ __('pos.school_view_report') }}</button></form>
<div class="scroll"><table><thead><tr><th>{{ __('pos.school_student_reference') }}</th><th>{{ __('school.period') }}</th><th>{{ __('school_ledger.payable') }}</th><th>{{ __('school_ledger.paid') }}</th><th>{{ __('school_ledger.balance') }}</th><th></th></tr></thead><tbody>
@foreach($accounts as $account)<tr><td>{{ $account->student_reference }} {{ $account->student_name }}</td><td>{{ $account->fee_period }}</td><td>{{ number_format($account->payable_paisa/100,2) }}</td><td>{{ number_format($account->paid_paisa/100,2) }}</td><td>{{ number_format(($account->payable_paisa-$account->paid_paisa)/100,2) }}</td><td>@if($account->paid_paisa < $account->payable_paisa)<button type="button" class="school-pay-balance" data-account="{{ json_encode($account) }}">{{ __('school_ledger.pay_balance') }}</button>@endif</td></tr>@endforeach
</tbody></table></div>{{ $accounts->links() }}</section>
<section class="card" id="school-receipt-history"><h2>{{ __('pos.school_receipt_history') }}</h2><div class="scroll">
<table><thead><tr><th>{{ __('pos.school_receipt') }}</th><th>{{ __('pos.school_student_reference') }}</th><th>{{ __('pos.school_fee_head') }}</th><th>PKR</th><th>{{ __('pos.school_collected_at') }}</th><th>{{ __('school.period') }}</th><th>{{ __('school.campus') }}</th><th>{{ __('school.cashier') }}</th></tr></thead><tbody>
@forelse($receipts as $receipt)
<tr><td><a href="/fbr-pos/school-fees/{{ $receipt->id }}/receipt">SF-{{ $receipt->id }}</a></td><td>{{ $receipt->student_reference }}</td><td>{{ $receipt->fee_head_label ?? __('pos.school_head_'.$receipt->fee_head) }}</td><td>{{ number_format($receipt->amount_paisa / 100, 2) }}</td><td>{{ $receipt->collected_at }}</td><td>{{ $receipt->fee_period }}</td><td>{{ $receipt->campus_name }}</td><td>{{ $receipt->cashier_name }}</td></tr>
@empty<tr><td colspan="8">{{ __('pos.school_no_receipts') }}</td></tr>@endforelse
</tbody></table></div>{{ $receipts->links() }}</section>
<script>
(() => {
    const form = document.getElementById('school-collection');
    const button = form.querySelector('button[type="submit"]');
    const confirmation = form.querySelector('[name="cash_confirmed"]');
    const key = form.querySelector('[name="request_key"]');
    const notice = document.getElementById('school-recovery-notice');
    const storageKey = @json('taxnest.school.pending.'.$company->id.'.'.($schoolCampusId ?? 0).'.'.auth('fbrpos')->id());
    const recoveryMessage = @json(__('school_ledger.pending_recovery'));
    const storageMessage = @json(__('pos.school_pending_storage_unavailable'));
    const heads = @json($feeHeads->values());
    const multi = document.getElementById('school-multi-mode');
    const editor = document.getElementById('school-line-editor');
    const lines = document.getElementById('school-lines');
    let loadedAccount = false;
    const rate = code => { const value = heads.find(h => h.code === code)?.default_amount_paisa; return value == null ? '' : (value / 100).toFixed(2); };
    const singleHead = document.getElementById('fee_head');
    const paidAmount = document.getElementById('amount');
    if (!paidAmount.value) paidAmount.value = rate(singleHead.value);
    singleHead.addEventListener('change', () => { if (!loadedAccount && !multi.checked) paidAmount.value = rate(singleHead.value); });
    const method = document.getElementById('payment_method');
    const reference = document.getElementById('payment_reference');
    const updateReference = () => { reference.required = method.value !== 'cash'; };
    method.addEventListener('change', updateReference); updateReference();
    const setMode = () => { editor.hidden = !multi.checked; document.getElementById('school-single-head').hidden = multi.checked; singleHead.disabled = multi.checked; };
    const showPayable = () => {
        if (loadedAccount) return;
        const total = [...lines.querySelectorAll('input')].reduce((sum, input) => sum + Math.round(Number(input.value || 0) * 100), 0);
        document.getElementById('school-payable').textContent = @json(__('school_ledger.payable')) + ': PKR ' + (total / 100).toFixed(2);
    };
    const addLine = (head = heads[0]?.code, amount = null) => {
        const row = document.createElement('div'); row.className = 'grid school-line';
        const select = document.createElement('select'); select.className = 'school-line-head';
        heads.forEach(h => { const option = new Option(h.label, h.code); option.selected = h.code === head; select.add(option); });
        if (![...select.options].some(o => o.value === head)) select.add(new Option(head, head, true, true));
        const input = document.createElement('input'); input.type = 'number'; input.min = '0.01'; input.max = '99999999.99'; input.step = '0.01'; input.className = 'school-line-amount'; input.value = amount ?? rate(head);
        select.addEventListener('change', () => { if (!loadedAccount) input.value = rate(select.value); showPayable(); });
        select.setAttribute('aria-label', @json(__('pos.school_fee_head'))); input.setAttribute('aria-label', @json(__('school_ledger.payable')));
        const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = @json(__('school_ledger.remove')); remove.onclick = () => { if (!loadedAccount) { row.remove(); showPayable(); } };
        input.addEventListener('input', showPayable);
        row.append(select, input, remove); lines.append(row); showPayable();
    };
    multi.onchange = () => { setMode(); if (multi.checked && !lines.children.length) addLine(document.getElementById('fee_head').value, document.getElementById('amount').value); };
    document.getElementById('school-add-line').onclick = () => { if (!loadedAccount && lines.children.length < 20) addLine(); };
    document.querySelectorAll('.school-pay-balance').forEach(b => b.onclick = () => {
        if (pending) { show(recoveryMessage); return; }
        const a = JSON.parse(b.dataset.account); loadedAccount = true;
        document.getElementById('school-account-id').value = a.id;
        document.getElementById('student_reference').value = a.student_reference;
        document.getElementById('student_name').value = a.student_name || '';
        document.getElementById('fee_period').value = a.fee_period;
        document.getElementById('amount').value = ((a.payable_paisa-a.paid_paisa)/100).toFixed(2);
        multi.checked = true; multi.disabled = true; setMode(); lines.replaceChildren();
        JSON.parse(a.fee_lines).forEach(l => addLine(l.fee_head, (l.amount_paisa/100).toFixed(2)));
        lines.querySelectorAll('select,input').forEach(input => input.disabled = true);
        ['student_reference','student_name','fee_period'].forEach(id => document.getElementById(id).readOnly = true);
        document.getElementById('school-payable').textContent = @json(__('school_ledger.balance')) + ': PKR ' + ((a.payable_paisa-a.paid_paisa)/100).toFixed(2);
        form.scrollIntoView({behavior:'smooth'});
    });
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
    const recover = document.getElementById('school-recover-payment');
    recover.hidden = !pending;
    recover.onclick = async () => {
        recover.disabled = true;
        try {
            const query = new URLSearchParams({ request_key: key.value, expected_branch_key: form.querySelector('[name="expected_branch_key"]').value });
            const response = await fetch('/fbr-pos/school-fees/recover?' + query, {credentials:'same-origin', headers:{Accept:'application/json'}});
            const result = await response.json();
            if (response.ok && /^\/fbr-pos\/school-fees\/\d+\/receipt$/.test(result.receipt_url)) { window.location.assign(result.receipt_url); return; }
            show(@json(__('school_ledger.not_recovered')));
        } catch (_) { show(recoveryMessage); }
        finally { recover.disabled = false; }
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
            recover.hidden = false;
        } catch (_) { storageReady = false; show(storageMessage); updateControls(); return; }
        const receiptWindow = window.open('', '_blank', 'popup,width=740,height=840');
        let receiptOpened = false;
        submitting = true;
        updateControls();
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 30000);
        try {
            const payload = new FormData(form);
            if (multi.checked) [...lines.children].forEach((row, i) => {
                payload.append(`fee_lines[${i}][fee_head]`, row.querySelector('select').value);
                payload.append(`fee_lines[${i}][amount]`, row.querySelector('input').value);
            });
            const response = await fetch(form.action, {
                method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' },
                body: payload, signal: abort.signal,
            });
            const result = await response.json();
            if (response.ok && result.success && /^\/fbr-pos\/school-fees\/\d+\/receipt$/.test(result.receipt_url)) {
                // Only the matching saved receipt may clear this recovery key.
                if (receiptWindow && !receiptWindow.closed) {
                    receiptWindow.location.replace(result.receipt_url);
                    receiptOpened = true;
                    if (sessionStorage.getItem(storageKey) === key.value) sessionStorage.removeItem(storageKey);
                    window.location.reload();
                } else {
                    window.location.assign(result.receipt_url);
                }
                return;
            }
            const errors = Object.values(result.errors || {}).flat().join(' ');
            show((errors || result.message || recoveryMessage) + ' ' + recoveryMessage);
        } catch (_) { show(recoveryMessage); }
        finally { if (!receiptOpened && receiptWindow && !receiptWindow.closed) receiptWindow.close();  clearTimeout(timeout); submitting = false; updateControls(); }
    });
})();
</script>
@endsection
