<x-pos-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Customize
            </a>
            <div class="mb-6">
                <h2 class="font-bold text-2xl text-gray-800 dark:text-gray-100 leading-tight">
                    TaxNest PRA Sync Agent
                </h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    Install our desktop agent on your Pakistani PC for direct PRA submission — no relay or proxy needed.
                </p>
            </div>

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-700 rounded-lg text-emerald-700 dark:text-emerald-300 font-medium">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 rounded-lg text-red-700 dark:text-red-300 font-medium">{{ session('error') }}</div>
            @endif

            {{-- Task 117: Offline billing + Desktop App = Business+ plan gate.
                 Already-paired shops (agent_api_key set) are grandfathered — full page. --}}
            @if(empty($offlineAllowed) && empty($company->agent_api_key))
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-amber-200 dark:border-amber-700 p-8 text-center">
                <div class="text-5xl mb-3">🔒</div>
                <h3 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">Desktop App aap ke package mein shamil nahi</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 max-w-xl mx-auto">
                    Offline billing aur Desktop App (silent printing + PRA agent sync) <strong>Business</strong> aur us se upar ke packages ka feature hai.
                    Upgrade karte hi yeh page khul jayega.
                </p>
                <a href="{{ route('pos.billing') }}" class="inline-flex items-center gap-2 mt-5 px-6 py-3 rounded-lg bg-gradient-to-r from-indigo-600 to-purple-700 text-white font-bold text-sm hover:opacity-90 transition">
                    Package Upgrade Karein
                </a>
            </div>
            @else

            {{-- Status Card --}}
            <div class="bg-gradient-to-r from-indigo-600 to-purple-700 rounded-xl shadow-lg p-6 text-white mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <h3 class="text-xl font-bold">Agent Status</h3>
                            @if($isOnline)
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-emerald-500 text-white text-xs font-bold">
                                    <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span> ONLINE
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-gray-600 text-white text-xs font-bold">
                                    <span class="w-2 h-2 rounded-full bg-gray-300"></span> OFFLINE
                                </span>
                            @endif
                        </div>
                        <p class="text-sm text-indigo-100">
                            Last seen: {{ $company->agent_last_seen ? $company->agent_last_seen->diffForHumans() : 'Never connected' }}
                        </p>
                        @if($company->agent_version)
                            <p class="text-xs text-indigo-200 mt-1">
                                Version: {{ $company->agent_version }}
                                @if(!empty($agentOutdated))
                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full bg-amber-500/90 text-white text-[10px] font-bold uppercase">
                                        Purani version — latest v{{ $latestAgentVersion }}
                                    </span>
                                @elseif(!empty($latestAgentVersion))
                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-500/80 text-white text-[10px] font-bold uppercase">Up to date</span>
                                @endif
                            </p>
                            @if(!empty($agentOutdated))
                                <p class="text-xs text-indigo-100 mt-1">
                                    Agent khud update hone ki koshish karta hai — PC on aur internet chalta rahe to nayi version khud lag jayegi.
                                </p>
                                @if($company->agent_update_error ?? null)
                                    <p class="text-xs text-amber-200 mt-1 break-words">
                                        Aakhri update koshish{{ $company->agent_update_target ? ' (v' . $company->agent_update_target . ')' : '' }} fail hui{{ $company->agent_update_at ? ' ' . $company->agent_update_at->diffForHumans() : '' }}:
                                        {{ \Illuminate\Support\Str::limit($company->agent_update_error, 120) }}
                                    </p>
                                @endif
                            @endif
                        @endif
                        @if(!is_null($company->agent_offline_mode))
                            <p class="text-xs text-indigo-200 mt-1">
                                Offline Mode:
                                @if($company->agent_offline_mode)
                                    <span class="font-semibold text-emerald-300">ON</span>
                                    @if($company->agent_snapshot_at)
                                        · Snapshot: {{ $company->agent_snapshot_at->diffForHumans() }}
                                    @else
                                        · Snapshot: not captured yet
                                    @endif
                                @else
                                    <span class="font-semibold">OFF</span>
                                @endif
                            </p>
                        @endif
                        {{-- Bills still held on a counter device. Shown whether or not
                             Offline Mode is on: a queue can build up from a dropped
                             line, a quota block or an expired session too. The shop
                             used to find this out only when day-close disagreed. --}}
                        @if(($company->offline_queue_depth ?? 0) > 0)
                            <p class="text-xs mt-1 {{ $company->offline_queue_depth > 10 ? 'text-rose-200 font-semibold' : 'text-amber-200' }}">
                                Counter device par ruke hue bill: {{ $company->offline_queue_depth }}
                                @if($company->offline_queue_oldest_at)
                                    · sab se purana {{ $company->offline_queue_oldest_at->diffForHumans() }}
                                @endif
                                @if($company->offline_queue_reported_at)
                                    · aakhri report {{ $company->offline_queue_reported_at->diffForHumans() }}
                                @endif
                            </p>
                        @endif
                    </div>
                    <div class="text-right">
                        <div class="text-3xl font-bold">{{ $stats['submitted_today'] }}</div>
                        <div class="text-xs text-indigo-200 uppercase">Submitted Today</div>
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 SUBMISSION MODE — Direct Production vs Agent Sync
                 (agentHandlesPra() — submission mode is DECOUPLED from
                 agent_enabled; Direct mode keeps the agent connected for
                 silent printing.)
                 ============================================================ --}}
            @php $agentSync = $company->agentHandlesPra(); @endphp
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border-2 {{ $agentSync ? 'border-purple-300 dark:border-purple-700' : 'border-blue-300 dark:border-blue-700' }} p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            ⚙️ Invoice Submission Mode
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Choose how PRA invoices are sent. Switch anytime — no restart required.</p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs font-bold {{ $agentSync ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 border border-purple-300 dark:border-purple-700' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 border border-blue-300 dark:border-blue-700' }}">
                        Currently: {{ $agentSync ? '🤖 AGENT SYNC' : '⚡ DIRECT PRODUCTION' }}
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- Direct Production Card --}}
                    <div class="relative p-5 rounded-xl border-2 transition {{ !$agentSync ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 ring-2 ring-blue-200 dark:ring-blue-800' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 opacity-70' }}">
                        @if(!$agentSync)
                            <span class="absolute top-2 right-2 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-blue-600 text-white">Active</span>
                        @endif
                        <div class="flex items-center gap-2 mb-2">
                            <div class="text-3xl">⚡</div>
                            <div class="font-bold text-gray-900 dark:text-white">Direct Production</div>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-3 leading-relaxed">
                            Server taxnest.pk PRA pe directly invoice submit karega. Koi desktop agent install karne ki zaroorat nahi.
                        </p>
                        <ul class="text-[11px] text-gray-600 dark:text-gray-300 space-y-1 mb-3">
                            <li>✓ Tezi se setup — bus toggle ON</li>
                            <li>✓ Multi-location / mobile sales</li>
                            <li>✓ Silent printing chalti rahegi (agent connected rahega)</li>
                            <li>⚠ Server ka IP Pakistan se whitelist hona chahiye</li>
                        </ul>
                        @if($agentSync)
                            <form method="POST" action="{{ route('pos.agent.toggle') }}" onsubmit="return confirm('Direct Production mode enable karen? Aage se invoices server se directly PRA jayengi. Agent connected rahega — silent printing chalti rahegi.');">
                                @csrf
                                <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg font-semibold transition">
                                    Switch to Direct Production
                                </button>
                            </form>
                        @else
                            <div class="w-full px-4 py-2 bg-blue-600/10 text-blue-700 dark:text-blue-300 text-sm rounded-lg font-semibold text-center border border-blue-300 dark:border-blue-700">
                                ✓ Active Mode
                            </div>
                        @endif
                    </div>

                    {{-- Agent Sync Card --}}
                    <div class="relative p-5 rounded-xl border-2 transition {{ $agentSync ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20 ring-2 ring-purple-200 dark:ring-purple-800' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 opacity-70' }}">
                        @if($agentSync)
                            <span class="absolute top-2 right-2 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-purple-600 text-white">Active</span>
                        @endif
                        <div class="flex items-center gap-2 mb-2">
                            <div class="text-3xl">🤖</div>
                            <div class="font-bold text-gray-900 dark:text-white">Agent Sync (via Desktop App)</div>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-3 leading-relaxed">
                            Aap ke local Pakistani PC pe install desktop agent invoices uthata aur PRA pe submit karta hai. IP whitelist ki zaroorat nahi.
                        </p>
                        <ul class="text-[11px] text-gray-600 dark:text-gray-300 space-y-1 mb-3">
                            <li>✓ Local PC ka Pakistani IP use hota hai</li>
                            <li>✓ Server PRA se direct connect nahi karta</li>
                            <li>⚠ Agent install + chalu rehna zaroori hai</li>
                        </ul>
                        @if(!$agentSync)
                            @if($company->agent_api_key)
                                <form method="POST" action="{{ route('pos.agent.toggle') }}" onsubmit="return confirm('Agent Sync mode enable karen? Aage se invoices pending mein jayengi aur desktop agent unhe pick up karega. Agent zaroor chalu rakhen.');">
                                    @csrf
                                    <button type="submit" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm rounded-lg font-semibold transition">
                                        Switch to Agent Sync
                                    </button>
                                </form>
                            @else
                                <div class="w-full px-4 py-2 bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300 text-xs rounded-lg font-semibold text-center border border-amber-300 dark:border-amber-700">
                                    ⚠ Pehle "Generate Key" karen (niche)
                                </div>
                            @endif
                        @else
                            <div class="w-full px-4 py-2 bg-purple-600/10 text-purple-700 dark:text-purple-300 text-sm rounded-lg font-semibold text-center border border-purple-300 dark:border-purple-700 flex items-center justify-center gap-2">
                                ✓ Active Mode
                                @if($isOnline)
                                    <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded bg-emerald-500 text-white">● ONLINE</span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded bg-red-500 text-white">⚠ OFFLINE</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            @if($canManageLocalCore ?? false)
                <div class="mb-6 p-5 rounded-xl border {{ $company->agent_core_enabled ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/20' : 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/40' }}">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h3 class="font-bold text-gray-900 dark:text-white">Local TaxNest Core</h3>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                Compatible desktop agents can send the version 1 local event inbox. This does not change PRA sync or sales processing.
                            </p>
                        </div>
                        <form method="POST" action="{{ route('pos.agent.local-core.toggle') }}" onsubmit="return confirm('Local TaxNest Core {{ $company->agent_core_enabled ? 'disable' : 'enable' }} karen?');">
                            @csrf
                            <button type="submit" class="px-4 py-2 text-sm rounded-lg font-semibold {{ $company->agent_core_enabled ? 'bg-gray-700 hover:bg-gray-800 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white' }}">
                                {{ $company->agent_core_enabled ? 'Disable Local Core' : 'Enable Local Core' }}
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            {{-- Stats --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <div class="text-3xl font-bold text-amber-600 dark:text-amber-400">{{ $stats['pending'] }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">Pending PRA Sync</div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <div class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ $stats['submitted_today'] }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">Submitted Today</div>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <div class="text-3xl font-bold text-red-600 dark:text-red-400">{{ $stats['failed_today'] }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">Failed Today</div>
                </div>
            </div>

            {{-- Credentials Card --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Agent Credentials</h3>

                <div class="space-y-4">
                    {{-- Company ID --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Company ID</label>
                        <div class="flex gap-2">
                            <input type="text" readonly value="{{ $company->id }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white font-mono">
                            <button onclick="copyToClipboard('{{ $company->id }}', this)" class="px-4 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg text-sm font-medium">Copy</button>
                        </div>
                    </div>

                    {{-- API Key --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">API Key</label>
                        @if($company->agent_api_key)
                            <div class="flex gap-2">
                                <input type="password" id="apiKey" data-password-toggle-exempt="true" readonly value="{{ $company->agent_api_key }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white font-mono">
                                <button type="button" onclick="toggleKey()" class="px-4 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg text-sm font-medium" id="toggleBtn">Show</button>
                                <button type="button" onclick="copyToClipboard(document.getElementById('apiKey').value, this)" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium">Copy</button>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">⚠️ Keep this key secret. Anyone with this key can submit PRA invoices for your company.</p>
                        @else
                            <div class="p-4 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 rounded-lg text-amber-800 dark:text-amber-300 text-sm">
                                No API key generated yet. Click "Generate Key" below to create one.
                            </div>
                        @endif
                    </div>

                    {{-- Agent Server URL --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Server URL (paste in agent)</label>
                        <div class="flex gap-2">
                            <input type="text" readonly value="{{ url('/api/agent') }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white font-mono text-sm">
                            <button onclick="copyToClipboard('{{ url('/api/agent') }}', this)" class="px-4 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg text-sm font-medium">Copy</button>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="flex flex-wrap gap-2 pt-4 border-t border-gray-200 dark:border-gray-700">
                        @if(!$company->agent_api_key)
                            <form method="POST" action="{{ route('pos.agent.generate') }}">
                                @csrf
                                <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold">
                                    🔑 Generate Key
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('pos.agent.regenerate') }}" onsubmit="return confirm('Regenerating will disconnect existing agents. Continue?')">
                                @csrf
                                <button type="submit" class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-semibold">
                                    🔄 Regenerate Key
                                </button>
                            </form>
                            {{-- Submission mode is shown as a separate card below; this toggle moved out --}}
                        @endif
                    </div>
                </div>
            </div>

            {{-- Download Section --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Download TaxNest Agent</h3>
                    @if(!empty($release['tag']))
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            Latest: {{ $release['tag'] }}
                        </span>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Windows download — .exe installer when available, otherwise the portable .zip is the primary button --}}
                    @php $winType = !empty($release['has_exe']) ? 'exe' : 'zip'; @endphp
                    <a href="{{ route('pos.agent.download') }}?type={{ $winType }}"
                       class="group relative flex flex-col items-center p-6 border-2 border-emerald-300 dark:border-emerald-700 bg-gradient-to-br from-emerald-50 to-white dark:from-emerald-900/20 dark:to-gray-900 rounded-lg hover:border-emerald-500 hover:shadow-lg transition">
                        <span class="absolute top-2 right-2 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-emerald-600 text-white">Recommended</span>
                        <div class="text-5xl mb-2">🪟</div>
                        <div class="font-semibold text-gray-800 dark:text-gray-100">Windows</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            @if($winType === 'exe')
                                .exe Installer
                                @if(!empty($release['exe_size_mb']))
                                    · {{ $release['exe_size_mb'] }} MB
                                @endif
                            @else
                                .zip (Portable — no installer needed)
                                @if(!empty($release['zip_size_mb']))
                                    · {{ $release['zip_size_mb'] }} MB
                                @endif
                            @endif
                        </div>
                        <div class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300 group-hover:translate-y-0.5 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                            Download Now
                        </div>
                        @if($winType === 'exe' && !empty($release['has_zip']))
                            <a href="{{ route('pos.agent.download') }}?type=zip"
                               onclick="event.stopPropagation();"
                               class="mt-2 text-[11px] text-gray-500 dark:text-gray-400 hover:text-emerald-600 underline">
                                or portable .zip{{ !empty($release['zip_size_mb']) ? ' ('.$release['zip_size_mb'].' MB)' : '' }}
                            </a>
                        @endif
                    </a>
                    <div class="flex flex-col items-center p-6 border-2 border-dashed border-gray-300 dark:border-gray-700 rounded-lg opacity-50 cursor-not-allowed">
                        <div class="text-5xl mb-2">🍎</div>
                        <div class="font-semibold text-gray-800 dark:text-gray-100">macOS</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Coming Soon</div>
                    </div>
                    <div class="flex flex-col items-center p-6 border-2 border-dashed border-gray-300 dark:border-gray-700 rounded-lg opacity-50 cursor-not-allowed">
                        <div class="text-5xl mb-2">🐧</div>
                        <div class="font-semibold text-gray-800 dark:text-gray-100">Linux</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Coming Soon</div>
                    </div>
                </div>

                @if(empty($release['has_exe']) && empty($release['has_zip']))
                    <p class="mt-3 text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded px-3 py-2">
                        ⚠️ Build is in progress. If clicking Windows opens a GitHub page instead of downloading, please wait 2–3 minutes and refresh.
                    </p>
                @endif
            </div>

            {{-- Setup Instructions --}}
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-700 p-6">
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-200 mb-3">📖 Install Karne Ka Tareeqa</h3>
                <ol class="list-decimal list-inside space-y-2 text-sm text-blue-900 dark:text-blue-200">
                    <li>Upar se Windows <strong>.zip</strong> download karein (Pakistan wale PC par, internet zaroori)</li>
                    <li>Zip file par right-click karke <strong>"Extract All"</strong> karein — Downloads ya Desktop par (kisi purane agent folder ke upar nahi)</li>
                    <li>Extract hue folder mein <strong>install.bat</strong> par double-click karein — installer purana agent khud band karke nayi files laga dega, shortcuts bana dega</li>
                    <li>Agent khud start ho kar system tray mein chala jayega. Pehli dafa <strong>Company ID</strong>, <strong>API Key</strong> aur <strong>Server URL</strong> (upar diye gaye) enter karein — ya NestPOS Desktop window mein sirf login karein, settings khud lag jayengi</li>
                    <li>PC business hours mein online rakhein — bas! Aage se <strong>naye versions khud-ba-khud update</strong> ho jayenge, dobara download/install ki zaroorat <strong>nahi</strong></li>
                </ol>
                <div class="mt-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg text-xs text-amber-800 dark:text-amber-300">
                    ⚠️ <strong>Zaroori:</strong> Zip ko kabhi chalte hue agent ke folder par seedha extract na karein — Windows "File In Use / open in Electron" ka error dega. Hamesha naye folder mein extract karke <strong>install.bat</strong> chalayen; wo purana agent khud band kar deta hai.
                </div>
                <div class="mt-4 p-3 bg-white dark:bg-gray-900 rounded-lg text-xs text-gray-700 dark:text-gray-300">
                    <strong>How it works:</strong> Your PC will check for pending invoices every 30 seconds, submit them to PRA using your local Pakistani IP, and report results back to TaxNest. No relay, no proxy, no PC dependency on TaxNest's servers.
                </div>
            </div>
            @endif
        </div>
    </div>

    <script>
        function toggleKey() {
            const input = document.getElementById('apiKey');
            const btn = document.getElementById('toggleBtn');
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = 'Hide';
            } else {
                input.type = 'password';
                btn.textContent = 'Show';
            }
        }
        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const orig = btn.textContent;
                btn.textContent = '✓ Copied';
                btn.classList.add('bg-emerald-600', 'text-white');
                setTimeout(() => {
                    btn.textContent = orig;
                    btn.classList.remove('bg-emerald-600', 'text-white');
                }, 1500);
            });
        }
    </script>
</x-pos-layout>
