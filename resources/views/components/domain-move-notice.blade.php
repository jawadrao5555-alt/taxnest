@php
    $domainMoveStarts = \Carbon\CarbonImmutable::create(2026, 9, 4, 0, 0, 0, 'Asia/Karachi');
    $domainMoveEnds = $domainMoveStarts->addDays(7);
    $domainMoveNow = \Carbon\CarbonImmutable::now('Asia/Karachi');
    $showDomainMoveNotice = $domainMoveNow->greaterThanOrEqualTo($domainMoveStarts)
        && $domainMoveNow->lessThan($domainMoveEnds);
@endphp

@if($showDomainMoveNotice)
    <div id="tn-domain-move-notice"
         role="dialog"
         aria-modal="true"
         aria-labelledby="tn-domain-move-title"
         style="display:none"
         class="fixed inset-0 z-[10000] items-center justify-center bg-slate-950/70 px-4 py-8 backdrop-blur-sm">
        <div class="w-full max-w-lg overflow-hidden rounded-3xl border border-emerald-200 bg-white shadow-2xl dark:border-emerald-800 dark:bg-gray-900">
            <div class="bg-emerald-700 px-6 py-5 text-white">
                <p class="text-xs font-black uppercase tracking-[0.2em] text-emerald-100">Zaroori elaan</p>
                <h2 id="tn-domain-move-title" class="mt-1 text-xl font-black">TaxNest ka naya official domain</h2>
            </div>
            <div class="space-y-4 px-6 py-6 text-gray-700 dark:text-gray-200">
                <p class="text-sm leading-6">
                    TaxNest ab sirf <strong>taxnest.pk</strong> par chalta hai. Purana domain band kar diya gaya hai.
                    Apna naya login address save aur bookmark kar lein.
                </p>
                <a href="https://taxnest.pk"
                   class="block rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-center font-black text-emerald-800 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">
                    https://taxnest.pk
                </a>
                <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                    Yeh yaad-dihani saat din tak roz ek martaba nazar aayegi.
                </p>
                <button type="button"
                        id="tn-domain-move-dismiss"
                        class="w-full rounded-xl bg-emerald-700 px-4 py-3 text-sm font-black text-white hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-300">
                    Samajh gaya
                </button>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const notice = document.getElementById('tn-domain-move-notice');
            const dismiss = document.getElementById('tn-domain-move-dismiss');
            if (!notice || !dismiss) return;

            const parts = new Intl.DateTimeFormat('en', {
                timeZone: 'Asia/Karachi',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            }).formatToParts(new Date());
            const value = (type) => parts.find((part) => part.type === type)?.value || '';
            const today = `${value('year')}-${value('month')}-${value('day')}`;
            const storageKey = 'taxnest-domain-move-seen-v1';

            let lastSeen = null;
            try { lastSeen = localStorage.getItem(storageKey); } catch (_) {}
            if (lastSeen !== today) notice.style.display = 'flex';

            dismiss.addEventListener('click', () => {
                try { localStorage.setItem(storageKey, today); } catch (_) {}
                notice.style.display = 'none';
            });
        })();
    </script>
@endif