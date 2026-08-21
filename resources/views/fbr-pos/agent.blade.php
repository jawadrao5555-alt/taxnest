<x-fbr-pos-layout>
    {{-- ═══════════════════════════════════════════════════════════════════
         FBR POS — Desktop Agent (Task 1403)

         Port of resources/views/company/agent.blade.php (PRA) with two
         deliberate divergences:

         1. NO "Invoice Submission Mode" card. That card belongs to PRA's
            agent_submits_pra switch. FBR's submission route lives on FBR
            Settings (fbr_connection_mode) and must stay there — pairing an
            agent for PRINTING must never move a shop's invoices.
         2. Stats are PRINT JOBS, not submissions. On FBR the agent's job on
            this page is silent bill + Store-slip printing.

         Reachable in BOTH cloud and fiscal_device mode. Blue chrome (the FBR
         layout remaps blue-* to the shop's accent).
         ═══════════════════════════════════════════════════════════════════ --}}
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            @include('fbr-pos.partials.back-link')

            <div class="mb-6">
                <h2 class="font-bold text-2xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('pos.fbr_agent_title') }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ __('pos.fbr_agent_sub') }}</p>
            </div>

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-700 rounded-lg text-emerald-700 dark:text-emerald-300 font-medium">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 rounded-lg text-red-700 dark:text-red-300 font-medium">{{ session('error') }}</div>
            @endif

            {{-- Plan gate (pricing_plans.offline_enabled). Already-paired shops
                 are grandfathered — a live agent must never be locked out. --}}
            @if(empty($offlineAllowed) && empty($company->agent_api_key))
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow border border-amber-200 dark:border-amber-700 p-8 text-center">
                <div class="text-5xl mb-3">🔒</div>
                <h3 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">{{ __('pos.fbr_agent_plan_locked_title') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 max-w-xl mx-auto">{{ __('pos.fbr_agent_plan_locked_body') }}</p>
                <a href="{{ route('fbrpos.billing') }}" class="inline-flex items-center gap-2 mt-5 px-6 py-3 rounded-lg bg-blue-600 text-white font-bold text-sm hover:bg-blue-700 transition">
                    {{ __('pos.upgrade_plan_btn') }}
                </a>
            </div>
            @else

            {{-- ─────────── Status ─────────── --}}
            <div class="bg-blue-600 rounded-xl shadow-lg p-6 text-white mb-6">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <h3 class="text-xl font-bold">{{ __('pos.fbr_agent_status_title') }}</h3>
                            @if($isOnline)
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-emerald-500 text-white text-xs font-bold">
                                    <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span> {{ __('pos.online') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-gray-600 text-white text-xs font-bold">
                                    <span class="w-2 h-2 rounded-full bg-gray-300"></span> {{ __('pos.offline') }}
                                </span>
                            @endif
                        </div>
                        <p class="text-sm text-white/85">
                            {{ __('pos.last_seen_prefix') }}
                            {{ $company->agent_last_seen ? $company->agent_last_seen->diffForHumans() : __('pos.agent_never_connected') }}
                        </p>
                        @if($company->agent_version)
                            <p class="text-xs text-white/75 mt-1">
                                {{ __('pos.fbr_agent_version_label') }} {{ $company->agent_version }}
                                @if(!empty($agentOutdated))
                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full bg-amber-500/90 text-white text-[10px] font-bold uppercase">
                                        {{ __('pos.fbr_agent_outdated_badge', ['version' => $latestAgentVersion]) }}
                                    </span>
                                @elseif(!empty($latestAgentVersion))
                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-500/80 text-white text-[10px] font-bold uppercase">{{ __('pos.fbr_agent_uptodate_badge') }}</span>
                                @endif
                            </p>
                            @if(!empty($agentOutdated))
                                <p class="text-xs text-white/85 mt-1">{{ __('pos.fbr_agent_selfupdate_note') }}</p>
                            @endif
                        @endif
                    </div>
                    <div class="text-right">
                        <div class="text-3xl font-bold">{{ $stats['printed_today'] }}</div>
                        <div class="text-xs text-white/75 uppercase">{{ __('pos.fbr_agent_stat_printed') }}</div>
                    </div>
                </div>
            </div>

            {{-- Submission routing is NOT configurable here, on purpose. Say so
                 out loud: shops used to reach the agent only through FBR
                 Settings, so the two felt like one setting. --}}
            <div class="mb-6 rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-4 text-sm text-blue-900 dark:text-blue-200 flex items-start gap-2">
                <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>
                    {{ __('pos.fbr_agent_mode_note') }}
                    <a href="{{ route('fbrpos.settings') }}" class="underline font-semibold">{{ __('pos.fbr_settings') }}</a>
                </span>
            </div>

            {{-- ─────────── What still needs doing ───────────
                 Pairing an agent alone prints nothing: silent print must be on,
                 and the Store slip needs its own feature. Say which step is
                 missing instead of leaving a paired-but-silent agent. --}}
            @if($company->agent_api_key && (!$silentPrintOn || !$reportedPrinters))
            <div class="mb-6 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
                <p class="text-sm font-bold text-amber-900 dark:text-amber-200 mb-1.5">{{ __('pos.fbr_agent_next_steps_title') }}</p>
                <ul class="text-xs text-amber-800 dark:text-amber-300 space-y-1 list-disc list-inside">
                    @if(!$silentPrintOn)
                    <li>{{ __('pos.fbr_agent_step_silent_off') }}
                        <a href="{{ route('fbrpos.printer-settings') }}" class="underline font-semibold">{{ __('pos.printer_settings') }}</a>
                    </li>
                    @endif
                    @if(!$reportedPrinters)
                    <li>{{ __('pos.fbr_agent_step_no_printers') }}</li>
                    @endif
                    @if(!$storeSlipOn)
                    <li>{{ __('pos.fbr_agent_step_store_slip_off') }}
                        <a href="{{ route('fbrpos.customize') }}" class="underline font-semibold">{{ __('pos.customize_fbr_pos') }}</a>
                    </li>
                    @endif
                </ul>
            </div>
            @endif

            {{-- ─────────── Print job stats ─────────── --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <div class="text-3xl font-bold text-amber-600 dark:text-amber-400">{{ $stats['queued'] }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ __('pos.fbr_agent_stat_queued') }}</div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <div class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ $stats['printed_today'] }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ __('pos.fbr_agent_stat_printed') }}</div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <div class="text-3xl font-bold text-red-600 dark:text-red-400">{{ $stats['failed_today'] }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ __('pos.fbr_agent_stat_failed') }}</div>
                </div>
            </div>

            {{-- ─────────── Credentials ─────────── --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">{{ __('pos.fbr_agent_credentials') }}</h3>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.fbr_agent_company_id') }}</label>
                        <div class="flex gap-2">
                            <input type="text" readonly value="{{ $company->id }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white font-mono">
                            <button type="button" onclick="fbrAgentCopy('{{ $company->id }}', this)" class="px-4 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg text-sm font-medium">{{ __('pos.copy_btn') }}</button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.fbr_agent_api_key') }}</label>
                        @if($company->agent_api_key)
                            <div class="flex gap-2">
                                <input type="password" id="fbrAgentKey" readonly value="{{ $company->agent_api_key }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white font-mono">
                                <button type="button" onclick="fbrAgentToggleKey(this)" class="px-4 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg text-sm font-medium"
                                        data-show="{{ __('pos.show_word') }}" data-hide="{{ __('pos.hide_word') }}">{{ __('pos.show_word') }}</button>
                                <button type="button" onclick="fbrAgentCopy(document.getElementById('fbrAgentKey').value, this)" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium">{{ __('pos.copy_btn') }}</button>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">⚠️ {{ __('pos.fbr_agent_key_secret_warn') }}</p>
                        @else
                            <div class="p-4 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 rounded-lg text-amber-800 dark:text-amber-300 text-sm">
                                {{ __('pos.fbr_agent_no_key_yet') }}
                            </div>
                        @endif
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.fbr_agent_server_url') }}</label>
                        <div class="flex gap-2">
                            <input type="text" readonly value="{{ url('/api/agent') }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white font-mono text-sm">
                            <button type="button" onclick="fbrAgentCopy('{{ url('/api/agent') }}', this)" class="px-4 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg text-sm font-medium">{{ __('pos.copy_btn') }}</button>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-4 border-t border-gray-200 dark:border-gray-700">
                        @if(!$company->agent_api_key)
                            <form method="POST" action="{{ route('fbrpos.agent.generate') }}">
                                @csrf
                                <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold">🔑 {{ __('pos.fbr_agent_generate_btn') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('fbrpos.agent.regenerate') }}" onsubmit="return confirm(@js(__('pos.fbr_agent_regenerate_confirm')))">
                                @csrf
                                <button type="submit" class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-semibold">🔄 {{ __('pos.fbr_agent_regenerate_btn') }}</button>
                            </form>
                        @endif
                        <a href="{{ route('fbrpos.printer-settings') }}" class="px-5 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-100 rounded-lg font-semibold">🖨 {{ __('pos.printer_settings') }}</a>
                    </div>
                </div>
            </div>

            {{-- ─────────── Download ─────────── --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('pos.fbr_agent_download_title') }}</h3>
                    @if(!empty($release['tag']))
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            {{ __('pos.fbr_agent_latest_prefix') }} {{ $release['tag'] }}
                        </span>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @php $winType = !empty($release['has_exe']) ? 'exe' : 'zip'; @endphp
                    <a href="{{ route('fbrpos.agent.download') }}?type={{ $winType }}"
                       class="group relative flex flex-col items-center p-6 border-2 border-emerald-300 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg hover:border-emerald-500 hover:shadow-lg transition">
                        <span class="absolute top-2 right-2 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-emerald-600 text-white">{{ __('pos.fbr_agent_recommended') }}</span>
                        <div class="text-5xl mb-2">🪟</div>
                        <div class="font-semibold text-gray-800 dark:text-gray-100">Windows</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            @if($winType === 'exe')
                                {{ __('pos.fbr_agent_exe_installer') }}@if(!empty($release['exe_size_mb'])) · {{ $release['exe_size_mb'] }} MB @endif
                            @else
                                {{ __('pos.fbr_agent_zip_portable') }}@if(!empty($release['zip_size_mb'])) · {{ $release['zip_size_mb'] }} MB @endif
                            @endif
                        </div>
                        <div class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300 group-hover:translate-y-0.5 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                            {{ __('pos.fbr_agent_download_now') }}
                        </div>
                    </a>
                    <div class="flex flex-col items-center p-6 border-2 border-dashed border-gray-300 dark:border-gray-700 rounded-lg opacity-50 cursor-not-allowed">
                        <div class="text-5xl mb-2">🍎</div>
                        <div class="font-semibold text-gray-800 dark:text-gray-100">macOS</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.fbr_agent_coming_soon') }}</div>
                    </div>
                    <div class="flex flex-col items-center p-6 border-2 border-dashed border-gray-300 dark:border-gray-700 rounded-lg opacity-50 cursor-not-allowed">
                        <div class="text-5xl mb-2">🐧</div>
                        <div class="font-semibold text-gray-800 dark:text-gray-100">Linux</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.fbr_agent_coming_soon') }}</div>
                    </div>
                </div>

                @if($winType === 'exe' && !empty($release['has_zip']))
                    <a href="{{ route('fbrpos.agent.download') }}?type=zip" class="mt-3 inline-block text-[11px] text-gray-500 dark:text-gray-400 hover:text-emerald-600 underline">
                        {{ __('pos.fbr_agent_or_zip') }}{{ !empty($release['zip_size_mb']) ? ' (' . $release['zip_size_mb'] . ' MB)' : '' }}
                    </a>
                @endif

                @if(empty($release['has_exe']) && empty($release['has_zip']))
                    <p class="mt-3 text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded px-3 py-2">
                        ⚠️ {{ __('pos.fbr_agent_build_in_progress') }}
                    </p>
                @endif
            </div>

            {{-- ─────────── Setup steps ─────────── --}}
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-700 p-6">
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-200 mb-3">📖 {{ __('pos.fbr_agent_setup_title') }}</h3>
                <ol class="list-decimal list-inside space-y-2 text-sm text-blue-900 dark:text-blue-200">
                    <li>{{ __('pos.fbr_agent_setup_step1') }}</li>
                    <li>{{ __('pos.fbr_agent_setup_step2') }}</li>
                    <li>{{ __('pos.fbr_agent_setup_step3') }}</li>
                    <li>{{ __('pos.fbr_agent_setup_step4') }}</li>
                    <li>{{ __('pos.fbr_agent_setup_step5') }}</li>
                </ol>
                <div class="mt-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg text-xs text-amber-800 dark:text-amber-300">
                    ⚠️ {{ __('pos.fbr_agent_setup_warn') }}
                </div>
            </div>
            @endif
        </div>
    </div>

    <script>
        function fbrAgentToggleKey(btn) {
            var input = document.getElementById('fbrAgentKey');
            if (!input) return;
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.textContent = showing ? btn.dataset.show : btn.dataset.hide;
        }
        function fbrAgentCopy(text, btn) {
            var done = function () {
                var orig = btn.textContent;
                btn.textContent = '✓';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(function () { fbrAgentCopyFallback(text, done); });
            } else {
                // Shop PCs on plain http have no navigator.clipboard — without this
                // fallback the Copy button silently did nothing on those counters.
                fbrAgentCopyFallback(text, done);
            }
        }
        function fbrAgentCopyFallback(text, done) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (e) { /* leave the button alone */ }
            document.body.removeChild(ta);
        }
    </script>
</x-fbr-pos-layout>
