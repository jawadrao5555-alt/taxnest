{{--
    💊 Distributor expiry / damage claims (Task 1558).

    Two halves, in the order the shop works: what is CLAIMABLE right now (the
    dead stock sitting in the drawer), and the claims already raised against a
    distributor. Building a claim is a single tick-and-send, because that is
    how the shop does it — one supplier's expired strips, one list, one visit.

    Expects: $claims (paginator), $status, $claimable, $suppliers + branch bag.
--}}
<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto" x-data="pharmacyClaims()">
    @include('fbr-pos.partials.back-link')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">💊 {{ __('pos.ph_claims_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.ph_claims_sub') }}</p>
        </div>
        <a href="{{ route('fbrpos.pharmacy.batches') }}" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.ph_nav_batches') }}</a>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm">{{ $errors->first() }}</div>@endif

    @include('fbr-pos.partials.branch-bar')

    {{-- ── Claimable stock ─────────────────────────────────────────────── --}}
    <section class="mb-8 bg-white dark:bg-gray-900 rounded-2xl border border-amber-200 dark:border-amber-900/50 overflow-hidden">
        <div class="px-4 py-3 bg-amber-50 dark:bg-amber-900/20 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ __('pos.ph_claimable_title') }}</h2>
                <p class="text-xs text-amber-700 dark:text-amber-300/80">{{ __('pos.ph_claimable_sub') }}</p>
            </div>
            <span class="text-xs font-bold text-amber-800 dark:text-amber-200">{{ $claimable->count() }}</span>
        </div>

        @if($claimable->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-gray-400">{{ __('pos.ph_claimable_none') }}</p>
        @else
        <form method="POST" action="{{ route('fbrpos.pharmacy.claim.store') }}">
            @csrf
            <div class="overflow-x-auto max-h-96 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 sticky top-0">
                        <tr>
                            <th class="px-4 py-2 text-left w-10">
                                <input type="checkbox" @change="toggleAll($event)" class="rounded border-gray-300">
                            </th>
                            <th class="px-4 py-2 text-left">{{ __('pos.ph_col_medicine') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('pos.ph_col_batch') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('pos.ph_col_expiry') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('pos.ph_col_qty') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('pos.ph_col_value') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('pos.ph_col_supplier') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($claimable as $b)
                        <tr>
                            <td class="px-4 py-2"><input type="checkbox" name="batch_ids[]" value="{{ $b->id }}" class="ph-claim-box rounded border-gray-300"></td>
                            <td class="px-4 py-2">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ $b->product?->name ?? '—' }}</div>
                                @if($b->product?->generic_name)<div class="text-xs text-gray-500">{{ $b->product->generic_name }} {{ $b->product->strength }}</div>@endif
                            </td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-600 dark:text-gray-300">{{ $b->batch_number }}</td>
                            <td class="px-4 py-2 text-red-600 dark:text-red-400 font-semibold">{{ $b->expiry_date?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">{{ rtrim(rtrim(number_format((float) $b->quantity, 3, '.', ''), '0'), '.') }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format((float) $b->quantity * (float) $b->cost_price, 2) }}</td>
                            <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ $b->supplier?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-800 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_supplier') }}</label>
                    <select name="supplier_id" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                        <option value="">—</option>
                        @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_writeoff_reason') }}</label>
                    <select name="reason" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                        @foreach(\App\Models\PharmacyClaimItem::REASONS as $r)
                            <option value="{{ $r }}">{{ __('pos.ph_reason_' . $r) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_note') }}</label>
                    <input type="text" name="notes" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                </div>
                {{-- Never gated behind "tick a row first" — the button says what
                     it does and the server refuses an empty selection cleanly. --}}
                <button type="submit" class="px-4 py-2.5 rounded-xl bg-amber-600 text-white text-sm font-bold hover:bg-amber-700">{{ __('pos.ph_create_claim') }}</button>
            </div>
        </form>
        @endif
    </section>

    {{-- ── Existing claims ─────────────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-2 mb-3">
        @foreach(['open' => __('pos.ph_claim_filter_open'), 'settled' => __('pos.ph_claim_status_settled'), 'credited' => __('pos.ph_claim_status_credited'), 'rejected' => __('pos.ph_claim_status_rejected'), 'all' => __('pos.ph_filter_all')] as $k => $label)
            <a href="{{ route('fbrpos.pharmacy.claims', ['status' => $k]) }}"
               class="px-3.5 py-2 rounded-xl text-xs font-bold {{ $status === $k ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
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
                @forelse($claims as $c)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer" onclick="window.location='{{ route('fbrpos.pharmacy.claim', $c->id) }}'">
                        <td class="px-4 py-3 font-mono font-bold text-blue-600 dark:text-blue-400">{{ $c->claim_number }}</td>
                        <td class="px-4 py-3 text-gray-800 dark:text-gray-200">{{ $c->supplier?->name ?? $c->supplier_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">{{ $c->items_count }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format((float) $c->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-right text-green-600 dark:text-green-400">{{ $c->settled_amount !== null ? number_format((float) $c->settled_amount, 2) : '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-lg text-[11px] font-bold {{ $c->isClosed() ? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' }}">
                                {{ __('pos.ph_claim_status_' . $c->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $c->created_at?->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400 text-sm">{{ __('pos.ph_no_claims') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $claims->links() }}</div>
</div>

<script>
function pharmacyClaims() {
    return {
        toggleAll(e) {
            document.querySelectorAll('.ph-claim-box').forEach(function (b) { b.checked = e.target.checked; });
        },
    };
}
</script>
</x-fbr-pos-layout>
