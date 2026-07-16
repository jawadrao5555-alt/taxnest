@php
    // In-app payment-decision notice (approved / rejected) — included in all
    // three panel layouts (DI, PRA POS, FBR POS) so the company admin sees the
    // outcome no matter which panel they use. Reads the company-scoped
    // notification rows written by AdminPaymentProofController; self-expires
    // after 7 days; dismiss is per-notification via sessionStorage (mirrors
    // trial-reminder-banner).
    $payNotif = null;
    $pnUser = null;
    $pnIsAdmin = false;
    foreach (['pos', 'fbrpos', 'web'] as $pnGuard) {
        if (auth($pnGuard)->check()) {
            $pnUser = auth($pnGuard)->user();
            $pnIsAdmin = $pnGuard === 'pos'
                ? (method_exists($pnUser, 'isPosAdmin') && $pnUser->isPosAdmin())
                : (($pnUser->role ?? null) === 'company_admin');
            break;
        }
    }

    if ($pnUser && $pnIsAdmin && ($pnUser->company_id ?? null)) {
        try {
            $payNotif = \App\Models\Notification::where('company_id', $pnUser->company_id)
                ->whereIn('type', ['payment_verified', 'payment_rejected'])
                ->where('created_at', '>=', now()->subDays(7))
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable $e) {
            $payNotif = null; // notifications table missing on an outdated DB — never break the page
        }
    }
@endphp

@if($payNotif)
@php $pnApproved = $payNotif->type === 'payment_verified'; @endphp
<div x-data="{
        show: false,
        init() {
            try { if (sessionStorage.getItem('payment_notif_{{ $payNotif->id }}') !== '1') this.show = true; }
            catch (e) { this.show = true; }
        },
        dismiss() {
            this.show = false;
            try { sessionStorage.setItem('payment_notif_{{ $payNotif->id }}', '1'); } catch (e) {}
        }
     }"
     x-show="show" x-cloak class="relative z-40">
    @if($pnApproved)
    <div class="flex items-center gap-3 px-4 py-2.5 bg-emerald-50 dark:bg-emerald-900/30 border-b border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 text-sm">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span class="flex-1 font-medium">{{ $payNotif->title }} <span class="hidden sm:inline font-normal opacity-80">{{ $payNotif->message }}</span></span>
        <button @click="dismiss()" class="flex-shrink-0 p-1 rounded hover:bg-emerald-100 dark:hover:bg-emerald-800/50" aria-label="Dismiss">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    @else
    <div class="flex items-center gap-3 px-4 py-2.5 bg-red-50 dark:bg-red-900/30 border-b border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span class="flex-1 font-medium">{{ $payNotif->message }}</span>
        <button @click="dismiss()" class="flex-shrink-0 p-1 rounded hover:bg-red-100 dark:hover:bg-red-800/50" aria-label="Dismiss">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    @endif
</div>
@endif
