@php
    // In-app trial-ending reminder. Mirrors the lock-modal company resolution.
    // Shows ONLY for non-internal companies still on an active trial that are
    // within 1 day OR 5 invoices of their limit. trialStatus() returns null
    // once the company is locked (the lock modal takes over from there).
    $reminder = null;
    $rCompanyId = app()->bound('n') ? app('n') : null;
    if (!$rCompanyId) {
        foreach (['web', 'pos', 'fbrpos'] as $guard) {
            if (auth($guard)->check()) { $rCompanyId = auth($guard)->user()->company_id ?? null; break; }
        }
    }

    if ($rCompanyId) {
        $rCompany = \App\Models\Company::find($rCompanyId);
        if ($rCompany && !$rCompany->is_internal_account) {
            // Active TEMPORARY override → show how long the granted access lasts
            // (lifetime overrides show nothing; trialStatus() is null under any override).
            $ov = \App\Services\SubscriptionAccessService::overrideReminder($rCompany);
            if ($ov) {
                $parts = [];
                $parts[] = $ov['days_left'] <= 0
                    ? __('pos.trb_ends_today')
                    : ($ov['days_left'] == 1
                        ? __('pos.trb_ends_1day', ['date' => $ov['until']])
                        : __('pos.trb_ends_days', ['date' => $ov['until'], 'days' => $ov['days_left']]));
                if ($ov['invoices_left'] !== null) {
                    $parts[] = $ov['invoices_left'] <= 0
                        ? __('pos.trb_no_invoices')
                        : ($ov['invoices_left'] == 1 ? __('pos.trb_inv_1') : __('pos.trb_inv_n', ['count' => $ov['invoices_left']]));
                }
                // Auto-granted bridge access (payment proof upload) reads differently
                // from a manual admin grant — the customer is waiting on verification.
                $ovSub = \App\Models\Subscription::where('company_id', $rCompany->id)->where('active', true)->orderByDesc('id')->first();
                $isAutoGrant = $ovSub && $ovSub->override_by === null && str_contains((string) $ovSub->override_reason, 'payment proof #');
                $reminder = [
                    'text' => __($isAutoGrant ? 'pos.trb_grant_auto' : 'pos.trb_grant_admin', ['parts' => implode(' · ', $parts)]),
                    'key' => 'override_reminder_' . now()->toDateString() . '_' . $ov['days_left'] . '_' . ($ov['invoices_left'] ?? 'x'),
                ];
            }

            // Paid subscription ending within 2 days (owner, 1 Aug 2026)
            if (!$reminder) {
                $pe = \App\Services\SubscriptionAccessService::paidEndingReminder($rCompany);
                if ($pe) {
                    $reminder = [
                        'text' => $pe['days_left'] <= 0
                            ? __('pos.trb_sub_today')
                            : ($pe['days_left'] == 1
                                ? __('pos.trb_sub_1day', ['date' => $pe['until']])
                                : __('pos.trb_sub_days', ['date' => $pe['until'], 'days' => $pe['days_left']])),
                        'key' => 'sub_ending_reminder_' . now()->toDateString() . '_' . $pe['days_left'],
                    ];
                }
            }

            $status = $reminder ? null : \App\Services\SubscriptionAccessService::trialStatus($rCompany);
            if ($status && $status['on_trial']) {
                $daysLeft = $status['days_left'];
                $invLeft = $status['invoices_left'];
                // 2-day early warning (owner, 1 Aug 2026)
                $dayUrgent = $daysLeft !== null && $daysLeft <= 2;
                $invUrgent = $invLeft !== null && $invLeft <= 5;
                if ($dayUrgent || $invUrgent) {
                    $parts = [];
                    if ($dayUrgent) {
                        $parts[] = $daysLeft <= 0
                            ? __('pos.trb_expires_today')
                            : ($daysLeft == 1 ? __('pos.trb_days_1') : __('pos.trb_days_n', ['days' => $daysLeft]));
                    }
                    if ($invUrgent) {
                        $parts[] = $invLeft <= 0
                            ? __('pos.trb_no_free_inv')
                            : ($invLeft == 1 ? __('pos.trb_inv_1') : __('pos.trb_inv_n', ['count' => $invLeft]));
                    }
                    $reminder = [
                        'text' => __('pos.trb_trial_ending', ['parts' => implode(' · ', $parts)]),
                        'key' => 'trial_reminder_' . now()->toDateString() . '_' . ($daysLeft ?? 'x') . '_' . ($invLeft ?? 'x'),
                    ];
                }
            }
        }
    }
@endphp

@if($reminder)
<div x-data="{
        show: false,
        init() {
            try { if (sessionStorage.getItem('{{ $reminder['key'] }}') !== '1') this.show = true; }
            catch (e) { this.show = true; }
        },
        dismiss() {
            this.show = false;
            try { sessionStorage.setItem('{{ $reminder['key'] }}', '1'); } catch (e) {}
        }
     }"
     x-show="show" x-cloak class="relative z-40">
    <div class="flex items-center gap-3 px-4 py-2.5 bg-amber-50 dark:bg-amber-900/30 border-b border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-200 text-sm">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
        <span class="flex-1 font-medium" @if(app()->getLocale() === 'ur') dir="rtl" @endif>{{ $reminder['text'] }} <span class="hidden sm:inline font-normal opacity-80">{{ __('pos.trb_subscribe_now') }}</span></span>
        <button @click="dismiss()" class="flex-shrink-0 p-1 rounded hover:bg-amber-100 dark:hover:bg-amber-800/50" aria-label="{{ __('pos.dismiss') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
</div>
@endif
