@php
    $domainMoveStarts = \Carbon\CarbonImmutable::create(2026, 9, 5, 0, 0, 0, 'Asia/Karachi');
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
    {{-- These essential colors deliberately live beside the component instead
         of relying on arbitrary Tailwind classes. Live serves precompiled CSS,
         so a newly introduced bg-[#...] class can otherwise be absent until a
         separate asset build and leave this safety notice transparent. --}}
    <style>
        #tn-domain-move-notice {
            background: rgba(2, 6, 23, 0.78);
        }
        #tn-domain-move-notice .tn-domain-move-card {
            background: #fffdf7;
            border-color: rgba(7, 91, 93, 0.26);
            border-radius: 1.75rem;
            box-shadow: 0 24px 70px rgba(9, 53, 58, 0.34);
        }
        #tn-domain-move-notice .tn-domain-move-header {
            background: #075b5d;
            color: #ffffff;
        }
        #tn-domain-move-notice .tn-domain-move-eyebrow {
            color: #f5cf7c;
        }
        #tn-domain-move-notice #tn-domain-move-summary {
            color: #d7f2ef;
        }
        #tn-domain-move-notice #tn-domain-move-close {
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 255, 255, 0.24);
            color: #ffffff;
        }
        #tn-domain-move-notice #tn-domain-move-close:hover {
            background: rgba(255, 255, 255, 0.22);
        }
        #tn-domain-move-notice .tn-domain-move-body {
            color: #334155;
        }
        #tn-domain-move-notice .tn-domain-move-official {
            background: #fff7df;
            border-color: #d8bd75;
        }
        #tn-domain-move-notice .tn-domain-move-official-label {
            color: #896518;
        }
        #tn-domain-move-notice .tn-domain-move-domain-link,
        #tn-domain-move-notice #tn-domain-move-dismiss {
            background: #075b5d;
            color: #ffffff;
        }
        #tn-domain-move-notice .tn-domain-move-domain-link {
            border-color: #064b4d;
        }
        #tn-domain-move-notice .tn-domain-move-domain-link:hover,
        #tn-domain-move-notice #tn-domain-move-dismiss:hover {
            background: #064b4d;
        }
        #tn-domain-move-notice .tn-domain-move-section-title {
            color: #0f172a;
        }
        #tn-domain-move-notice .tn-domain-move-step {
            background: #e8bf63;
            color: #473208;
        }
        #tn-domain-move-notice .tn-domain-move-inline-link {
            color: #075b5d;
            text-decoration-color: #c79e43;
        }
        #tn-domain-move-notice .tn-domain-move-bullet {
            background: #c79e43;
        }
        #tn-domain-move-notice .tn-domain-move-outcome {
            background: #edf5f1;
            border-left-color: #c79e43;
            color: #214a4d;
        }
        #tn-domain-move-notice .tn-domain-move-footer {
            background: #fffaf0;
        }
        #tn-domain-move-notice a:focus,
        #tn-domain-move-notice button:focus {
            outline: 3px solid #e8bf63;
            outline-offset: 2px;
        }
        html.dark #tn-domain-move-notice .tn-domain-move-card,
        html.dark #tn-domain-move-notice .tn-domain-move-footer {
            background: #102b2e;
        }
        html.dark #tn-domain-move-notice .tn-domain-move-card {
            border-color: rgba(94, 234, 212, 0.25);
        }
        html.dark #tn-domain-move-notice .tn-domain-move-body {
            color: #f1f5f9;
        }
        html.dark #tn-domain-move-notice .tn-domain-move-official,
        html.dark #tn-domain-move-notice .tn-domain-move-outcome {
            background: #17383a;
        }
        html.dark #tn-domain-move-notice .tn-domain-move-official {
            border-color: rgba(216, 189, 117, 0.45);
        }
        html.dark #tn-domain-move-notice .tn-domain-move-official-label,
        html.dark #tn-domain-move-notice .tn-domain-move-inline-link {
            color: #f5cf7c;
        }
        html.dark #tn-domain-move-notice .tn-domain-move-section-title {
            color: #ffffff;
        }
        html.dark #tn-domain-move-notice .tn-domain-move-outcome {
            color: #d7f2ef;
        }
    </style>

    <div id="tn-domain-move-notice"
         role="dialog"
         aria-modal="true"
         aria-labelledby="tn-domain-move-title"
         aria-describedby="tn-domain-move-summary"
         style="display:none; z-index:10000;"
         data-domain-agent-login-notice
         class="fixed inset-0 z-[10000] items-center justify-center bg-slate-950/75 px-3 py-4 sm:px-5 sm:py-8 backdrop-blur-sm">
        <div class="tn-domain-move-card relative flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-[1.75rem] border border-teal-800/25 bg-[#fffdf7] shadow-[0_24px_70px_rgba(9,53,58,0.34)] dark:border-teal-400/25 dark:bg-[#102b2e]">
            <div class="tn-domain-move-header relative border-b border-teal-950/10 bg-[#075b5d] px-5 py-5 text-white sm:px-7 sm:py-6">
                <button type="button"
                        id="tn-domain-move-close"
                        aria-label="{{ __('pos.domain_agent_close_aria') }}"
                        class="absolute right-3 top-3 flex h-10 w-10 items-center justify-center rounded-full border border-white/20 bg-white/10 text-2xl font-light text-white transition hover:bg-white/20 focus:outline-none focus:ring-4 focus:ring-[#e8bf63] sm:right-5 sm:top-5">
                    &times;
                </button>
                <p class="tn-domain-move-eyebrow pr-12 text-[11px] font-black uppercase tracking-[0.18em] text-[#f5cf7c]">{{ __('pos.domain_agent_eyebrow') }}</p>
                <h2 id="tn-domain-move-title" class="mt-1.5 max-w-lg pr-8 text-xl font-black leading-tight sm:text-2xl">{{ __('pos.domain_agent_title') }}</h2>
                <p id="tn-domain-move-summary" class="mt-2 max-w-xl text-sm leading-5 text-teal-50/85">{{ __('pos.domain_agent_subtitle') }}</p>
            </div>
            <div class="tn-domain-move-body overflow-y-auto px-5 py-5 text-slate-700 dark:text-slate-100 sm:px-7 sm:py-6">
                <div class="tn-domain-move-official rounded-2xl border border-[#d8bd75] bg-[#fff7df] px-4 py-3.5 dark:border-[#d8bd75]/45 dark:bg-[#17383a]">
                    <p class="tn-domain-move-official-label text-[11px] font-black uppercase tracking-[0.14em] text-[#896518] dark:text-[#f5cf7c]">{{ __('pos.domain_agent_official_label') }}</p>
                    <p class="mt-1 text-sm leading-6">{{ __('pos.domain_agent_domain_intro') }}</p>
                    <a href="https://taxnest.pk"
                       class="tn-domain-move-domain-link mt-2.5 block rounded-xl border border-[#064b4d] bg-[#075b5d] px-4 py-2.5 text-center text-base font-black tracking-wide text-white transition hover:bg-[#064b4d] focus:outline-none focus:ring-4 focus:ring-[#e8bf63]">
                        taxnest.pk
                    </a>
                </div>

                <div class="mt-5">
                    <p class="tn-domain-move-section-title text-sm font-black text-slate-900 dark:text-white">{{ __('pos.domain_agent_update_title') }}</p>
                    <ol class="mt-3 space-y-3.5">
                        <li class="flex gap-3 text-sm leading-5">
                            <span class="tn-domain-move-step flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#e8bf63] font-black text-[#473208]">1</span>
                            <span><strong>{{ __('pos.domain_agent_step_one') }}</strong></span>
                        </li>
                        <li class="flex gap-3 text-sm leading-5">
                            <span class="tn-domain-move-step flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#e8bf63] font-black text-[#473208]">2</span>
                            <span><a href="https://taxnest.pk/download" class="tn-domain-move-inline-link font-black text-[#075b5d] underline decoration-[#c79e43] underline-offset-2 dark:text-[#f5cf7c]">taxnest.pk/download</a> {{ __('pos.domain_agent_step_two') }}</span>
                        </li>
                        <li class="flex gap-3 text-sm leading-5">
                            <span class="tn-domain-move-step flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#e8bf63] font-black text-[#473208]">3</span>
                            <span>{{ __('pos.domain_agent_step_three') }}</span>
                        </li>
                    </ol>
                </div>

                <div class="mt-5 border-t border-slate-200 pt-4 dark:border-white/10">
                    <p class="tn-domain-move-section-title text-sm font-black text-slate-900 dark:text-white">{{ __('pos.domain_agent_sale_title') }}</p>
                    <ul class="mt-3 space-y-2 text-sm leading-5">
                        <li class="flex gap-2.5"><span class="tn-domain-move-bullet mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[#c79e43]"></span><span>{{ __('pos.domain_agent_sale_popup') }}</span></li>
                        <li class="flex gap-2.5"><span class="tn-domain-move-bullet mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[#c79e43]"></span><span>{{ __('pos.domain_agent_sale_payment') }}</span></li>
                        <li class="flex gap-2.5"><span class="tn-domain-move-bullet mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[#c79e43]"></span><span>{{ __('pos.domain_agent_sale_customer') }}</span></li>
                    </ul>
                </div>

                <p class="tn-domain-move-outcome mt-5 rounded-xl border-l-4 border-[#c79e43] bg-[#edf5f1] px-4 py-3 text-xs font-bold leading-5 text-[#214a4d] dark:bg-[#16393b] dark:text-teal-50">
                    {{ __('pos.domain_agent_outcome') }}
                </p>
            </div>
            <div class="tn-domain-move-footer border-t border-slate-200 bg-[#fffaf0] px-5 py-4 dark:border-white/10 dark:bg-[#102b2e] sm:px-7">
                <button type="button"
                        id="tn-domain-move-dismiss"
                        class="w-full rounded-xl bg-[#075b5d] px-4 py-3 text-sm font-black text-white transition hover:bg-[#064b4d] focus:outline-none focus:ring-4 focus:ring-[#e8bf63]">
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
            const previouslyFocused = document.activeElement;
            if (!dismissedThisLogin) {
                notice.style.display = 'flex';
                requestAnimationFrame(() => close.focus());
            }

            const hideNotice = () => {
                try { localStorage.setItem(storageKey, '1'); } catch (_) {}
                notice.style.display = 'none';
                if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                    previouslyFocused.focus();
                }
            };

            dismiss.addEventListener('click', hideNotice);
            close.addEventListener('click', hideNotice);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && notice.style.display === 'flex') hideNotice();
            });
        })();
    </script>
@endif