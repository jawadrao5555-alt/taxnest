{{--
    💊 Missed sales — "customer ne poocha, dukan par nahi thi" (Sep 2026).

    Every no-match Enter and every "Nahi hai" on the counter's alternatives
    panel lands here, grouped by the normalised term over a date range: how
    many times it was asked, how much was wanted, when it was last asked and
    who heard it. "Product banayein" jumps to the create form with the name
    pre-filled; "Ho gaya" marks the whole term handled (and can be reopened).

    Manager/owner only (a cashier only through Custom Access "reports").
    Expects: $groups, $askers, $totalAsks, $from, $to, $show + branch bag.
--}}
<x-fbr-pos-layout>
<div class="max-w-6xl mx-auto" x-data="phMissedSales()">
    @include('fbr-pos.partials.back-link')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-5 print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">💊 {{ __('pos.ph_missed_title') }} <x-new-badge feature="fbr_pharmacy_missed_sales" /></h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.ph_missed_sub') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('fbrpos.pharmacy.reports') }}" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.ph_nav_reports') }}</a>
            <button type="button" onclick="window.print()" class="px-4 py-2 rounded-xl bg-blue-600 text-white text-sm font-bold hover:bg-blue-700">🖨 {{ __('pos.print') }}</button>
        </div>
    </div>

    <div class="print:hidden">@include('fbr-pos.partials.branch-bar')</div>

    <form method="GET" class="flex flex-wrap items-end gap-3 mb-4 print:hidden">
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.from') }}</label>
            <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.to') }}</label>
            <input type="date" name="to" value="{{ $to }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_missed_show') }}</label>
            <select name="show" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                <option value="open" @selected($show === 'open')>{{ __('pos.ph_missed_show_open') }}</option>
                <option value="handled" @selected($show === 'handled')>{{ __('pos.ph_missed_show_handled') }}</option>
                <option value="all" @selected($show === 'all')>{{ __('pos.ph_missed_show_all') }}</option>
            </select>
        </div>
        <button class="px-4 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-semibold">{{ __('pos.filter_btn') }}</button>
        <span class="text-xs text-gray-500 dark:text-gray-400 ml-auto">{{ __('pos.ph_missed_summary', ['terms' => $groups->count(), 'asks' => $totalAsks]) }}</span>
    </form>

    <h2 class="hidden print:block text-lg font-bold mb-2">{{ $company->name ?? '' }} — {{ __('pos.ph_missed_title') }} ({{ $from }} – {{ $to }})</h2>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_missed_col_term') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_missed_col_asks') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_missed_col_qty') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_missed_col_last') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_missed_col_by') }}</th>
                        <th class="px-4 py-3 text-right print:hidden">{{ __('pos.actions_col') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($groups as $g)
                    @php
                        $gOpen = (int) $g->open_asks > 0;
                        $gQty = (float) $g->qty;
                        $gLast = $g->last_asked ? \Illuminate\Support\Carbon::parse($g->last_asked) : null;
                    @endphp
                    <tr class="{{ $gOpen ? '' : 'opacity-60' }}" x-show="!hidden[@js($g->term_key)]" data-testid="missed-row">
                        <td class="px-4 py-2.5">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $g->term }}</div>
                            @if((int) $g->out_of_stock_asks > 0)
                                <div class="text-[11px] text-amber-600 dark:text-amber-400">{{ __('pos.ph_missed_out_of_stock_note', ['count' => (int) $g->out_of_stock_asks]) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <span class="inline-flex items-center justify-center min-w-[24px] h-6 px-2 rounded-full text-xs font-extrabold {{ (int) $g->asks >= 3 ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' }}">{{ (int) $g->asks }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-right">{{ $gQty > 0 ? rtrim(rtrim(number_format($gQty, 2, '.', ''), '0'), '.') : '—' }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $gLast?->format('d M Y, h:i A') ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">{{ $askers[$g->last_id] ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right print:hidden">
                            <div class="inline-flex items-center gap-1.5">
                                @if(auth('fbrpos')->user()?->role === 'company_admin')
                                    @if((int) ($g->product_id ?? 0) > 0)
                                    {{-- The brand exists but was off the shelf: open THAT product, never create a duplicate. --}}
                                    <a href="{{ route('fbrpos.products.edit', ['id' => (int) $g->product_id]) }}"
                                       class="px-2.5 py-1.5 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-[11px] font-bold text-amber-700 dark:text-amber-300 hover:bg-amber-100">{{ __('pos.ph_missed_open_product') }}</a>
                                    @else
                                    <a href="{{ route('fbrpos.products.create', ['name' => $g->term]) }}"
                                       class="px-2.5 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-[11px] font-bold text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100">{{ __('pos.ph_missed_create_product') }}</a>
                                    @endif
                                @endif
                                <button type="button" @click="toggle(@js($g->term_key), {{ $gOpen ? 'true' : 'false' }})" :disabled="busy[@js($g->term_key)]"
                                        class="px-2.5 py-1.5 rounded-lg text-[11px] font-bold transition disabled:opacity-50 {{ $gOpen ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-200' }}"
                                        data-testid="missed-toggle">
                                    {{ $gOpen ? __('pos.ph_missed_mark_handled') : __('pos.ph_missed_reopen') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400 text-sm">{{ __('pos.ph_missed_empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="mt-3 text-[11px] text-gray-400 print:hidden">{{ __('pos.ph_missed_footnote') }}</p>
</div>

<script>
function phMissedSales() {
    return {
        busy: {}, hidden: {},
        async toggle(key, handled) {
            if (this.busy[key]) return;
            this.busy[key] = true;
            try {
                const r = await fetch({{ Js::from(route('fbrpos.pharmacy.missed-sales.handled', [], false)) }}, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': {{ Js::from(csrf_token()) }} },
                    body: JSON.stringify({ term: key, handled: handled }),
                });
                const d = await r.json().catch(() => null);
                if (!r.ok || !d || d.success !== true) {
                    alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }});
                    return;
                }
                // The row's state changed on the server; the list filter (open /
                // handled) decides whether it still belongs here — reload keeps
                // the counts honest instead of guessing client-side.
                window.location.reload();
            } catch (e) {
                alert({{ Js::from(__('pos.setting_save_failed')) }});
            } finally {
                this.busy[key] = false;
            }
        },
    };
}
</script>
</x-fbr-pos-layout>
