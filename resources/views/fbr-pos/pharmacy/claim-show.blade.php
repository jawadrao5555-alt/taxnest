{{--
    💊 One distributor claim (Task 1558).

    The lifecycle is deliberately visible: DRAFT is a list being built, RAISED
    is the moment the goods physically leave (and the only moment stock is
    written off), SETTLED / CREDITED / REJECTED only record the money answer.

    Expects: $claim (with items + supplier), $company.
--}}
<x-fbr-pos-layout>
<div class="max-w-5xl mx-auto">
    @include('fbr-pos.partials.back-link')

    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $claim->claim_number }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $claim->supplier?->name ?? $claim->supplier_name ?? __('pos.ph_no_supplier') }}
                · {{ $claim->created_at?->format('d M Y') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <span class="px-3 py-1.5 rounded-xl text-xs font-bold {{ $claim->isClosed() ? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' }}">
                {{ __('pos.ph_claim_status_' . $claim->status) }}
            </span>
            <a href="{{ route('fbrpos.pharmacy.claim.print', $claim->id) }}" target="_blank"
               class="px-4 py-2 rounded-xl bg-blue-600 text-white text-sm font-bold hover:bg-blue-700">🖨 {{ __('pos.print') }}</a>
        </div>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>@endif

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden mb-5">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3 text-left">{{ __('pos.ph_col_medicine') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('pos.ph_col_batch') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('pos.ph_col_expiry') }}</th>
                    <th class="px-4 py-3 text-left">{{ __('pos.ph_writeoff_reason') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('pos.ph_col_qty') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('pos.ph_col_cost') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('pos.ph_col_value') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach($claim->items as $it)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-semibold text-gray-900 dark:text-white">{{ $it->item_name }}</div>
                        @if($it->product?->generic_name)<div class="text-xs text-gray-500">{{ $it->product->generic_name }} {{ $it->product->strength }}</div>@endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $it->batch_number }}</td>
                    <td class="px-4 py-3">{{ $it->expiry_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs">{{ __('pos.ph_reason_' . $it->reason) }}</td>
                    <td class="px-4 py-3 text-right">{{ rtrim(rtrim(number_format((float) $it->quantity, 3, '.', ''), '0'), '.') }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float) $it->cost_price, 2) }}</td>
                    <td class="px-4 py-3 text-right font-semibold">{{ number_format((float) $it->total_amount, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="bg-gray-50 dark:bg-gray-800 font-bold">
                <tr>
                    <td colspan="6" class="px-4 py-3 text-right">{{ __('pos.ph_col_value') }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float) $claim->total_amount, 2) }}</td>
                </tr>
                @if($claim->settled_amount !== null)
                <tr class="text-green-700 dark:text-green-400">
                    <td colspan="6" class="px-4 py-3 text-right">{{ __('pos.ph_col_settled') }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float) $claim->settled_amount, 2) }}</td>
                </tr>
                @endif
            </tfoot>
        </table>
    </div>

    @if($claim->notes)
    <p class="text-sm text-gray-600 dark:text-gray-300 mb-5"><span class="font-semibold">{{ __('pos.ph_col_note') }}:</span> {{ $claim->notes }}</p>
    @endif

    @unless($claim->isClosed())
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-5">
        <h2 class="text-sm font-bold text-gray-900 dark:text-white mb-1">{{ __('pos.ph_claim_next_title') }}</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
            {{ $claim->status === \App\Models\PharmacyClaim::STATUS_DRAFT ? __('pos.ph_claim_draft_hint') : __('pos.ph_claim_raised_hint') }}
        </p>
        <form method="POST" action="{{ route('fbrpos.pharmacy.claim.status', $claim->id) }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
            @csrf
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_status') }}</label>
                <select name="status" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
                    @if($claim->status === \App\Models\PharmacyClaim::STATUS_DRAFT)
                        <option value="raised">{{ __('pos.ph_claim_status_raised') }}</option>
                    @endif
                    <option value="settled">{{ __('pos.ph_claim_status_settled') }}</option>
                    <option value="credited">{{ __('pos.ph_claim_status_credited') }}</option>
                    <option value="rejected">{{ __('pos.ph_claim_status_rejected') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_settled') }}</label>
                <input type="number" name="settled_amount" step="0.01" min="0" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.ph_col_note') }}</label>
                <input type="text" name="notes" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-sm">
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-emerald-600 text-white text-sm font-bold hover:bg-emerald-700">{{ __('pos.save') }}</button>
        </form>
    </div>
    @endunless
</div>
</x-fbr-pos-layout>
