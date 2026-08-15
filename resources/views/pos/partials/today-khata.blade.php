{{--
    Task 666: "Aaj ka Khaata" — stream-wise TODAY sale/tax summary (Local + PRA).

    Shared across ALL 7 dashboard styles: included by the PRA dashboard wrapper
    BEFORE the style include (same pattern as the pending-bills tile), so no
    per-style edits are needed. Only existing Tailwind classes (no Vite rebuild).

    Scope rules (controller decides — this partial only renders what it gets):
      - local-scope staff  → Local card only
      - pra-scope staff    → PRA card only
      - both-scope         → PRA card + (Local card if admin/manager)
      - Exempt bills row   → every scope, rendered ONCE (never inside a stream
        card) so the both-scope view can never double-count them. Each stream
        card's "exempt items" line is the mixed-bill share only.
    Figures are returns-netted (signed) — see PosController::dashboard().
--}}
@php
    $tk = $todayKhata ?? null;
    $tkStreams = [];
    if (is_array($tk)) {
        if (!empty($tk['pra']))   { $tkStreams['pra'] = $tk['pra']; }
        if (!empty($tk['local'])) { $tkStreams['local'] = $tk['local']; }
    }
    $tkExempt = is_array($tk) ? ($tk['exempt'] ?? null) : null;
    // Exempt bills are rare — show the row only when today actually has some.
    $tkShowExempt = is_array($tkExempt) && ((($tkExempt['bills'] ?? 0) > 0) || abs((float) ($tkExempt['sale'] ?? 0)) > 0.009);
@endphp
@if(!empty($tkStreams))
{{-- id="today-khata" is a language-independent smoke-test marker (scripts/live-screen-smoke.sh) — keep it. --}}
<div id="today-khata" class="mb-4 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <div class="flex items-center gap-2">
            <div class="w-9 h-9 rounded-xl bg-teal-600 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m-6 4h6m-6 4h4m-9 5V5a2 2 0 012-2h10a2 2 0 012 2v15l-3-2-2 2-2-2-2 2-2-2-3 2z"/></svg>
            </div>
            <div>
                <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.khata_title') }}</h3>
                <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ \Carbon\Carbon::parse($tk['date'] ?? now())->format('d M Y') }} · {{ __('pos.khata_net_note') }}</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 {{ count($tkStreams) > 1 ? 'lg:grid-cols-2' : '' }} gap-3">
        @foreach($tkStreams as $tkKey => $s)
        @php $tkIsPra = ($tkKey === 'pra'); @endphp
        <div class="rounded-xl border p-3 {{ $tkIsPra ? 'border-teal-200 dark:border-teal-800 bg-teal-50 dark:bg-teal-900/20' : 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20' }}">
            <div class="flex items-center justify-between gap-2 mb-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase tracking-wider {{ $tkIsPra ? 'bg-teal-600 text-white' : 'bg-amber-500 text-white' }}">
                    {{ $tkIsPra ? __('pos.khata_pra_stream') : __('pos.khata_local_stream') }}
                </span>
                <span class="text-[10px] font-semibold text-gray-500 dark:text-gray-400">{{ $s['bills'] }} {{ __('pos.khata_bills_word') }}</span>
            </div>
            <p class="text-[10px] uppercase tracking-wider font-bold {{ $tkIsPra ? 'text-teal-700 dark:text-teal-400' : 'text-amber-700 dark:text-amber-400' }}">{{ __('pos.khata_total_sale') }}</p>
            <p class="text-xl font-black text-gray-900 dark:text-white">Rs {{ number_format($s['sale'], 0) }}</p>

            <div class="mt-2 pt-2 border-t {{ $tkIsPra ? 'border-teal-200 dark:border-teal-800' : 'border-amber-200 dark:border-amber-800' }} space-y-1">
                @if($tkIsPra)
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-600 dark:text-gray-400">{{ __('pos.khata_pra_reported') }}</span>
                    <span class="font-bold text-gray-900 dark:text-white">Rs {{ number_format($s['reported'], 0) }}</span>
                </div>
                @endif
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-600 dark:text-gray-400">{{ __('pos.khata_total_tax') }}</span>
                    <span class="font-bold text-gray-900 dark:text-white">Rs {{ number_format($s['tax'], 0) }}</span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-600 dark:text-gray-400 pl-3">{{ __('pos.khata_cash_tax') }}</span>
                    <span class="font-semibold text-gray-700 dark:text-gray-300">Rs {{ number_format($s['cash_tax'], 0) }}</span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-600 dark:text-gray-400 pl-3">{{ __('pos.khata_card_tax') }}</span>
                    <span class="font-semibold text-gray-700 dark:text-gray-300">Rs {{ number_format($s['card_tax'], 0) }}</span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-600 dark:text-gray-400">{{ __('pos.khata_exempt_items') }}</span>
                    <span class="font-semibold text-gray-700 dark:text-gray-300">Rs {{ number_format($s['exempt_items'], 0) }}</span>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    @if($tkShowExempt)
    <div class="mt-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase tracking-wider bg-gray-500 text-white">{{ __('pos.khata_exempt_bills') }}</span>
            <span class="text-[10px] font-semibold text-gray-500 dark:text-gray-400">{{ $tkExempt['bills'] }} {{ __('pos.khata_bills_word') }}</span>
        </div>
        <span class="text-sm font-black text-gray-900 dark:text-white">Rs {{ number_format($tkExempt['sale'], 0) }}</span>
    </div>
    @endif
</div>
@endif
