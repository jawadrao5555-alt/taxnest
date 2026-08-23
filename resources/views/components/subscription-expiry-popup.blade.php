@php
    // Subscription-expiry warning POPUP (owner request, 9 Aug 2026):
    // subscription khatam hone se 2 din pehle modal popup — dismiss ho sakta hai
    // lekin HAR 6 GHANTE baad khud dobara aata hai (localStorage snooze) jab tak
    // renewal na ho. Maqsad: "humein pata nahi laga, raat ko tang hue" eitraz khatam.
    // Complements the top reminder banner + the post-expiry lock modal (mutually
    // exclusive: yeh sirf TAB dikhta hai jab access ABHI allowed ho).
    $sePopup = null;
    $seGuard = null; $seUser = null;
    foreach (['web', 'pos', 'fbrpos'] as $seG) {
        if (auth($seG)->check()) { $seGuard = $seG; $seUser = auth($seG)->user(); break; }
    }
    $seCompanyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : ($seUser->company_id ?? null);

    // Confined POS roles (waiter/kitchen/rider/…) + view-only impersonation +
    // pending companies: popup skip (same convention as the What's New popup).
    $seImp = session('impersonation');
    $seReadonlyImp = is_array($seImp) && !empty($seImp['readonly']);
    $seConfined = $seGuard === 'pos' && in_array($seUser->pos_role ?? null,
        ['pos_waiter', 'pos_kitchen', 'pos_rider', 'pos_delivery', 'archive_viewer', 'local_viewer'], true);

    $seCompany = null;
    if ($seCompanyId && $seGuard && !$seReadonlyImp && !$seConfined) {
        try {
            $seCompany = \App\Models\Company::find($seCompanyId);
            if ($seCompany && !$seCompany->is_internal_account && ($seCompany->status ?? null) !== 'pending') {
                // 1) Temporary/grace admin grant ending soon. FBR POS shops on a
                //    TEMPORARY grant get a 7-din early warning (Task: free-access
                //    expiry reminder, Aug 2026) so woh Business/Pro upgrade ka
                //    faisla waqt par kar saken; baqi sab 2 din pehle.
                $seOv = \App\Services\SubscriptionAccessService::overrideReminder($seCompany);
                $seOvWindow = ($seCompany->product_type === 'fbrpos' && ($seOv['type'] ?? null) === 'temporary') ? 7 : 2;
                if ($seOv && $seOv['days_left'] !== null && (int) $seOv['days_left'] <= $seOvWindow) {
                    $sePopup = ['kind' => 'override', 'until' => $seOv['until'], 'days_left' => (int) $seOv['days_left']];
                }
                // 2) Paid subscription ending within 2 days
                if (!$sePopup) {
                    $sePe = \App\Services\SubscriptionAccessService::paidEndingReminder($seCompany);
                    if ($sePe) {
                        $sePopup = ['kind' => 'paid', 'until' => $sePe['until'], 'days_left' => (int) $sePe['days_left']];
                    }
                }
                // 3) Free trial ending within 2 days (date-based)
                if (!$sePopup) {
                    $seSt = \App\Services\SubscriptionAccessService::trialStatus($seCompany);
                    if ($seSt && $seSt['on_trial'] && $seSt['days_left'] !== null && (int) $seSt['days_left'] <= 2) {
                        $seSub = \App\Models\Subscription::where('company_id', $seCompany->id)
                            ->where('active', true)->orderByDesc('id')->first();
                        $seUntil = $seSub && $seSub->trial_ends_at ? $seSub->trial_ends_at->format('Y-m-d') : null;
                        $sePopup = ['kind' => 'trial', 'until' => $seUntil, 'days_left' => (int) $seSt['days_left']];
                    }
                }
            }
        } catch (\Throwable $seEx) {
            $sePopup = null; // prod schema drift must never 500 a page — fail silent
        }
    }

    $seForceOpen = false;
    if ($sePopup) {
        $seKindLabel = match ($sePopup['kind']) {
            'trial' => __('pos.se_kind_trial'),
            'override' => __('pos.se_kind_temp'),
            default => __('pos.se_kind_sub'),
        };
        $seUntilNice = \Carbon\Carbon::parse($sePopup['until'] ?? now())->format('d M Y');
        $seDays = $sePopup['days_left'];

        // Payment account details (same SystemSetting keys as the lock modal)
        $seBank = [
            'bank_name' => \App\Models\SystemSetting::get('payment_bank_name', ''),
            'account_title' => \App\Models\SystemSetting::get('payment_account_title', ''),
            'account_number' => \App\Models\SystemSetting::get('payment_account_number', ''),
            'iban' => \App\Models\SystemSetting::get('payment_iban', ''),
            'instructions' => \App\Models\SystemSetting::get('payment_instructions', ''),
        ];
        $seWaNumber = preg_replace('/\D/', '', (string) \App\Models\SystemSetting::get('support_whatsapp_number', ''));
        $seWaMsg = "Hello, I want to RENEW my TaxNest subscription.\n"
            . 'Company: ' . ($seCompany->name ?? '') . "\n"
            . 'NTN: ' . ($seCompany->ntn ?? '') . "\n"
            . 'Payment receipt attached.';
        $seWaLink = $seWaNumber ? 'https://wa.me/' . $seWaNumber . '?text=' . urlencode($seWaMsg) : null;

        // Renew form: POS/FBR-POS cashiers ko sirf warning (payment admin/manager ka
        // kaam) — baqi sab ko package select + receipt upload (lock modal jaisa form).
        // Code-review fix (9 Aug 2026): fbrpos guard bhi isPosAdmin() gate ke andar —
        // warna FBR cashier ko bank details + renewal form leak hota.
        $seCanRenew = !in_array($seGuard, ['pos', 'fbrpos'], true)
            || (method_exists($seUser, 'isPosAdmin') && $seUser->isPosAdmin());

        $seSubmit = null; $sePendingProof = false; $sePlans = []; $seCycles = [];
        if ($seCanRenew) {
            if ($seGuard === 'pos') {
                $seSubmit = route('pos.payment-proof.store');
                $seProductType = (($seCompany->pos_integration_mode ?? 'pra') === 'standalone') ? 'standalone' : 'pos';
            } elseif ($seGuard === 'fbrpos') {
                $seSubmit = route('fbrpos.payment-proof.store');
                $seProductType = 'fbrpos';
            } else {
                $seSubmit = route('payment-proof.store');
                $seProductType = 'di';
            }
            // Annual-only since 23 Aug 2026 (owner): every product line is
            // sold by the YEAR, so there is nothing left to pick here.
            $seCycles = [['key' => 'annual', 'label' => __('pos.cycle_annual')]];
            try {
                // Total = base package + paid extra-branch slots (Rs 10,000/branch/
                // year). $seCompany is passed so this popup shows the SAME number
                // the renewal actually charges.
                foreach (\App\Models\PricingPlan::where('is_trial', false)->where('product_type', $seProductType)->orderBy('price')->get() as $seLp) {
                    $sePrices = [];
                    foreach ($seCycles as $seLc) {
                        $sePrices[$seLc['key']] = \App\Services\SubscriptionAssignmentService::computePrice($seLp, $seLc['key'], $seCompany)['final_price'];
                    }
                    $sePlans[] = ['id' => $seLp->id, 'name' => $seLp->name, 'prices' => $sePrices];
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('payment_proofs')) {
                    // Package proofs only — an extra-branch request must not
                    // replace the renewal form with "under review".
                    $sePendingProof = \App\Models\PaymentProof::subscriptionKind()->where('company_id', $seCompanyId)
                        ->where('status', 'pending')->exists();
                }
            } catch (\Throwable $seEx2) {
                $sePlans = []; $seSubmit = null; // fail silent — warning phir bhi dikhe
            }
        }

        // Form validation-error / just-submitted state: popup zabardasti khule
        // (lock modal never coexists — yeh sirf pre-expiry render hota hai).
        $seForceOpen = session('payment_proof') || $errors->has('proof') || $errors->has('amount')
            || $errors->has('pricing_plan_id') || $errors->has('billing_cycle');

        $seBranchSlots = \App\Services\BranchAddonService::slots($seCompany);
        $seSnoozeKey = 'se_snooze_' . $seCompanyId . '_' . ($sePopup['until'] ?? 'x');
    }
@endphp

@if($sePopup)
<div x-data="{
        open: false,
        init() {
            @if($seForceOpen) this.open = true; return; @endif
            try {
                const t = parseInt(localStorage.getItem('{{ $seSnoozeKey }}') || '0', 10);
                if (!t || (Date.now() - t) >= 21600000) this.open = true;
            } catch (e) { this.open = true; }
        },
        snooze() {
            this.open = false;
            try { localStorage.setItem('{{ $seSnoozeKey }}', String(Date.now())); } catch (e) {}
        }
     }"
     x-cloak>
    <div x-show="open" class="fixed inset-0 z-[70] flex items-center justify-center p-4" style="display:none;">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="snooze()"></div>

        {{-- dir=rtl in Urdu-script locale: without it, mixed Urdu+date lines ("12 Aug 2026") get bidi-scrambled --}}
        <div @if(app()->getLocale() === 'ur') dir="rtl" @endif
             class="relative w-full max-w-lg bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden max-h-[90vh] overflow-y-auto"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">

            <div class="bg-gradient-to-r from-red-600 to-orange-500 px-6 py-5 text-white">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold leading-tight">
                                @if($seDays <= 0)
                                    {{ __('pos.se_title_today', ['kind' => $seKindLabel]) }}
                                @else
                                    {{ __('pos.se_title_soon', ['kind' => $seKindLabel]) }}
                                @endif
                            </h2>
                            <p class="text-xs text-white/80 mt-0.5">{{ $seCompany->name ?? '' }}</p>
                        </div>
                    </div>
                    <button type="button" @click="snooze()" class="text-white/80 hover:text-white" aria-label="{{ __('pos.close') }}">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <div class="px-6 py-5 space-y-4">
                <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4">
                    <p class="text-sm font-semibold text-red-700 dark:text-red-300">
                        @if($seDays <= 0)
                            {{ __('pos.se_line_today', ['kind' => $seKindLabel, 'date' => $seUntilNice]) }}
                        @elseif($seDays == 1)
                            {{ __('pos.se_line_1day', ['kind' => $seKindLabel, 'date' => $seUntilNice]) }}
                        @else
                            {{ __('pos.se_line_days', ['days' => $seDays, 'kind' => $seKindLabel, 'date' => $seUntilNice]) }}
                        @endif
                    </p>
                    <p class="text-xs text-red-600/80 dark:text-red-300/80 mt-1.5">
                        {{ __('pos.se_consequence') }}
                    </p>
                </div>

                @if($seBank['bank_name'] || $seBank['account_number'] || $seBank['iban'])
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.pp_account_details') }}</p>
                    <dl class="space-y-2 text-sm">
                        @if($seBank['bank_name'])
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('pos.pp_bank') }}</dt><dd class="font-medium text-gray-800 dark:text-gray-100 text-right">{{ $seBank['bank_name'] }}</dd></div>
                        @endif
                        @if($seBank['account_title'])
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('pos.pp_account_title') }}</dt><dd class="font-medium text-gray-800 dark:text-gray-100 text-right">{{ $seBank['account_title'] }}</dd></div>
                        @endif
                        @if($seBank['account_number'])
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('pos.pp_account_no') }}</dt><dd class="font-medium text-gray-800 dark:text-gray-100 text-right select-all">{{ $seBank['account_number'] }}</dd></div>
                        @endif
                        @if($seBank['iban'])
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">IBAN</dt><dd class="font-medium text-gray-800 dark:text-gray-100 text-right select-all">{{ $seBank['iban'] }}</dd></div>
                        @endif
                    </dl>
                    @if($seBank['instructions'])
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">{{ $seBank['instructions'] }}</p>
                    @endif
                </div>
                @endif

                @if(!$seCanRenew)
                <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-700 dark:text-amber-300">
                    {{ __('pos.se_tell_admin') }}
                </div>
                @elseif($sePendingProof)
                <div class="rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-4 text-sm text-blue-700 dark:text-blue-300 flex items-start gap-2">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>{{ __('pos.se_under_review') }}</span>
                </div>
                @elseif($seSubmit && count($sePlans))
                <form method="POST" action="{{ $seSubmit }}" enctype="multipart/form-data"
                      x-data="{
                        plans: {{ \Illuminate\Support\Js::from($sePlans) }},
                        cycles: {{ \Illuminate\Support\Js::from($seCycles) }},
                        planId: '{{ old('pricing_plan_id') }}',
                        cycle: 'annual',
                        get price() {
                            const p = this.plans.find(x => String(x.id) === String(this.planId));
                            return p ? (p.prices[this.cycle] ?? null) : null;
                        },
                        get cycleLabel() {
                            const c = this.cycles.find(x => x.key === this.cycle);
                            return c ? c.label : '';
                        }
                      }"
                      class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 p-4 space-y-3">
                    @csrf
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('pos.se_choose_package') }}</p>

                    <div class="grid grid-cols-1 {{ count($seCycles) > 1 ? 'sm:grid-cols-2' : '' }} gap-2">
                        <select name="pricing_plan_id" x-model="planId" required
                                class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                            <option value="">{{ __('pos.pp_select_package') }}</option>
                            <template x-for="p in plans" :key="p.id">
                                <option :value="p.id" x-text="p.name"></option>
                            </template>
                        </select>
                        @if(count($seCycles) > 1)
                        <select name="billing_cycle" x-model="cycle" required
                                class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                            <template x-for="c in cycles" :key="c.key">
                                <option :value="c.key" x-text="c.label"></option>
                            </template>
                        </select>
                        @else
                        <input type="hidden" name="billing_cycle" value="annual">
                        @endif
                    </div>
                    @error('pricing_plan_id')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                    @error('billing_cycle')<p class="text-xs text-red-500">{{ $message }}</p>@enderror

                    <div x-show="price !== null" x-cloak class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
                        <span x-text="cycleLabel"></span> {{ __('pos.pp_package_total') }}
                        <span class="font-bold">PKR <span x-text="price !== null ? Number(price).toLocaleString() : ''"></span></span>
                    </div>
                    @if($seBranchSlots > 0)
                    {{-- Paid extra branches ride on the package total (Rs 10,000/branch/year). --}}
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.eb_total_note', ['slots' => $seBranchSlots, 'price' => number_format(\App\Services\BranchAddonService::PRICE_PER_YEAR)]) }}</p>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" placeholder="{{ __('pos.pp_amount_paid') }}"
                               class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                        <select name="payment_method"
                                class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                            <option value="">{{ __('pos.pp_payment_method') }}</option>
                            <option value="bank" @selected(old('payment_method') === 'bank')>{{ __('pos.pp_method_bank') }}</option>
                            <option value="jazzcash" @selected(old('payment_method') === 'jazzcash')>JazzCash</option>
                            <option value="easypaisa" @selected(old('payment_method') === 'easypaisa')>EasyPaisa</option>
                            <option value="other" @selected(old('payment_method') === 'other')>{{ __('pos.pp_method_other') }}</option>
                        </select>
                    </div>
                    <input type="text" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="{{ __('pos.pp_reference') }}"
                           class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                    <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required
                           class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-amber-500 file:text-white hover:file:bg-amber-400">
                    @error('proof')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                    @error('amount')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                    <p class="text-[11px] text-gray-400">{{ __('pos.pp_accepted_formats') }}</p>
                    <button type="submit"
                            class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-white text-sm font-semibold transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        {{ __('pos.pp_upload_proof') }}
                    </button>
                </form>
                @endif

                <div class="flex flex-col sm:flex-row gap-3 pt-1">
                    @if($seWaLink)
                    <a href="{{ $seWaLink }}" target="_blank" rel="noopener"
                       class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-[#25D366] hover:bg-[#1ebe5b] text-white text-sm font-semibold transition">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.515 5.26l-.999 3.648 3.973-1.715z"/></svg>
                        {{ __('pos.se_whatsapp') }}
                    </a>
                    @endif
                    <button type="button" @click="snooze()"
                            class="px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                        {{ __('pos.se_later') }}
                    </button>
                </div>
                <p class="text-[11px] text-center text-gray-400">{{ __('pos.se_6h_note') }}</p>
            </div>
        </div>
    </div>
</div>
@endif
