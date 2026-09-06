{{--
    💊 MRP price updates from the medicine catalogue (Task 1579).

    One row per linked product whose notified MRP changed since the shop added
    it. Owner decides per row (apply / dismiss) or applies all. Applying moves
    the product's MRP; the sale price follows only when it equalled the old MRP.
    Nothing here happens on its own.

    Expects: $pending, $history (collections of MedicinePriceNotice with
    product + entry), $isAdmin.
--}}
<x-fbr-pos-layout>
<div class="max-w-6xl mx-auto">
    @include('fbr-pos.partials.back-link')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">💊 {{ __('pos.ph_cat_pu_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.ph_cat_pu_sub') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('fbrpos.products') }}" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.products_word') }}</a>
            @if($isAdmin && $pending->isNotEmpty())
            <form method="POST" action="{{ route('fbrpos.pharmacy.price-updates.apply-all') }}" onsubmit="return confirm(@js(__('pos.ph_cat_pu_apply_all_confirm', ['n' => $pending->count()])))">
                @csrf
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 text-white text-sm font-bold hover:bg-emerald-700">{{ __('pos.ph_cat_pu_apply_all') }} ({{ $pending->count() }})</button>
            </form>
            @endif
        </div>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>@endif

    <section class="mb-8 bg-white dark:bg-gray-900 rounded-2xl border border-amber-200 dark:border-amber-900/50 overflow-hidden">
        <div class="px-4 py-3 bg-amber-50 dark:bg-amber-900/20 flex items-center justify-between">
            <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ __('pos.ph_cat_pu_pending') }}</h2>
            <span class="text-xs font-bold text-amber-800 dark:text-amber-200">{{ $pending->count() }}</span>
        </div>
        @if($pending->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-gray-400">{{ __('pos.ph_cat_pu_none') }}</p>
        @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-[11px] uppercase text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('pos.product_word') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('pos.ph_cat_pu_old') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('pos.ph_cat_pu_new') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('pos.ph_cat_pu_sale_now') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('pos.ph_cat_pu_effective') }}</th>
                        @if($isAdmin)<th class="px-3 py-2"></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($pending as $n)
                    @php
                        $old = $n->old_mrp !== null ? (float) $n->old_mrp : null;
                        $new = (float) $n->new_mrp;
                        $sale = (float) $n->product->default_price;
                        $follows = $old !== null && abs($sale - $old) < 0.005;
                        $up = $old === null || $new > $old;
                    @endphp
                    <tr>
                        <td class="px-4 py-2.5 align-top">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $n->product->name }}</div>
                            <div class="text-[11px] text-gray-400">{{ $n->entry?->manufacturer }} @if($n->entry?->drap_reg_no) · DRAP {{ $n->entry->drap_reg_no }} @endif</div>
                        </td>
                        <td class="px-3 py-2.5 align-top text-right text-gray-500 whitespace-nowrap">{{ $old !== null ? 'Rs ' . number_format($old, 2) : '—' }}</td>
                        <td class="px-3 py-2.5 align-top text-right font-bold whitespace-nowrap {{ $up ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">Rs {{ number_format($new, 2) }} <span class="text-[10px]">{{ $up ? '▲' : '▼' }}</span></td>
                        <td class="px-3 py-2.5 align-top text-right whitespace-nowrap text-gray-700 dark:text-gray-200">
                            Rs {{ number_format($sale, 2) }}
                            <div class="text-[10px] text-gray-400">{{ $follows ? __('pos.ph_cat_pu_sale_follows') : __('pos.ph_cat_pu_sale_stays') }}</div>
                        </td>
                        <td class="px-3 py-2.5 align-top text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap">{{ $n->effective_date?->format('d M Y') ?? '—' }}</td>
                        @if($isAdmin)
                        <td class="px-3 py-2.5 align-top text-right whitespace-nowrap">
                            <form method="POST" action="{{ route('fbrpos.pharmacy.price-updates.apply', $n->id) }}" class="inline">@csrf
                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700">{{ __('pos.ph_cat_pu_apply') }}</button>
                            </form>
                            <form method="POST" action="{{ route('fbrpos.pharmacy.price-updates.dismiss', $n->id) }}" class="inline">@csrf
                                <button type="submit" class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 text-xs font-semibold hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.ph_cat_pu_dismiss') }}</button>
                            </form>
                        </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>

    @if($history->isNotEmpty())
    <section class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800/60">
            <h2 class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ __('pos.ph_cat_pu_history') }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($history as $n)
                    <tr class="text-gray-600 dark:text-gray-300">
                        <td class="px-4 py-2">{{ $n->product->name }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ $n->old_mrp !== null ? number_format((float) $n->old_mrp, 2) : '—' }} → <strong>{{ number_format((float) $n->new_mrp, 2) }}</strong></td>
                        <td class="px-3 py-2 text-xs whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded-full font-semibold {{ $n->status === 'applied' ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">{{ $n->status === 'applied' ? __('pos.ph_cat_pu_st_applied') : __('pos.ph_cat_pu_st_dismissed') }}</span>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-400 whitespace-nowrap">{{ $n->acted_at?->format('d M Y H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif

    <p class="mt-6 text-[11px] text-gray-400">{{ __('pos.ph_cat_source_note') }}</p>
</div>
</x-fbr-pos-layout>
