@php
    $lockInfo = null;
    $companyId = app()->bound('n') ? app('n') : null;
    if (!$companyId) {
        foreach (['web', 'pos', 'fbrpos'] as $guard) {
            if (auth($guard)->check()) { $companyId = auth($guard)->user()->company_id ?? null; break; }
        }
    }

    if ($companyId) {
        $lockCompany = \App\Models\Company::find($companyId);
        if ($lockCompany && !$lockCompany->is_internal_account) {
            $access = \App\Services\SubscriptionAccessService::hasAccess($lockCompany);
            if (!($access['allowed'] ?? true)) {
                $waNumber = preg_replace('/\D/', '', (string) \App\Models\SystemSetting::get('support_whatsapp_number', ''));
                $proofMsg = "Hello, I have made the payment for my TaxNest subscription.\n"
                    . "Company: " . ($lockCompany->name ?? '') . "\n"
                    . "NTN: " . ($lockCompany->ntn ?? '') . "\n"
                    . "Please find the payment proof attached.";
                $lockInfo = [
                    'reason' => $access['reason'] ?? 'Your free trial has ended.',
                    'company' => $lockCompany->name,
                    'wa' => $waNumber,
                    'wa_link' => $waNumber ? 'https://wa.me/' . $waNumber . '?text=' . urlencode($proofMsg) : null,
                    'bank_name' => \App\Models\SystemSetting::get('payment_bank_name', ''),
                    'account_title' => \App\Models\SystemSetting::get('payment_account_title', ''),
                    'account_number' => \App\Models\SystemSetting::get('payment_account_number', ''),
                    'iban' => \App\Models\SystemSetting::get('payment_iban', ''),
                    'instructions' => \App\Models\SystemSetting::get('payment_instructions', ''),
                ];
            }
        }
    }

    // Guard-aware submit target + "under review" state for the proof upload form.
    $submitAction = null;
    $pendingProof = false;
    $forceOpen = false;
    if ($lockInfo) {
        if (auth('pos')->check()) {
            $submitAction = route('pos.payment-proof.store');
        } elseif (auth('fbrpos')->check()) {
            $submitAction = route('fbrpos.payment-proof.store');
        } elseif (auth('web')->check()) {
            $submitAction = route('payment-proof.store');
        }
        $rejectedProof = null;
        $everRejected = false;
        if ($companyId && \Illuminate\Support\Facades\Schema::hasTable('payment_proofs')) {
            $pendingProof = \App\Models\PaymentProof::where('company_id', $companyId)
                ->where('status', 'pending')->exists();
            $everRejected = \App\Models\PaymentProof::where('company_id', $companyId)
                ->where('status', 'rejected')->exists();
            // No pending proof + the LATEST proof was rejected → show the
            // reason right where the company resubmits.
            if (!$pendingProof) {
                $latestProof = \App\Models\PaymentProof::where('company_id', $companyId)
                    ->orderByDesc('id')->first();
                if ($latestProof && $latestProof->status === 'rejected') {
                    $rejectedProof = $latestProof;
                }
            }
        }
        $forceOpen = session('payment_proof') || $errors->has('proof') || $errors->has('amount')
            || $errors->has('pricing_plan_id') || $errors->has('billing_cycle');

        // Package + duration the company is paying for — filtered to THIS panel's
        // product line so a POS company never sees DI plans (and vice-versa).
        if (auth('pos')->check()) {
            $lockProductType = (($lockCompany->pos_integration_mode ?? 'pra') === 'standalone') ? 'standalone' : 'pos';
        } elseif (auth('fbrpos')->check()) {
            $lockProductType = 'fbrpos';
        } else {
            $lockProductType = 'di';
        }

        // DI = full toggle; PRA POS = Annual + Quarterly (Aug 2026);
        // standalone / FBR POS = annual-only.
        $lockCycles = $lockProductType === 'di'
            ? [['key' => 'monthly', 'label' => 'Monthly'], ['key' => 'quarterly', 'label' => 'Quarterly'], ['key' => 'semi_annual', 'label' => 'Semi-Annual'], ['key' => 'annual', 'label' => 'Annual']]
            : ($lockProductType === 'pos'
                ? [['key' => 'annual', 'label' => 'Annual'], ['key' => 'quarterly', 'label' => 'Quarterly']]
                : [['key' => 'annual', 'label' => 'Annual']]);

        $lockPlans = [];
        foreach (\App\Models\PricingPlan::where('is_trial', false)->where('product_type', $lockProductType)->orderBy('price')->get() as $lp) {
            $prices = [];
            foreach ($lockCycles as $lc) {
                $prices[$lc['key']] = \App\Services\SubscriptionAssignmentService::computePrice($lp, $lc['key'])['final_price'];
            }
            $lockPlans[] = ['id' => $lp->id, 'name' => $lp->name, 'prices' => $prices];
        }
    }
@endphp

@if($lockInfo)
<div x-data="{
        open: false,
        init() {
            @if($forceOpen) this.open = true; return; @endif
            try { if (sessionStorage.getItem('trial_lock_dismissed') !== '1') this.open = true; }
            catch (e) { this.open = true; }
        },
        close() {
            this.open = false;
            try { sessionStorage.setItem('trial_lock_dismissed', '1'); } catch (e) {}
        }
     }"
     x-cloak>

    {{-- Reopen pill (always visible while locked) --}}
    <button type="button" @click="open = true"
            class="fixed z-[55] bottom-5 right-5 flex items-center gap-2 px-4 py-2.5 rounded-full shadow-lg bg-amber-500 hover:bg-amber-400 text-white text-sm font-semibold transition"
            style="padding-bottom: calc(0.625rem + env(safe-area-inset-bottom, 0px));">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        Subscription Locked
    </button>

    {{-- Modal --}}
    <div x-show="open" class="fixed inset-0 z-[70] flex items-center justify-center p-4" style="display:none;">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="close()"></div>

        <div class="relative w-full max-w-lg bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden max-h-[90vh] overflow-y-auto"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">

            <div class="bg-gradient-to-r from-amber-500 to-orange-600 px-6 py-5 text-white">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold leading-tight">Free Trial Ended</h2>
                            <p class="text-xs text-white/80 mt-0.5">{{ $lockInfo['company'] }}</p>
                        </div>
                    </div>
                    <button type="button" @click="close()" class="text-white/80 hover:text-white">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <div class="px-6 py-5 space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $lockInfo['reason'] }} Your account is now <strong>view-only</strong> — you can still browse your data, but creating new invoices / bills is disabled until you subscribe.
                </p>

                @if($lockInfo['bank_name'] || $lockInfo['account_number'] || $lockInfo['iban'])
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">Payment Account Details</p>
                    <dl class="space-y-2 text-sm">
                        @if($lockInfo['bank_name'])
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Bank</dt><dd class="font-medium text-gray-800 dark:text-gray-100 text-right">{{ $lockInfo['bank_name'] }}</dd></div>
                        @endif
                        @if($lockInfo['account_title'])
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Title</dt><dd class="font-medium text-gray-800 dark:text-gray-100 text-right">{{ $lockInfo['account_title'] }}</dd></div>
                        @endif
                        @if($lockInfo['account_number'])
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Account #</dt><dd class="font-medium text-gray-800 dark:text-gray-100 text-right select-all">{{ $lockInfo['account_number'] }}</dd></div>
                        @endif
                        @if($lockInfo['iban'])
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">IBAN</dt><dd class="font-medium text-gray-800 dark:text-gray-100 text-right select-all">{{ $lockInfo['iban'] }}</dd></div>
                        @endif
                    </dl>
                    @if($lockInfo['instructions'])
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">{{ $lockInfo['instructions'] }}</p>
                    @endif
                </div>
                @else
                <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-700 dark:text-amber-300">
                    Please contact support to get the payment account details and subscribe.
                </div>
                @endif

                {{-- Payment proof submission --}}
                @if($pendingProof)
                <div class="rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-4 text-sm text-blue-700 dark:text-blue-300 flex items-start gap-2">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Your payment proof is <strong>under review</strong>. We'll unlock your account as soon as our team verifies it.</span>
                </div>
                @elseif($submitAction && !empty($rejectedProof))
                <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4 text-sm text-red-700 dark:text-red-300 flex items-start gap-2">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Your last payment proof was <strong>rejected</strong>{{ trim((string) $rejectedProof->reject_reason) !== '' ? ' — ' . $rejectedProof->reject_reason : '' }}. Please submit a new payment proof below.</span>
                </div>
                @endif
                @if(!$pendingProof && $submitAction && empty($lockPlans))
                <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-700 dark:text-amber-300">
                    No subscription packages are available for your account yet. Please contact support to subscribe.
                </div>
                @elseif(!$pendingProof && $submitAction)
                <form method="POST" action="{{ $submitAction }}" enctype="multipart/form-data"
                      x-data="{
                        plans: {{ \Illuminate\Support\Js::from($lockPlans) }},
                        cycles: {{ \Illuminate\Support\Js::from($lockCycles) }},
                        planId: '{{ old('pricing_plan_id') }}',
                        cycle: '{{ old('billing_cycle', $lockProductType === 'di' ? 'monthly' : 'annual') }}',
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
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Choose your package &amp; submit your receipt</p>

                    <div class="grid grid-cols-1 {{ count($lockCycles) > 1 ? 'sm:grid-cols-2' : '' }} gap-2">
                        <select name="pricing_plan_id" x-model="planId" required
                                class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                            <option value="">Select your package</option>
                            <template x-for="p in plans" :key="p.id">
                                <option :value="p.id" x-text="p.name"></option>
                            </template>
                        </select>
                        @if(count($lockCycles) > 1)
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
                        <span x-text="cycleLabel"></span> package total:
                        <span class="font-bold">PKR <span x-text="price !== null ? Number(price).toLocaleString() : ''"></span></span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" placeholder="Amount paid (PKR)"
                               class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                        <select name="payment_method"
                                class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                            <option value="">Payment method</option>
                            <option value="bank" @selected(old('payment_method') === 'bank')>Bank Transfer</option>
                            <option value="jazzcash" @selected(old('payment_method') === 'jazzcash')>JazzCash</option>
                            <option value="easypaisa" @selected(old('payment_method') === 'easypaisa')>EasyPaisa</option>
                            <option value="other" @selected(old('payment_method') === 'other')>Other</option>
                        </select>
                    </div>
                    <input type="text" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="Transaction ID / bank reference (optional)"
                           class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                    <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required
                           class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-amber-500 file:text-white hover:file:bg-amber-400">
                    @error('proof')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                    @error('amount')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                    <p class="text-[11px] text-gray-400">Accepted: JPG, PNG, PDF — max 5 MB.</p>
                    @if($everRejected)
                    <p class="text-[11px] text-amber-600 dark:text-amber-400">Your new proof will be activated after our team verifies it.</p>
                    @else
                    <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-3 py-2 text-xs text-emerald-700 dark:text-emerald-300">
                        Upload your proof and get <strong>instant access for 10 days</strong> while our team verifies your payment.
                    </div>
                    @endif
                    <button type="submit"
                            class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-white text-sm font-semibold transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        Upload Payment Proof
                    </button>
                </form>
                @endif

                <div class="flex flex-col sm:flex-row gap-3 pt-1">
                    @if($lockInfo['wa_link'])
                    <a href="{{ $lockInfo['wa_link'] }}" target="_blank" rel="noopener"
                       class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-[#25D366] hover:bg-[#1ebe5b] text-white text-sm font-semibold transition">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.515 5.26l-.999 3.648 3.973-1.715z"/></svg>
                        Send Payment Proof on WhatsApp
                    </a>
                    @else
                    <span class="flex-1 text-center text-xs text-gray-400 px-4 py-3">Support WhatsApp number not configured yet.</span>
                    @endif
                    <button type="button" @click="close()"
                            class="px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                        Continue (view only)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endif
