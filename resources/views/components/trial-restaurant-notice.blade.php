@php
    // Heads-up for trial companies using Restaurant & Kitchen features (KDS,
    // Tables, KOT...): those flags are trial-only and get masked OFF the moment
    // the trial expires (PosFeatureService::restaurantAllowed). Shown to POS
    // admins/managers only, dismissible per-day via localStorage.
    $rnShow = false;
    $rnUser = auth('pos')->user();
    if ($rnUser && $rnUser->isPosAdmin()) {
        $rnCompany = \App\Models\Company::find($rnUser->company_id);
        if ($rnCompany && \App\Services\PosFeatureService::restaurantAccessSource($rnCompany) === 'trial') {
            // Only nag shops actually using kitchen features.
            $rnFeatures = \App\Services\PosFeatureService::forCompany($rnCompany);
            foreach (\App\Services\PosFeatureService::RESTAURANT_FLAGS as $rnFlag) {
                if (!empty($rnFeatures->{$rnFlag})) { $rnShow = true; break; }
            }
        }
    }
@endphp

@if($rnShow)
<div x-data="{
        show: false,
        key: 'trial_restaurant_notice_{{ now()->toDateString() }}',
        init() {
            try { if (localStorage.getItem(this.key) !== '1') this.show = true; }
            catch (e) { this.show = true; }
        },
        dismiss() {
            this.show = false;
            try { localStorage.setItem(this.key, '1'); } catch (e) {}
        }
     }"
     x-show="show" x-cloak class="relative z-40">
    <div class="flex items-center gap-3 px-4 py-2.5 bg-orange-50 dark:bg-orange-900/30 border-b border-orange-200 dark:border-orange-800 text-orange-800 dark:text-orange-200 text-sm">
        <span class="text-base leading-none flex-shrink-0">🍽️</span>
        <span class="flex-1 font-medium">
            {{ __('pos.trn_line1') }}
            <span class="hidden sm:inline font-normal opacity-80">{{ __('pos.trn_line2') }}</span>
        </span>
        <a href="{{ route('pos.billing') }}" class="flex-shrink-0 px-3 py-1 rounded-lg bg-orange-600 hover:bg-orange-700 text-white text-xs font-bold shadow-sm">{{ __('pos.trn_view_plans') }}</a>
        <button @click="dismiss()" class="flex-shrink-0 p-1 rounded hover:bg-orange-100 dark:hover:bg-orange-800/50" aria-label="{{ __('pos.dismiss') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
</div>
@endif
