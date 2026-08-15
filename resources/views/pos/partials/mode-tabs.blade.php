@php
    $currentTab = $tab ?? 'pra';
    $hasPinSet = $hasPinSet ?? false;
    $localCount = $localCount ?? 0;
    $baseUrl = $baseUrl ?? request()->url();
    // Task 699: tab switches must carry the current filters (date range, tax
    // rate, payment method, customer, bill_type, ...) — only 'tab' changes and
    // 'page' resets to 1. Arr::query url-encodes every value, so safe in href.
    $tabQuery = \Illuminate\Support\Arr::query(request()->except(['tab', 'page']));
    $tabQuery = $tabQuery !== '' ? '&' . $tabQuery : '';
    // Billing Scope (07 Aug 2026): stream-locked staff see ONLY their own
    // stream's tab — controllers force the tab server-side too.
    $tabScope = auth('pos')->user()?->posBillingScope() ?? 'both';
    // Exempt tab (Task 647, HISTORICAL since Task 760): exempt items are now
    // reported to PRA at 0%, so NEW all-exempt bills live in the PRA tab like
    // any other reported bill. This tab only surfaces pre-zero-rating bills
    // stamped pra_status='exempt_internal' — shown ONLY when such bills exist
    // (the old "has exempt products" OR-clause is gone: it would show a
    // forever-empty tab). Visible to EVERY role and BOTH billing scopes.
    // Cheap cached existence check; caller opts in via 'showExempt'.
    $showExemptTab = false;
    if (($showExempt ?? false)) {
        try {
            $tabCompanyId = (int) app('currentCompanyId');
            $showExemptTab = \Illuminate\Support\Facades\Cache::remember(
                "pos_exempt_tab_{$tabCompanyId}",
                300,
                function () use ($tabCompanyId) {
                    return \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
                            ->where('company_id', $tabCompanyId)
                            ->where('pra_status', \App\Models\PosTransaction::EXEMPT_INTERNAL)
                            ->exists();
                }
            );
        } catch (\Throwable $e) {
            $showExemptTab = false;
        }
    }
@endphp
<div class="flex items-center gap-2 mb-6">
    @if($tabScope !== 'local')
    <a href="{{ $baseUrl }}?tab=pra{{ $tabQuery }}"
        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200
        {{ $currentTab === 'pra' ? 'bg-purple-600 text-white shadow-md' : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        {{ __('pos.pra_invoices') }}
    </a>
    @endif
    {{-- Local Invoices tab — ADMIN-ONLY (owner request Jul 2026). Fully isolated
         from the PRA set: shows ONLY invoice_mode='local' bills. Cashiers never
         see this tab and the controllers force tab='pra' for them server-side.
         Billing Scope (07 Aug 2026): a local-scoped cashier/manager DOES get it
         (it is their whole world); a pra-scoped one never does. --}}
    {{-- Task 705: manager default PRA-only — Local tab hidden until the khufia
         local-check mode (Ctrl+Alt+Shift+L) is ON. Owner/admin unchanged. --}}
    @if($tabScope === 'local' || ($tabScope !== 'pra' && auth('pos')->user()?->isPosAdmin() && !(auth('pos')->user()?->posHidesLocalStream() ?? false)))
    <a href="{{ $baseUrl }}?tab=local{{ $tabQuery }}"
        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200
        {{ $currentTab === 'local' ? 'bg-purple-600 text-white shadow-md' : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        {{ __('pos.local_invoices') }}
    </a>
    @endif
    @if($showExemptTab)
    <a href="{{ $baseUrl }}?tab=exempt{{ $tabQuery }}"
        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200
        {{ $currentTab === 'exempt' ? 'bg-purple-600 text-white shadow-md' : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
        {{ __('pos.exempt_invoices') }}
    </a>
    @endif
</div>
