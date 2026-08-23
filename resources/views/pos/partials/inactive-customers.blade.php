{{--
    Task 1161: "Purane Customer Khamosh Hain" — repeat-customer inactivity alert.
    Repeat customers (PosRepeatCustomerAlert::MIN_ORDERS+ completed orders)
    whose LAST order is older than the inactivity window: naam, phone
    (tap-to-call), total orders, "aakhri order X din pehle" + history link.

    Shared by BOTH dashboard variants (retail pos/dashboard + restaurant
    dashboard) — included before the style include like the pending-bills tile,
    so no per-style edits are needed. Only existing Tailwind classes (no Vite
    rebuild required).

    Owner, 23 Aug 2026 (two voice notes):
      · "jis ko handle kar liya us ko clear kar dein taake neeche wala front
        par aa jaye" → per-row ✕ that marks THIS silence handled (server side,
        PosRepeatCustomerAlert::dismiss — nothing is deleted, and the alert
        returns if the customer orders again and then goes quiet again).
      · "teen number bas show hon, baqi automatic hide" → only CARD_LIMIT rows
        are visible; the next ones are pre-rendered hidden and slide up the
        moment one is cleared, with no page reload.

    Visibility: controller passes rows only for admin/manager ($isAdmin). Empty
    list hides the card entirely. Pending companies + view-only impersonation
    are skipped per the standard POS notification-surface convention (What's New
    rules) — and confined roles never reach the dashboards at all.
--}}
@php
    $icList = collect($inactiveRegulars ?? []);
    $icImp = session('impersonation');
    $icReadonlyImp = is_array($icImp) && !empty($icImp['readonly']);
    $icPending = (($company->status ?? null) === 'pending') || (($company->company_status ?? null) === 'pending');
    $icShow = ($isAdmin ?? false) && !$icPending && !$icReadonlyImp && $icList->isNotEmpty();
    $icLimit = \App\Services\PosRepeatCustomerAlert::CARD_LIMIT;
    // Visible rows + a hidden buffer that moves up as rows are cleared.
    $icRows = $icList->take($icLimit + \App\Services\PosRepeatCustomerAlert::CARD_BUFFER);
    $icMore = max(0, $icList->count() - min($icRows->count(), $icLimit));
@endphp
@if($icShow)
<div id="inactive-regulars" data-ic-total="{{ $icList->count() }}" class="mb-4 rounded-2xl bg-white dark:bg-gray-900 border border-sky-200 dark:border-sky-800 p-4 shadow-sm">
    <div class="flex items-start gap-3 mb-3">
        <div class="w-9 h-9 rounded-xl bg-sky-600 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
        </div>
        <div class="flex-1 min-w-0">
            <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">
                {{ __('pos.inactive_regulars_title') }}
                <span data-ic-count class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-sky-500 text-white text-[11px] font-extrabold">{{ $icList->count() }}</span>
            </h3>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                {{ __('pos.inactive_regulars_hint', ['min' => \App\Services\PosRepeatCustomerAlert::MIN_ORDERS, 'days' => \App\Services\PosRepeatCustomerAlert::INACTIVE_DAYS]) }}
            </p>
        </div>
    </div>
    <div class="divide-y divide-gray-100 dark:divide-gray-800">
        @foreach($icRows as $ic)
        <div data-ic-row data-cid="{{ (int) $ic['id'] }}" class="py-2 flex items-center gap-3"@if($loop->index >= $icLimit) style="display:none"@endif>
            <div class="flex-1 min-w-0">
                {{-- hover styles limited to classes already in the built CSS (no Vite rebuild) --}}
                <a href="{{ route('pos.customers.history', $ic['id']) }}" class="text-sm font-bold text-gray-900 dark:text-white hover:underline transition truncate block">{{ $ic['name'] }}</a>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                    <span class="font-semibold text-sky-700 dark:text-sky-400">{{ __('pos.inactive_orders_count', ['count' => $ic['orders']]) }}</span>
                    · {{ __('pos.inactive_last_order_days', ['days' => $ic['days']]) }}
                </p>
            </div>
            @if(!empty($ic['phone']))
            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $ic['phone']) }}"
               class="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 text-xs font-bold hover:bg-sky-100 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                <span dir="ltr">{{ $ic['phone'] }}</span>
            </a>
            @endif
            <button type="button" data-ic-dismiss title="{{ __('pos.ic_dismiss_title') }}" aria-label="{{ __('pos.ic_dismiss_title') }}"
                    class="flex-shrink-0 w-7 h-7 inline-flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 dark:hover:text-gray-200 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        @endforeach
    </div>
    <p data-ic-error class="mt-2 text-[11px] text-red-600 dark:text-red-400" style="display:none">{{ __('pos.ic_dismiss_failed') }}</p>
    <p data-ic-more-wrap class="mt-2 text-[11px] text-gray-400"@if($icMore <= 0) style="display:none"@endif>
        <a href="{{ route('pos.customers') }}" data-ic-more data-tpl="{{ __('pos.inactive_more_note', ['count' => ':count']) }}" class="font-semibold text-sky-700 dark:text-sky-400 hover:underline">{{ __('pos.inactive_more_note', ['count' => $icMore]) }}</a>
    </p>
</div>

<script>
(function () {
    var card = document.getElementById('inactive-regulars');
    if (!card || card.dataset.icBound === '1') return;
    card.dataset.icBound = '1';

    var LIMIT = {{ (int) $icLimit }};
    var total = parseInt(card.getAttribute('data-ic-total') || '0', 10);
    var countEl = card.querySelector('[data-ic-count]');
    var moreEl = card.querySelector('[data-ic-more]');
    var moreWrap = card.querySelector('[data-ic-more-wrap]');
    var errEl = card.querySelector('[data-ic-error]');
    var moreTpl = moreEl ? (moreEl.getAttribute('data-tpl') || '') : '';

    function liveRows() {
        return Array.prototype.filter.call(
            card.querySelectorAll('[data-ic-row]'),
            function (r) { return r.dataset.icDone !== '1'; }
        );
    }

    // Show only LIMIT rows; the ones behind them wait their turn.
    function refresh() {
        var shown = 0;
        liveRows().forEach(function (r) {
            var visible = shown < LIMIT;
            r.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });
        if (countEl) countEl.textContent = total;
        var hidden = Math.max(0, total - shown);
        if (moreEl && moreWrap) {
            if (hidden > 0 && moreTpl) {
                moreEl.textContent = moreTpl.replace(':count', hidden);
                moreWrap.style.display = '';
            } else {
                moreWrap.style.display = 'none';
            }
        }
        if (total <= 0 || shown === 0) card.style.display = 'none';
    }

    card.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-ic-dismiss]') : null;
        if (!btn || btn.disabled) return;
        var row = btn.closest('[data-ic-row]');
        if (!row) return;

        var cid = parseInt(row.getAttribute('data-cid') || '0', 10);
        if (!cid) return;

        // Optimistic, but rolled back on anything short of a real success —
        // a silent 403/419 must never look like "handled".
        btn.disabled = true;
        row.dataset.icDone = '1';
        total = Math.max(0, total - 1);
        if (errEl) errEl.style.display = 'none';
        refresh();

        var meta = document.querySelector('meta[name="csrf-token"]');
        fetch(@json(route('pos.customers.alert-dismiss', [], false)), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': meta ? meta.content : '',
            },
            body: JSON.stringify({ customer_id: cid }),
        }).then(function (r) {
            return r.ok ? r.json().catch(function () { return null; }) : null;
        }).then(function (d) {
            if (!d || d.success !== true) throw new Error('dismiss failed');
            if (typeof d.remaining === 'number') {
                total = d.remaining;
                refresh();
            }
        }).catch(function () {
            delete row.dataset.icDone;
            btn.disabled = false;
            total = total + 1;
            card.style.display = '';
            if (errEl) errEl.style.display = '';
            refresh();
        });
    });

    refresh();
})();
</script>
@endif
