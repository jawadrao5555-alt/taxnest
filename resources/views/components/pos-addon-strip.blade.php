{{--
    Paid add-on catalogue strip (22 Aug 2026) — shown under the package
    comparison table on the PRA POS landing. The six optional features left
    the packages entirely; this is where a prospect learns they exist and
    what they cost. Names/descriptions come from the SAME lang keys the
    billing purchase box uses (pos.addon_label_* / pos.addon_desc_*), prices
    from PosAddonPricingService (admin-editable) — nothing here is hand-typed,
    so this strip and the purchase box can never disagree.
--}}
@php
    $adsCatalog = \App\Services\PosAddonPricingService::catalog();
@endphp

<div class="mt-14">
    <div class="text-center mb-8">
        <h3 class="text-2xl font-bold text-gray-900">{{ __('pos.addons_title') }}</h3>
        <p class="text-gray-500 text-sm mt-2 max-w-xl mx-auto">{{ __('pos.addons_subtitle') }}</p>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 max-w-5xl mx-auto">
        @foreach($adsCatalog as $adsCode => $adsSpec)
        <div class="rounded-2xl border border-gray-200 bg-white p-5 flex flex-col">
            <p class="font-semibold text-gray-900">{{ __('pos.addon_label_' . $adsCode) }}</p>
            <p class="text-xs text-gray-500 mt-1 flex-1">{{ __('pos.addon_desc_' . $adsCode) }}</p>
            <div class="mt-4 pt-3 border-t border-gray-100">
                <p class="text-lg font-bold text-gray-900">
                    Rs {{ number_format($adsSpec['annual_price']) }}
                    <span class="text-xs font-normal text-gray-400">/ {{ __('pos.addons_per_year') }}</span>
                </p>
                <p class="text-xs text-gray-400 mt-0.5">
                    Rs {{ number_format($adsSpec['quarterly_price']) }} / {{ __('pos.addons_per_quarter') }}
                </p>
            </div>
        </div>
        @endforeach
    </div>

    <p class="text-center text-xs text-gray-400 mt-4 max-w-2xl mx-auto">{{ __('pos.addons_renewal_note') }}</p>
</div>
