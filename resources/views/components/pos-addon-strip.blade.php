{{--
    Public paid-feature picker for PRA POS.

    Catalogue labels and prices come from the same server-side service used by
    the authenticated billing page. The browser only builds a quote and carries
    selected CODES to registration; registration and payment approval validate
    the codes and calculate the amount again on the server.
--}}
@php
    $adsCatalog = \App\Services\PosAddonPricingService::catalog();
    $adsPrices = [];
    $adsLabels = [];
    foreach ($adsCatalog as $adsCode => $adsSpec) {
        $adsPrices[$adsCode] = [
            'annual' => (int) $adsSpec['annual_price'],
            'quarterly' => (int) $adsSpec['quarterly_price'],
            'monthly' => (int) $adsSpec['monthly_price'],
        ];
        $adsLabels[$adsCode] = __('pos.addon_label_' . $adsCode);
    }
@endphp

@once
<style>
    /* Plain CSS on purpose: public landings are not allowed to depend on a new
       Tailwind class making it into a later Vite build. */
    .tn-addons { margin-top:4.5rem; }
    .tn-addons__head { max-width:40rem; margin:0 auto 1.75rem; text-align:center; }
    .tn-addons__head h3 { margin:0; color:#052730; font-family:'Playfair Display',Georgia,serif; font-size:1.75rem; line-height:1.2; }
    .tn-addons__head p { margin:.625rem 0 0; color:#6B7280; font-size:.875rem; line-height:1.6; }
    .tn-addons__toolbar { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin:0 auto 1rem; max-width:64rem; }
    .tn-addons__cycles { display:inline-flex; padding:.25rem; border:1px solid #D1D5DB; background:#FFFFFF; }
    .tn-addons__cycle { border:0; padding:.5rem .875rem; background:transparent; color:#6B7280; cursor:pointer; font-size:.75rem; font-weight:700; }
    .tn-addons__cycle.is-active { background:#0A4D5C; color:#FFFFFF; }
    .tn-addons__hint { margin:0; color:#6B7280; font-size:.75rem; text-align:right; }
    .tn-addons__grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:1rem; max-width:64rem; margin:0 auto; }
    .tn-addons__card { position:relative; display:flex; min-height:10.5rem; flex-direction:column; padding:1.125rem; border:1px solid #D1D5DB; background:#FFFFFF; cursor:pointer; transition:border-color .15s,background-color .15s,box-shadow .15s; }
    .tn-addons__card:hover { border-color:#0A4D5C; }
    .tn-addons__card.is-selected { border-color:#0A4D5C; background:#F3F8F8; box-shadow:0 0 0 1px #0A4D5C; }
    .tn-addons__top { display:flex; align-items:flex-start; gap:.625rem; }
    .tn-addons__check { width:1rem; height:1rem; margin-top:.2rem; accent-color:#0A4D5C; flex:none; }
    .tn-addons__name { margin:0; color:#052730; font-size:.9375rem; font-weight:700; }
    .tn-addons__desc { margin:.35rem 0 0; color:#6B7280; font-size:.75rem; line-height:1.5; }
    .tn-addons__price { margin:auto 0 0; padding-top:1rem; color:#052730; font-size:1rem; font-weight:800; }
    .tn-addons__period { color:#6B7280; font-size:.6875rem; font-weight:500; }
    .tn-addons__bill { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:1.5rem; align-items:center; max-width:64rem; margin:1.25rem auto 0; padding:1.25rem 1.5rem; border:1px solid rgba(10,77,92,.28); background:#07333E; color:#FFFFFF; }
    .tn-addons__bill-title { margin:0; font-family:'Playfair Display',Georgia,serif; font-size:1.25rem; }
    .tn-addons__bill-count { margin:.25rem 0 0; color:#CBD5E1; font-size:.75rem; }
    .tn-addons__bill-total { margin:.25rem 0 0; color:#E7BF3B; font-family:'JetBrains Mono',ui-monospace,monospace; font-size:1.25rem; font-weight:800; }
    .tn-addons__continue { display:inline-flex; align-items:center; justify-content:center; min-width:13rem; padding:.75rem 1rem; border:1px solid #E7BF3B; background:#E7BF3B; color:#052730; font-size:.75rem; font-weight:800; letter-spacing:.04em; text-align:center; text-decoration:none; text-transform:uppercase; }
    .tn-addons__continue:hover, .tn-addons__continue:focus { background:#FFFFFF; border-color:#FFFFFF; }
    .tn-addons__continue.is-disabled { pointer-events:none; opacity:.45; }
    .tn-addons__note { max-width:42rem; margin:.875rem auto 0; color:#6B7280; font-size:.75rem; line-height:1.5; text-align:center; }
    [dir="rtl"] .tn-addons__hint { text-align:left; }
    @media (max-width:767px) {
        .tn-addons { margin-top:3.5rem; }
        .tn-addons__toolbar { align-items:stretch; flex-direction:column; }
        .tn-addons__hint { text-align:left; }
        .tn-addons__grid { grid-template-columns:1fr; }
        .tn-addons__bill { grid-template-columns:1fr; }
        .tn-addons__continue { width:100%; }
    }
    @media (min-width:640px) and (max-width:1023px) {
        .tn-addons__grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }
</style>
@endonce

<div class="tn-addons"
     x-data="{
        cycle: 'annual',
        picked: [],
        prices: {{ \Illuminate\Support\Js::from($adsPrices) }},
        labels: {{ \Illuminate\Support\Js::from($adsLabels) }},
        selectedSuffix: {{ \Illuminate\Support\Js::from(__('pos.addons_selected_suffix')) }},
        priceOf(code) {
            const row = this.prices[code];
            return row ? Number(row[this.cycle] || 0) : 0;
        },
        total() {
            return this.picked.reduce((sum, code) => sum + this.priceOf(code), 0);
        },
        fmt(value) {
            return Number(value || 0).toLocaleString('en-US');
        },
        signupUrl() {
            const params = new URLSearchParams();
            this.picked.forEach(code => params.append('addons[]', code));
            params.set('addon_cycle', this.cycle);
            return '/pos/register?' + params.toString();
        }
     }">
    <div class="tn-addons__head">
        <h3>{{ __('pos.addons_title') }}</h3>
        <p>{{ __('pos.addons_public_subtitle') }}</p>
    </div>

    <div class="tn-addons__toolbar">
        <div class="tn-addons__cycles" role="group" aria-label="{{ __('pos.addons_choose_cycle') }}">
            <button type="button" class="tn-addons__cycle" :class="{ 'is-active': cycle === 'annual' }" @click="cycle = 'annual'">
                {{ __('pos.addons_cycle_annual') }}
            </button>
            <button type="button" class="tn-addons__cycle" :class="{ 'is-active': cycle === 'monthly' }" @click="cycle = 'monthly'">
                {{ __('pos.addons_cycle_monthly') }}
            </button>
            <button type="button" class="tn-addons__cycle" :class="{ 'is-active': cycle === 'quarterly' }" @click="cycle = 'quarterly'">
                {{ __('pos.addons_cycle_quarterly') }}
            </button>
        </div>
        <p class="tn-addons__hint">{{ __('pos.addons_public_hint') }}</p>
    </div>

    <div class="tn-addons__grid">
        @foreach($adsCatalog as $adsCode => $adsSpec)
        <label class="tn-addons__card"
               :class="{ 'is-selected': picked.includes({{ \Illuminate\Support\Js::from($adsCode) }}) }">
            <div class="tn-addons__top">
                <input type="checkbox"
                       class="tn-addons__check"
                       value="{{ $adsCode }}"
                       x-model="picked"
                       aria-label="{{ __('pos.addon_label_' . $adsCode) }}">
                <div>
                    <p class="tn-addons__name">{{ __('pos.addon_label_' . $adsCode) }}</p>
                    <p class="tn-addons__desc">{{ __('pos.addon_desc_' . $adsCode) }}</p>
                </div>
            </div>
            <p class="tn-addons__price">
                PKR <span x-text="fmt(priceOf({{ \Illuminate\Support\Js::from($adsCode) }}))">{{ number_format($adsSpec['annual_price']) }}</span>
                <span class="tn-addons__period"
                      x-text="({{ \Illuminate\Support\Js::from([
                          'annual' => '/ ' . __('pos.addons_per_year'),
                          'quarterly' => '/ ' . __('pos.addons_per_quarter'),
                          'monthly' => '/ ' . __('pos.addons_per_month'),
                      ]) }})[cycle]">
                    / {{ __('pos.addons_per_year') }}
                </span>
            </p>
        </label>
        @endforeach
    </div>

    <div class="tn-addons__bill" aria-live="polite">
        <div>
            <p class="tn-addons__bill-title">{{ __('pos.addons_bill_title') }}</p>
            <p class="tn-addons__bill-count" x-text="picked.length + ' ' + selectedSuffix">0 {{ __('pos.addons_selected_suffix') }}</p>
            <p class="tn-addons__bill-total">PKR <span x-text="fmt(total())">0</span></p>
        </div>
        <a class="tn-addons__continue"
           :class="{ 'is-disabled': picked.length === 0 }"
           :aria-disabled="picked.length === 0 ? 'true' : 'false'"
           :href="signupUrl()">
            {{ __('pos.addons_continue_cta') }}
        </a>
    </div>

    <p class="tn-addons__note">{{ __('pos.addons_public_note') }}</p>
</div>
