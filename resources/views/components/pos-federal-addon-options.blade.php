@props(['billing' => false, 'codes' => \App\Services\PosFederalAddonAccess::CODES, 'active' => [], 'pending' => [], 'purchasable' => []])

@php
    $definitions = \App\Services\PosAddonPricingService::definitions();
@endphp

<section class="mt-6 rounded-xl border border-gray-200 bg-white p-5 text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" aria-labelledby="federal-addon-heading">
    <h3 id="federal-addon-heading" class="text-lg font-bold">{{ __('pos_federal_addons.heading') }}</h3>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('pos_federal_addons.eligibility') }}</p>
    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
        @foreach($codes as $code)
            @php($annualPrice = \App\Services\PosAddonPricingService::price($code))
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <dt class="font-semibold">{{ $definitions[$code]['label'] }}</dt>
                <dd class="mt-1 text-sm">
                    @if(in_array($code, $active, true))
                        {{ __('pos_federal_addons.active') }}
                        <span class="mt-1 block text-gray-600 dark:text-gray-300">{{ __('pos_federal_addons.setup_optional') }}</span>
                    @elseif(in_array($code, $pending, true))
                        {{ __('pos.addons_pending_title') }}
                    @elseif($annualPrice > 0)
                        {{ __('pos_federal_addons.annual', ['amount' => number_format($annualPrice)]) }}
                        @if($billing && !in_array($code, $purchasable, true))
                            <span class="mt-1 block text-gray-600 dark:text-gray-300">{{ __('pos_addon_billing.federal_unavailable') }}</span>
                        @endif
                    @else
                        {{ __('pos_federal_addons.price_pending') }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
    @unless($billing)
        <a href="{{ route('pos.billing') }}" class="mt-4 inline-block text-sm font-semibold text-teal-700 underline dark:text-teal-300">{{ __('pos_federal_addons.review_account') }}</a>
    @endunless
</section>
