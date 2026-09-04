@php
    $domainMoveStarts = \Carbon\CarbonImmutable::create(2026, 9, 4, 0, 0, 0, 'Asia/Karachi');
    $domainMoveEnds = $domainMoveStarts->addDays(7);
    $domainMoveNow = \Carbon\CarbonImmutable::now('Asia/Karachi');
    $showDomainMoveNotice = $domainMoveNow->greaterThanOrEqualTo($domainMoveStarts)
        && $domainMoveNow->lessThan($domainMoveEnds);

    // One dismissal per authenticated login session. Logout invalidates the
    // Laravel session, so the next login receives a fresh browser-storage key.
    $domainAgentNoticeKey = session()->get('taxnest_domain_agent_notice_key');
    if ($showDomainMoveNotice && !$domainAgentNoticeKey) {
        $domainAgentNoticeKey = (string) \Illuminate\Support\Str::uuid();
        session()->put('taxnest_domain_agent_notice_key', $domainAgentNoticeKey);
    }
@endphp

@if($showDomainMoveNotice)
    <div id="tn-domain-move-notice"
         role="dialog"
         aria-modal="true"
         aria-labelledby="tn-domain-move-title"
         style="display:none"
         data-domain-agent-login-notice
         class="fixed inset-0 z-[10000] items-center justify-center bg-slate-950/70 px-3 py-4 sm:px-4 sm:py-8 backdrop-blur-sm">
        <div class="relative flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl border border-emerald-200 bg-white shadow-2xl dark:border-emerald-800 dark:bg-gray-900">
            <div class="relative bg-emerald-800 px-6 py-5 text-white">
                <button type="button"
                        id="tn-domain-move-close"
                        aria-label="{{ __('pos.domain_agent_close_aria') }}"
                        class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-2xl font-light text-white hover:bg-white/25">
                    &times;
                </button>
                <p class="text-xs font-black uppercase tracking-[0.2em] text-amber-300">{{ __('pos.domain_agent_eyebrow') }}</p>
                <h2 id="tn-domain-move-title" class="mt-1 pr-9 text-xl font-black sm:text-2xl">{{ __('pos.domain_agent_title') }}</h2>
                <p class="mt-1 text-xs leading-5 text-emerald-100">{{ __('pos.domain_agent_subtitle') }}</p>
            </div>
            <div class="overflow-y-auto px-5 py-5 text-gray-700 dark:text-gray-200 sm:px-6">
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-950/40">
                    <p class="text-[11px] font-black uppercase tracking-wider text-emerald-700 dark:text-emerald-300">{{ __('pos.domain_agent_official_label') }}</p>
                    <p class="mt-1 text-sm leading-6">{{ __('pos.domain_agent_domain_intro') }}</p>
                    <a href="https://taxnest.pk"
                       class="mt-2 block rounded-xl bg-emerald-800 px-4 py-2.5 text-center text-base font-black text-white hover:bg-emerald-900">
                        taxnest.pk
                    </a>
                </div>

                <div class="mt-4">
                    <p class="text-sm font-black text-gray-900 dark:text-white">{{ __('pos.domain_agent_update_title') }}</p>
                    <ol class="mt-3 space-y-3">
                        <li class="flex gap-3 text-sm leading-5">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-400 font-black text-amber-950">1</span>
                            <span><strong>{{ __('pos.domain_agent_step_one') }}</strong></span>
                        </li>
                        <li class="flex gap-3 text-sm leading-5">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-400 font-black text-amber-950">2</span>
                            <span><a href="https://taxnest.pk/download" class="font-black text-emerald-700 underline dark:text-emerald-300">taxnest.pk/download</a> {{ __('pos.domain_agent_step_two') }}</span>
                        </li>
                        <li class="flex gap-3 text-sm leading-5">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-400 font-black text-amber-950">3</span>
                            <span>{{ __('pos.domain_agent_step_three') }}</span>
                        </li>
                    </ol>
                </div>

                <p class="mt-4 rounded-xl bg-slate-100 px-4 py-3 text-xs font-bold leading-5 text-slate-700 dark:bg-gray-800 dark:text-gray-200">
                    {{ __('pos.domain_agent_outcome') }}
                </p>
            </div>
            <div class="border-t border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900 sm:px-6">
                <button type="button"
                        id="tn-domain-move-dismiss"
                        class="w-full rounded-xl bg-emerald-800 px-4 py-3 text-sm font-black text-white hover:bg-emerald-900 focus:outline-none focus:ring-4 focus:ring-emerald-300">
                    {{ __('pos.domain_agent_dismiss') }}
                </button>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const notice = document.getElementById('tn-domain-move-notice');
            const dismiss = document.getElementById('tn-domain-move-dismiss');
            const close = document.getElementById('tn-domain-move-close');
            if (!notice || !dismiss || !close) return;

            const storageKey = 'taxnest-domain-agent-seen-{{ $domainAgentNoticeKey }}';
            let dismissedThisLogin = false;
            try { dismissedThisLogin = localStorage.getItem(storageKey) === '1'; } catch (_) {}
            if (!dismissedThisLogin) notice.style.display = 'flex';

            const hideNotice = () => {
                try { localStorage.setItem(storageKey, '1'); } catch (_) {}
                notice.style.display = 'none';
            };

            dismiss.addEventListener('click', hideNotice);
            close.addEventListener('click', hideNotice);
        })();
    </script>
@endif