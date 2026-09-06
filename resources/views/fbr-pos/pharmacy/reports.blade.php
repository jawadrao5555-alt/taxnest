{{--
    💊 Pharmacy reports (Task 1558).

    One page, one report picker — the same shape as the panel's other report
    screens so nothing new has to be learnt. Every report opens on a sensible
    default window (never an accidental All Time scan of a 10k catalogue) and
    prints from the browser rather than inventing a second PDF pipeline.

    Expects: $report, $rows, $totals, $nearDays, $from, $to + branch bag.
--}}
<x-fbr-pos-layout>
@php
    $reportTabs = [
        'near_expiry' => __('pos.ph_rep_near_expiry'),
        'expired' => __('pos.ph_rep_expired'),
        'batch_stock' => __('pos.ph_rep_batch_stock'),
        'valuation' => __('pos.ph_rep_valuation'),
        'claims' => __('pos.ph_rep_claims'),
        'writeoffs' => __('pos.ph_rep_writeoffs'),
        'prescriptions' => __('pos.ph_rep_prescriptions'),
        'movers' => __('pos.ph_rep_movers'),
    ];
    $isBatchReport = in_array($report, ['near_expiry', 'expired', 'batch_stock', 'valuation'], true);
    $isDated = in_array($report, ['prescriptions', 'movers'], true);
    // "WhatsApp par bhejo" (Sep 2026): a compact near-expiry / expired list the
    // owner forwards to the distributor rep. Plain text, one line per batch,
    // capped so a 2,000-row report never becomes an unsendable draft.
    $waText = null;
    if (in_array($report, ['near_expiry', 'expired'], true) && $rows->isNotEmpty()) {
        $waLines = [];
        $waLines[] = ($company->name ?? '') . ' — ' . ($report === 'near_expiry'
            ? __('pos.ph_wa_head_near', ['days' => $nearDays])
            : __('pos.ph_wa_head_expired'));
        $waLines[] = __('pos.ph_wa_date', ['date' => now()->format('d/m/Y')]);
        $waLines[] = '';
        foreach ($rows->take(60) as $i => $r) {
            $waLines[] = ($i + 1) . '. ' . ($r->product->name ?? '')
                . (($r->product->strength ?? '') !== '' ? ' ' . $r->product->strength : '')
                . ' | ' . __('pos.ph_wa_batch') . ' ' . $r->batch_number
                . ' | ' . __('pos.ph_wa_exp') . ' ' . ($r->expiry_date ? \Illuminate\Support\Carbon::parse($r->expiry_date)->format('m/Y') : '-')
                . ' | ' . __('pos.ph_wa_qty') . ' ' . rtrim(rtrim(number_format((float) $r->quantity, 3, '.', ''), '0'), '.');
        }
        if ($rows->count() > 60) {
            $waLines[] = __('pos.ph_wa_more', ['count' => $rows->count() - 60]);
        }
        $waText = implode("\n", $waLines);
    }
@endphp
<div class="max-w-7xl mx-auto">
    @include('fbr-pos.partials.back-link')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-5 print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">💊 {{ __('pos.ph_reports_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.ph_reports_sub') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('fbrpos.pharmacy.batches') }}" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.ph_nav_batches') }}</a>
            <a href="{{ route('fbrpos.pharmacy.missed-sales') }}" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 inline-flex items-center gap-1.5">{{ __('pos.ph_nav_missed_sales') }} <x-new-badge feature="fbr_pharmacy_missed_sales" /></a>
            @if($waText !== null)
            <a href="https://wa.me/?text={{ rawurlencode($waText) }}" target="_blank" rel="noopener"
               class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-sm font-bold hover:bg-emerald-700 inline-flex items-center gap-1.5" data-testid="pharmacy-wa-share">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1-.3-.1-.5-.1-.7.1-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-.3-.1-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.4-.5c.1-.2.2-.3.3-.5.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.2 5.1 4.5.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.2-.3-.3-.6-.4zM12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2c-1.5 0-3-.4-4.3-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2z"/></svg>
                {{ __('pos.ph_wa_share_btn') }}
            </a>
            @endif
            <button type="button" onclick="window.print()" class="px-4 py-2 rounded-xl bg-blue-600 text-white text-sm font-bold hover:bg-blue-700">🖨 {{ __('pos.print') }}</button>
        </div>
    </div>

    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 print:hidden">{{ session('error') }}</div>@endif

    <div class="print:hidden">@include('fbr-pos.partials.branch-bar')</div>

    <div class="flex flex-wrap gap-2 mb-4 print:hidden">
        @foreach($reportTabs as $key => $label)
            <a href="{{ route('fbrpos.pharmacy.reports', ['report' => $key, 'from' => $from, 'to' => $to]) }}"
               class="px-3.5 py-2 rounded-xl text-xs font-bold transition {{ $report === $key ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200 hover:bg-gray-200' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if($isDated)
    <form method="GET" class="flex flex-wrap items-end gap-3 mb-4 print:hidden">
        <input type="hidden" name="report" value="{{ $report }}">
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.from') }}</label>
            <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.to') }}</label>
            <input type="date" name="to" value="{{ $to }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <button class="px-4 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-semibold">{{ __('pos.filter_btn') }}</button>
    </form>
    @endif

    <h2 class="hidden print:block text-lg font-bold mb-2">{{ $company->name ?? '' }} — {{ $reportTabs[$report] ?? '' }}</h2>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
        @if($isBatchReport)
            @if($report === 'near_expiry')
            <p class="px-4 pt-4 text-xs text-amber-700 dark:text-amber-300">{{ __('pos.ph_rep_near_hint', ['days' => $nearDays]) }}</p>
            @endif
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_medicine') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_batch') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_expiry') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_qty') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_cost_value') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_retail_value') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_supplier') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($rows as $r)
                    <tr>
                        <td class="px-4 py-2.5">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $r->product?->name ?? '—' }}</div>
                            @if($r->product?->generic_name)<div class="text-xs text-gray-500">{{ $r->product->generic_name }} {{ $r->product->strength }}</div>@endif
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ $r->batch_number }}</td>
                        <td class="px-4 py-2.5 {{ $r->isExpired() ? 'text-red-600 font-semibold' : '' }}">{{ $r->expiry_date?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right">{{ rtrim(rtrim(number_format((float) $r->quantity, 3, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-2.5 text-right">{{ number_format((float) $r->quantity * (float) $r->cost_price, 2) }}</td>
                        <td class="px-4 py-2.5 text-right">{{ number_format((float) $r->quantity * (float) ($r->retail_price ?? 0), 2) }}</td>
                        <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300">{{ $r->supplier?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400 text-sm">{{ __('pos.ph_rep_empty') }}</td></tr>
                @endforelse
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-gray-800 font-bold text-sm">
                    <tr>
                        <td colspan="3" class="px-4 py-3 text-right">{{ __('pos.total') }}</td>
                        <td class="px-4 py-3 text-right">{{ rtrim(rtrim(number_format($totals['quantity'], 3, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($totals['cost'], 2) }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($totals['retail'], 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

        @elseif($report === 'claims')
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_claim_no') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_supplier') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_items') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_value') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_settled') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_status') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_date') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($rows as $c)
                    <tr>
                        <td class="px-4 py-2.5 font-mono font-bold">{{ $c->claim_number }}</td>
                        <td class="px-4 py-2.5">{{ $c->supplier?->name ?? $c->supplier_name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right">{{ $c->items_count }}</td>
                        <td class="px-4 py-2.5 text-right">{{ number_format((float) $c->total_amount, 2) }}</td>
                        <td class="px-4 py-2.5 text-right">{{ $c->settled_amount !== null ? number_format((float) $c->settled_amount, 2) : '—' }}</td>
                        <td class="px-4 py-2.5">{{ __('pos.ph_claim_status_' . $c->status) }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $c->created_at?->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400 text-sm">{{ __('pos.ph_rep_empty') }}</td></tr>
                @endforelse
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-gray-800 font-bold text-sm">
                    <tr>
                        <td colspan="3" class="px-4 py-3 text-right">{{ __('pos.total') }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($totals['cost'], 2) }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($totals['retail'], 2) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

        @elseif($report === 'writeoffs')
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_date') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_medicine') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_batch') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_qty') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_cost_value') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_writeoff_reason') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_responsible') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($rows as $a)
                    <tr>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $a->created_at?->format('d M Y') }}</td>
                        <td class="px-4 py-2.5 font-semibold text-gray-900 dark:text-white">{{ $a->product?->name ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ $a->batch?->batch_number ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right">{{ rtrim(rtrim(number_format((float) $a->quantity, 3, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-2.5 text-right">{{ number_format((float) $a->cost_value, 2) }}</td>
                        <td class="px-4 py-2.5 text-xs">{{ $a->reason ? __('pos.ph_reason_' . $a->reason) : '—' }}</td>
                        <td class="px-4 py-2.5">{{ $a->responsible_name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400 text-sm">{{ __('pos.ph_rep_empty') }}</td></tr>
                @endforelse
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-gray-800 font-bold text-sm">
                    <tr>
                        <td colspan="3" class="px-4 py-3 text-right">{{ __('pos.total') }}</td>
                        <td class="px-4 py-3 text-right">{{ rtrim(rtrim(number_format($totals['quantity'], 3, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($totals['cost'], 2) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

        @elseif($report === 'prescriptions')
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_date') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_bill') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_doctor_name') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_patient_name') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_medicines') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.total') }}</th>
                        <th class="px-4 py-3 text-left print:hidden">{{ __('pos.ph_col_slip') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($rows as $t)
                    <tr>
                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ $t->created_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ $t->invoice_number ?? $t->id }}</td>
                        <td class="px-4 py-2.5">{{ $t->doctor_name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ $t->patient_name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">
                            {{ $t->items->take(4)->map(fn($i) => $i->item_name . ($i->batch_number ? ' [' . $i->batch_number . ']' : ''))->implode(', ') }}
                            @if($t->items->count() > 4)…@endif
                        </td>
                        <td class="px-4 py-2.5 text-right font-semibold">{{ number_format((float) $t->total_amount, 2) }}</td>
                        <td class="px-4 py-2.5 print:hidden">
                            @if($t->prescription_image)
                                <a href="{{ asset('storage/' . $t->prescription_image) }}" target="_blank" class="text-xs font-semibold text-blue-600 hover:underline">{{ __('pos.ph_view_slip') }}</a>
                            @else
                                <span class="text-gray-400 text-xs">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400 text-sm">{{ __('pos.ph_rep_empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>

        @else {{-- movers --}}
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_medicine') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_sold') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_on_hand') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.ph_col_cost_value') }}</th>
                        <th class="px-4 py-3 text-left">{{ __('pos.ph_col_speed') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($rows as $r)
                    <tr>
                        <td class="px-4 py-2.5">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $r->product?->name ?? '—' }}</div>
                            @if($r->product?->generic_name)<div class="text-xs text-gray-500">{{ $r->product->generic_name }} {{ $r->product->strength }}</div>@endif
                        </td>
                        <td class="px-4 py-2.5 text-right font-semibold">{{ rtrim(rtrim(number_format($r->sold, 3, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-2.5 text-right">{{ rtrim(rtrim(number_format($r->on_hand, 3, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-2.5 text-right">{{ number_format($r->value, 2) }}</td>
                        <td class="px-4 py-2.5">
                            @if($r->sold <= 0)
                                <span class="px-2 py-1 rounded-lg text-[11px] font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ __('pos.ph_speed_dead') }}</span>
                            @elseif($r->sold < 5)
                                <span class="px-2 py-1 rounded-lg text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ __('pos.ph_speed_slow') }}</span>
                            @else
                                <span class="px-2 py-1 rounded-lg text-[11px] font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">{{ __('pos.ph_speed_fast') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400 text-sm">{{ __('pos.ph_rep_empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        @endif
        </div>
    </div>
</div>
</x-fbr-pos-layout>
