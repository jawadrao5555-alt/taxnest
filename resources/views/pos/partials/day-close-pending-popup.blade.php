{{-- "Pichla din band nahi hua" popup (owner request, 23 Aug 2026).

     The shop opens the app next morning and goes straight to New Sale, so the
     red banner on the dashboard / day-close page was being walked past — a
     whole new day of bills piled on top of a day that was never closed.

     Rules the owner asked for:
       · it must POP, not sit quietly in a corner
       · it must NOT close by itself — it waits for the shop
       · it keeps coming back on every load until the day is actually closed

     State comes from GET pos.api.day-close-pending at runtime (never baked into
     the page): the sale screen is served from the offline cache, so a baked
     answer could still shout about a day that was closed hours ago. --}}
<div x-data="dayClosePendingPopup()" x-init="check()" x-show="show" x-cloak
     class="fixed inset-0 z-[80] flex items-center justify-center p-4"
     style="display:none;background:rgba(0,0,0,.6)">
    <div class="bg-white dark:bg-gray-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-5 py-4 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800 flex items-start gap-3">
            <div class="w-9 h-9 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div class="min-w-0">
                <h3 class="text-base font-bold text-red-800 dark:text-red-300" x-text="title"></h3>
                <p class="text-sm text-red-700 dark:text-red-400 mt-0.5" x-text="labels.join(', ')"></p>
            </div>
        </div>
        <div class="px-5 py-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('pos.dcp_note') }}</p>
        </div>
        <div class="px-5 py-3 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-800 flex items-center justify-end gap-2">
            <button type="button" @click="show = false" class="px-3 py-2 text-sm font-semibold text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">{{ __('pos.dcp_later') }}</button>
            <a :href="url" class="px-4 py-2 text-sm font-bold text-white bg-red-600 hover:bg-red-700 rounded-lg">{{ __('pos.dcp_close_now') }}</a>
        </div>
    </div>
</div>

<script>
function dayClosePendingPopup() {
    return {
        show: false,
        labels: [],
        url: '',
        title: '',
        oneTpl: @json(__('pos.dcp_title_one')),
        manyTpl: @json(__('pos.dcp_title_many', ['count' => '__C__'])),
        async check() {
            // Offline (or a cached page opened with no line): nothing to ask.
            if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
            try {
                const r = await fetch(@json(route('pos.api.day-close-pending', [], false)), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!r.ok) return;
                const d = await r.json();
                if (!d || !d.pending || !d.can_close) return;
                this.labels = d.labels || [];
                this.url = d.url || '';
                this.title = (d.count > 1) ? this.manyTpl.replace('__C__', d.count) : this.oneTpl;
                this.show = true;
            } catch (e) {
                // A failed check must never block billing — stay quiet.
            }
        },
    };
}
</script>
