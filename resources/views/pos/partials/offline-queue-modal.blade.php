{{--
    OFFLINE QUEUE INSPECTOR — shared by the PRA and FBR universal sale screens.

    Why this exists (Phase 1 of the offline hardening, Aug 2026):
    During an outage the screen used to show a SINGLE number on the Auto-Sync
    pill, and that number added two unrelated things together — bills sitting in
    this device's IndexedDB queue, and bills the server had already REJECTED.
    There was no way to open either list. A shop that bills for three hours into
    an invisible queue cannot reconcile its till, and the first question it asks
    support is never "why is there no internet" — it is "where did my bills go".

    This modal answers that question: every queued bill with its time, customer,
    amount and state, a clock on the outage itself, a manual sync button, and a
    printable copy so the counter can tally against cash on paper.

    Contract — the host screen must provide this Alpine state:
      showOfflineQueue, offlineQueueList[], offlineQueueCount, offlineSyncing,
      offlineNeedsLogin, failedBills[], syncStatus,
      openOfflineQueue(), offlineSinceText(), offlineQueueSum(),
      printOfflineQueueList(), syncOfflineBills(manual)
    Both host screens define these identically; keep them in step.
--}}
{{-- The id is not styling — it is how a render probe proves this modal actually
     reached the page on both sale screens instead of silently going missing. --}}
<div id="tn-offline-queue-modal" x-show="showOfflineQueue" x-cloak x-transition.opacity
     class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4"
     @click.self="showOfflineQueue = false">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[88vh] flex flex-col overflow-hidden">

        {{-- Header: title + the outage clock. The clock is the part shopkeepers
             read first — "kitni der se" matters more than "kitne bill". --}}
        <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ __('pos.offq_title') }}</span>
                </h3>
                <p class="mt-1 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('pos.offq_sub') }}</p>
                <p x-show="offlineSinceText()" class="mt-1.5 text-[11px] font-bold text-red-600 dark:text-red-400">
                    {{ __('pos.offq_offline_for') }} <span x-text="offlineSinceText()"></span>
                </p>
            </div>
            <button type="button" @click="showOfflineQueue = false"
                    class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition flex-shrink-0">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Session-expired notice: the drain stopped on a 401/419, so the bills
             are safe but nothing will move until the cashier logs in again. --}}
        <div x-show="offlineNeedsLogin" x-cloak
             class="mx-5 mt-3 px-3 py-2 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-[11px] font-semibold text-red-700 dark:text-red-300">
            {{ __('pos.session_expired_offline_safe') }}
        </div>

        {{-- Walk-ins saved while the line was dead. They carry no money, so they
             stay OUT of the bill count and out of the totals — but they are real
             work the cashier did and must not be invisible. --}}
        <div x-show="offlineCustomerCount > 0" x-cloak
             class="mx-5 mt-3 px-3 py-2 rounded-lg bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 text-[11px] font-semibold text-sky-800 dark:text-sky-300">
            <span x-text="offlineCustomerCount"></span> {{ __('pos.offq_customers_pending') }}
        </div>

        {{-- Grow warning: fires before the queue is scary, not after. --}}
        <div x-show="offlineQueueList.length >= 25" x-cloak
             class="mx-5 mt-3 px-3 py-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-[11px] font-semibold text-amber-800 dark:text-amber-300">
            {{ __('pos.offq_many_warning') }}
        </div>

        {{-- Body --}}
        <div class="flex-1 overflow-y-auto px-5 py-3">
            <div x-show="offlineQueueList.length === 0" x-cloak class="py-10 text-center">
                <svg class="w-10 h-10 mx-auto text-emerald-500 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ __('pos.offq_none') }}</p>
            </div>

            <table x-show="offlineQueueList.length > 0" x-cloak class="w-full text-[11px]">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="text-start font-bold py-1.5">{{ __('pos.offq_col_time') }}</th>
                        <th class="text-start font-bold py-1.5">{{ __('pos.offq_col_customer') }}</th>
                        <th class="text-end font-bold py-1.5">{{ __('pos.offq_col_amount') }}</th>
                        <th class="text-end font-bold py-1.5">{{ __('pos.offq_col_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="b in offlineQueueList" :key="b.uuid">
                        <tr class="border-b border-gray-100 dark:border-gray-700/60">
                            <td class="py-1.5 font-mono text-gray-700 dark:text-gray-300"
                                x-text="b.queued_at ? new Date(b.queued_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'"></td>
                            <td class="py-1.5 text-gray-700 dark:text-gray-300 truncate max-w-[140px]"
                                x-text="b.customer || '{{ __('pos.offq_walkin') }}'"></td>
                            <td class="py-1.5 text-end font-bold text-gray-900 dark:text-gray-100"
                                x-text="Number(b.total || 0).toLocaleString()"></td>
                            {{-- Status labels are rendered by Blade, NOT via window.TXT.
                                 PosI18n bakes TXT keys by scanning each sale screen's own
                                 source and does not follow includes, so a TXT key used only
                                 in this partial would arrive undefined. Plain Blade also
                                 dodges the quoting trap: an apostrophe in a translation
                                 would break a single-quoted string inside x-text. --}}
                            <td class="py-1.5 text-end">
                                <span x-show="(b.tries || 0) >= 50"
                                      class="px-1.5 py-0.5 rounded-full text-[9px] font-black bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300"
                                >{{ __('pos.offq_status_stuck') }}</span>
                                <span x-show="(b.tries || 0) > 0 && (b.tries || 0) < 50"
                                      class="px-1.5 py-0.5 rounded-full text-[9px] font-black bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300"
                                ><span x-text="b.tries"></span> {{ __('pos.offq_tries_word') }}</span>
                                <span x-show="(b.tries || 0) === 0"
                                      class="px-1.5 py-0.5 rounded-full text-[9px] font-black bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300"
                                >{{ __('pos.offq_status_waiting') }}</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Footer: the money total is the number the shop reconciles against. --}}
        <div class="px-5 py-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between gap-3 flex-wrap">
            <div class="text-[11px] font-bold text-gray-700 dark:text-gray-300">
                {{ __('pos.offq_total') }}:
                <span class="text-sm text-gray-900 dark:text-gray-100" x-text="Number(offlineQueueSum()).toLocaleString()"></span>
                <span class="ms-2 font-semibold text-gray-500 dark:text-gray-400">
                    (<span x-text="offlineQueueList.length"></span> {{ __('pos.offq_pending_word') }}<template x-if="failedBills.length > 0"><span>, <span x-text="failedBills.length"></span> {{ __('pos.offq_failed_word') }}</span></template>)
                </span>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="printOfflineQueueList()" x-show="offlineQueueList.length > 0"
                        class="px-3 py-1.5 rounded-lg text-[11px] font-bold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    {{ __('pos.offq_print_list') }}
                </button>
                <button type="button" @click="syncOfflineBills(true)" :disabled="offlineSyncing"
                        class="px-3 py-1.5 rounded-lg text-[11px] font-bold bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white transition">
                    {{ __('pos.offq_sync_now') }}
                </button>
            </div>
        </div>
    </div>
</div>
