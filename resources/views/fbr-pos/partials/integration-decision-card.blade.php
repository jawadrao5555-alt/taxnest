{{-- Optional FBR integration (Sep 2026): one-time "FBR se connect karna hai?" card.
     Rendered by layouts/fbr-pos-app only when $fbrDecisionCard is true (owner,
     approved company, not read-only impersonation, undecided shop, dashboard or
     sale screen). Choice is POST-recorded; X only snoozes for this session so a
     dismiss never loops every page load. Relative URL (route(...,false)) —
     absolute https breaks http dev fetches. --}}
@php
    $fdcConfigured = false;
    try { $fdcConfigured = $fbrCompany->fbrPosIntegrationConfigured(); } catch (\Throwable $e) {}
    $fdcHadFailures = false;
    try {
        $fdcHadFailures = (bool) $fbrCompany->fbr_reporting_enabled
            && \App\Models\FbrPosTransaction::where('company_id', $fbrCompany->id)
                ->whereIn('fbr_status', ['failed', 'config_error'])->exists();
    } catch (\Throwable $e) {}
@endphp
<div x-data="{ fdOpen: true, fdBusy: false,
        fdChoose(choice) {
            if (this.fdBusy) return;
            this.fdBusy = true;
            fetch('{{ route('fbrpos.integration.decision', [], false) }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ choice: choice })
            }).then(r => r.json().then(d => ({ ok: r.ok, d })))
              .then(({ ok, d }) => {
                  if (!ok || !d.success) { this.fdBusy = false; alert((d && d.message) || @js(__('pos.network_error'))); return; }
                  this.fdOpen = false;
                  if (choice === 'later') { this.fdBusy = false; return; }
                  if (choice === 'connect' && d.redirect) { window.location.href = d.redirect; return; }
                  // without_fbr: the failed pill / counters / cached sale screen must
                  // re-read server truth — one reload does it.
                  window.location.reload();
              })
              .catch(() => { this.fdBusy = false; alert(@js(__('pos.network_error'))); });
        } }"
     x-show="fdOpen" x-cloak data-fbr-decision-card="1"
     class="fixed inset-0 flex items-center justify-center p-4"
     style="z-index: 131; background: rgba(5, 15, 40, 0.55); backdrop-filter: blur(4px);">
    <div class="w-full max-w-md bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden"
         role="dialog" aria-modal="true" aria-labelledby="fbrDecisionTitle"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-90"
         x-transition:enter-end="opacity-100 scale-100">
        <div class="relative px-6 py-5" style="background: linear-gradient(135deg, hsl(var(--accent-h), var(--accent-s), 42%), hsl(var(--accent-h), var(--accent-s), 28%));">
            <button type="button" @click="fdChoose('later')" :disabled="fdBusy"
                    class="absolute top-3 right-3 w-8 h-8 rounded-full bg-white/15 hover:bg-white/30 text-white flex items-center justify-center transition"
                    title="{{ __('pos.fbr_decision_later') }}" aria-label="{{ __('pos.fbr_decision_later') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="text-3xl mb-1">🧾</div>
            <h2 id="fbrDecisionTitle" class="text-xl font-extrabold text-white">{{ __('pos.fbr_decision_title') }}</h2>
            <p class="text-[12px] text-white/85 mt-1">{{ __('pos.fbr_decision_subtitle') }}</p>
        </div>
        <div class="px-6 py-5 space-y-3">
            <p class="text-sm text-gray-700 dark:text-gray-200">{{ __('pos.fbr_decision_body') }}</p>
            @if($fdcHadFailures)
            <p class="text-xs rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 p-2.5 text-slate-700 dark:text-slate-200">{{ __('pos.fbr_decision_failures_note') }}</p>
            @endif
            <button type="button" @click="fdChoose('connect')" :disabled="fdBusy"
                    class="w-full text-left rounded-xl border-2 border-blue-500 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/40 px-4 py-3 transition disabled:opacity-50">
                <span class="block text-sm font-extrabold text-blue-800 dark:text-blue-200">{{ __('pos.fbr_decision_yes') }}</span>
                <span class="block text-xs text-blue-700/80 dark:text-blue-300/80 mt-0.5">{{ $fdcConfigured ? __('pos.fbr_decision_yes_hint_configured') : __('pos.fbr_decision_yes_hint') }}</span>
            </button>
            <button type="button" @click="fdChoose('without_fbr')" :disabled="fdBusy"
                    class="w-full text-left rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 px-4 py-3 transition disabled:opacity-50">
                <span class="block text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.fbr_decision_no') }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.fbr_decision_no_hint') }}</span>
            </button>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 text-center">{{ __('pos.fbr_decision_footer') }}</p>
        </div>
    </div>
</div>
