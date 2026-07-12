<x-fbr-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">FBR Integration Settings</h1>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif

    @php
        $fbrMode = $company->fbr_connection_mode ?? 'cloud';
        $isAgentMode = $fbrMode === 'fiscal_device';
        $agentOnline = $company->agent_last_seen && \Carbon\Carbon::parse($company->agent_last_seen)->gt(now()->subMinutes(2));
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            {{-- Submission Mode selector — Direct to FBR vs Fiscal Device (Agent), PRA-style --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border-2 {{ $isAgentMode ? 'border-blue-300 dark:border-blue-700' : 'border-gray-200 dark:border-gray-700' }} shadow-md p-5">
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Submission Mode</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">FBR bills kaise bhejni hain. Kabhi bhi switch karen — restart ki zaroorat nahi.</p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs font-bold {{ $isAgentMode ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 border border-blue-300 dark:border-blue-700' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border border-gray-300 dark:border-gray-700' }}">
                        Abhi: {{ $isAgentMode ? 'Fiscal Device (Agent)' : 'Direct to FBR' }}
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- Direct to FBR --}}
                    <div class="relative p-5 rounded-xl border-2 transition {{ !$isAgentMode ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 opacity-70' }}">
                        @if(!$isAgentMode)
                        <span class="absolute top-2 right-2 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-blue-600 text-white">Active</span>
                        @endif
                        <div class="font-bold text-gray-900 dark:text-white mb-1">Direct to FBR (Cloud)</div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-3 leading-relaxed">Server FBR IMS ko seedha bill submit karega. Koi desktop agent zaroori nahi.</p>
                        <ul class="text-[11px] text-gray-600 dark:text-gray-300 space-y-1 mb-3">
                            <li>✓ Sab se saada — bus is mode pe rahen</li>
                            <li>⚠ FBR ne naye POS ke liye cloud PostData band kiya (Code 112) — zyada tar naye POS ko Fiscal Device chahiye</li>
                        </ul>
                        @if($isAgentMode)
                        <form method="POST" action="{{ route('fbrpos.settings') }}" onsubmit="return confirm('Direct to FBR mode enable karen? Bills server se seedha FBR jayengi (agent bypass).');">
                            @csrf
                            <input type="hidden" name="fbr_pos_environment" value="{{ $company->fbr_pos_environment ?? 'sandbox' }}">
                            <input type="hidden" name="fbr_connection_mode" value="cloud">
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg font-semibold transition">Switch to Direct</button>
                        </form>
                        @else
                        <div class="w-full px-4 py-2 bg-blue-600/10 text-blue-700 dark:text-blue-300 text-sm rounded-lg font-semibold text-center border border-blue-300 dark:border-blue-700">✓ Active Mode</div>
                        @endif
                    </div>

                    {{-- Fiscal Device (Agent) --}}
                    <div class="relative p-5 rounded-xl border-2 transition {{ $isAgentMode ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 opacity-70' }}">
                        @if($isAgentMode)
                        <span class="absolute top-2 right-2 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-blue-600 text-white">Active</span>
                        @endif
                        <div class="font-bold text-gray-900 dark:text-white mb-1">Fiscal Device (via Agent)</div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-3 leading-relaxed">Shop PC pe desktop agent bills uthata hai aur local FBR IMS component (localhost:8524) ko bhejta hai. FBR invoice number khud wapas aata hai.</p>
                        <ul class="text-[11px] text-gray-600 dark:text-gray-300 space-y-1 mb-3">
                            <li>✓ Naye POS registrations ke liye zaroori (Code 112)</li>
                            <li>✓ Wahi agent jo PRA use karta hai</li>
                            <li>⚠ Agent install + chalu rehna zaroori</li>
                        </ul>
                        @if(!$isAgentMode)
                        <form method="POST" action="{{ route('fbrpos.settings') }}" onsubmit="return confirm('Fiscal Device (Agent) mode enable karen? Bills pending jayengi aur shop PC ka agent unhe FBR ko bhejega.');">
                            @csrf
                            <input type="hidden" name="fbr_pos_environment" value="{{ $company->fbr_pos_environment ?? 'sandbox' }}">
                            <input type="hidden" name="fbr_connection_mode" value="fiscal_device">
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg font-semibold transition">Switch to Fiscal Device</button>
                        </form>
                        @else
                        <div class="w-full px-4 py-2 bg-blue-600/10 text-blue-700 dark:text-blue-300 text-sm rounded-lg font-semibold text-center border border-blue-300 dark:border-blue-700 flex items-center justify-center gap-2">
                            ✓ Active Mode
                            @if($agentOnline)
                            <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded bg-emerald-500 text-white">● ONLINE</span>
                            @else
                            <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded bg-red-500 text-white">OFFLINE</span>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">FBR POS Configuration</h3>
                <form method="POST" action="{{ route('fbrpos.settings') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Environment</label>
                        <select name="fbr_pos_environment" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="sandbox" {{ ($company->fbr_pos_environment ?? 'sandbox') === 'sandbox' ? 'selected' : '' }}>Sandbox (Testing)</option>
                            <option value="production" {{ ($company->fbr_pos_environment ?? 'sandbox') === 'production' ? 'selected' : '' }}>Production (Live)</option>
                        </select>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 rounded-lg p-2.5">
                        Submission Mode (<strong>Direct to FBR</strong> ya <strong>Fiscal Device</strong>) upar wale <strong>Submission Mode</strong> card se chunen.
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">FBR POS Registration ID <span class="text-red-500">*</span></label>
                        <input type="text" name="fbr_pos_id" value="{{ $company->fbr_pos_id }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. 196339">
                        <p class="text-xs text-gray-400 mt-1">Your FBR-assigned POS Registration Number (POSID). Required for IMS submission.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">FBR Access Code</label>
                        <input type="text" name="fbr_access_code" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ $maskedAccessCode ?: 'IRIS POS Registration grid ka Access Code' }}">
                        @if($maskedAccessCode)
                        <p class="text-xs text-gray-400 mt-1">Current: {{ $maskedAccessCode }} — leave empty to keep existing code</p>
                        @else
                        <p class="text-xs text-gray-400 mt-1">IRIS portal ke <strong>Point of Sale Registration</strong> grid mein POS ID ke saath jo Access Code likha hai — Fiscal Device setup ke liye zaroori. Encrypted mehfooz hota hai.</p>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">FBR POS Token <span class="text-red-500">*</span></label>
                        <input type="text" name="fbr_pos_token" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ $maskedPosToken ?: 'Enter FBR IMS POS API token' }}">
                        @if($maskedPosToken)
                        <p class="text-xs text-gray-400 mt-1">Current: {{ $maskedPosToken }} — leave empty to keep existing token</p>
                        @else
                        <p class="text-xs text-gray-400 mt-1">Dedicated FBR IMS POS token — this is separate from your Digital Invoicing token.</p>
                        @endif
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                        <p class="text-xs text-blue-700 dark:text-blue-400">
                            <strong>FBR IMS POS Endpoints (SRO 1279/2021):</strong><br>
                            Sandbox: https://esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData<br>
                            Production: https://gw.fbr.gov.pk/imsp/v1/api/Live/PostData
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition">Save Settings</button>
                        <button type="button" onclick="testFbrConnection()" id="testBtn" class="px-6 py-2 bg-gray-600 text-white text-sm rounded-lg hover:bg-gray-700 transition">
                            Test Connection
                        </button>
                    </div>
                </form>
                <div id="testResult" class="mt-3 hidden">
                    <div id="testResultContent" class="p-3 rounded-lg text-sm"></div>
                </div>
            </div>

            @if($isAgentMode)
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-blue-200 dark:border-blue-800 shadow-md p-5">
                <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                        Connect the Desktop Sync Agent
                        <span class="text-[9px] px-1.5 py-0.5 bg-blue-600 text-white rounded font-bold uppercase tracking-wider">Fiscal Device</span>
                    </h3>
                    @if($agentOnline)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-500 text-white text-[10px] font-bold"><span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span> ONLINE</span>
                    @else
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-500 text-white text-[10px] font-bold"><span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span> OFFLINE</span>
                    @endif
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    Fiscal Device mode mein yeh server FBR ko seedha submit nahi karta. Bills queue hoti hain aur shop PC ka Desktop Sync Agent har bill ko local FBR IMS component (<code>localhost:8524</code>) ko bhejta hai. FBR invoice number khud wapas aa jata hai.
                </p>

                {{-- 3 fields the agent needs to connect --}}
                <div class="space-y-3 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">1. Server URL</label>
                        <div class="flex gap-2">
                            <input type="text" id="agentServerUrl" readonly value="{{ url('/api/agent') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs font-mono focus:ring-blue-500 focus:border-blue-500">
                            <button type="button" onclick="copyField('agentServerUrl', this)" class="px-3 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs rounded-lg whitespace-nowrap">Copy</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">2. Company ID</label>
                        <div class="flex gap-2">
                            <input type="text" id="agentCompanyId" readonly value="{{ $company->id }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs font-mono focus:ring-blue-500 focus:border-blue-500">
                            <button type="button" onclick="copyField('agentCompanyId', this)" class="px-3 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs rounded-lg whitespace-nowrap">Copy</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">3. Agent API Key</label>
                        <div class="flex gap-2">
                            <input type="password" id="agentKey" readonly value="{{ $company->agent_api_key ?? '' }}" placeholder="Fiscal Device mode mein Save karen — key ban jayegi" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs font-mono focus:ring-blue-500 focus:border-blue-500">
                            <button type="button" onclick="toggleAgentKey()" id="agentKeyToggle" class="px-3 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs rounded-lg whitespace-nowrap">Show</button>
                            <button type="button" onclick="copyField('agentKey', this)" class="px-3 py-2 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700 transition whitespace-nowrap">Copy</button>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">⚠️ Yeh key secret rakhen — jis ke paas ho woh aap ki company ke FBR bills bhej sakta hai.</p>
                    </div>
                </div>

                {{-- Download + regenerate --}}
                <div class="mb-4">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Download Desktop Sync Agent (Windows)</label>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('fbrpos.agent.download') }}?type=exe"
                           class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download Agent (.exe)
                        </a>
                        <a href="{{ route('fbrpos.agent.download') }}?type=zip"
                           class="inline-flex items-center gap-1.5 px-3 py-2 border border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-400 text-xs font-semibold rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                            Portable (.zip)
                        </a>
                        @if($company->agent_api_key)
                        <form method="POST" action="{{ route('fbrpos.settings') }}" onsubmit="return confirm('Purani key band ho jayegi aur agent ko dobara connect karna hoga. Continue?')" class="inline">
                            @csrf
                            <input type="hidden" name="fbr_pos_environment" value="{{ $company->fbr_pos_environment ?? 'sandbox' }}">
                            <input type="hidden" name="regenerate_agent_key" value="1">
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 border border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-400 text-xs font-semibold rounded-lg hover:bg-amber-50 dark:hover:bg-amber-900/20 transition">Regenerate Key</button>
                        </form>
                        @endif
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Shop PC pe install karen (wahi PC jahan FBR IMS component hai). Yeh <strong>wahi</strong> agent hai jo PRA use karta hai — FBR ke liye alag version nahi.</p>
                </div>

                <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs">Agent</span>
                        <span class="{{ $company->agent_enabled ? 'text-blue-600 font-semibold' : 'text-red-500' }}">{{ $company->agent_enabled ? 'Enabled' : 'Disabled' }}</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs">Last Seen</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->agent_last_seen ? \Carbon\Carbon::parse($company->agent_last_seen)->diffForHumans() : 'Never connected' }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                    <p class="text-xs font-semibold text-blue-700 dark:text-blue-400 mb-1">📖 Agent connect karne ka tareeqa (shop PC pe)</p>
                    <ol class="text-xs text-blue-700 dark:text-blue-400 list-decimal list-inside space-y-0.5">
                        <li>Shop PC pe FBR IMS Fiscal component install karen (aap ki FBR registration se) — <code>localhost:8524</code> chalu ho.</li>
                        <li>Upar se <strong>Download Agent (.exe)</strong> usi PC pe install karen.</li>
                        <li>Agent kholen aur teen khaane bharen: <strong>Server URL</strong>, <strong>Company ID</strong>, <strong>API Key</strong> (upar se Copy karen).</li>
                        <li><strong>Connect</strong> dabayen — upar status <strong>ONLINE</strong> ho jayega.</li>
                        <li>PC business hours mein on rakhen — bills khud-ba-khud sync hongi.</li>
                    </ol>
                </div>
            </div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Confidential PIN</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Set a 4-6 digit PIN to protect access to local (non-FBR) invoice data. Required to view local transactions.</p>

                @php $hasPinSet = !empty($company->confidential_pin); @endphp
                @if($hasPinSet)
                <div class="flex items-center gap-2 mb-4 p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20">
                    <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span class="text-sm font-medium text-blue-700 dark:text-blue-400">PIN is set and active</span>
                </div>
                @endif

                <form method="POST" action="{{ route('fbrpos.settings') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="pin_update" value="1">
                    <input type="hidden" name="fbr_pos_environment" value="{{ $company->fbr_pos_environment ?? 'sandbox' }}">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ $hasPinSet ? 'Change PIN' : 'Set PIN' }} (4-6 digits)</label>
                        <input type="password" name="confidential_pin" maxlength="6" pattern="\d{4,6}" placeholder="Enter 4-6 digit PIN" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition">{{ $hasPinSet ? 'Update PIN' : 'Set PIN' }}</button>
                        @if($hasPinSet)
                        <button type="submit" name="remove_pin" value="1" onclick="return confirm('Remove the confidential PIN? Local data will be accessible without verification.')" class="px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition">Remove PIN</button>
                        @endif
                    </div>
                </form>
            </div>

            {{-- 🌐 Universal Sale Screen toggle (Phase 1) — classic create screen stays the fallback --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5"
                 x-data="{ on: {{ ($company->fbr_universal_enabled ?? false) ? 'true' : 'false' }}, busy: false, msg: '',
                    flip() {
                        if (this.busy) return; this.busy = true; this.msg = '';
                        fetch('{{ route('fbrpos.api.toggle-universal') }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
                        })
                        .then(r => r.json())
                        .then(d => { if (d.success) { this.on = d.enabled; this.msg = d.message; } else { this.msg = d.message || 'Failed to update.'; } })
                        .catch(() => { this.msg = 'Network error — please try again.'; })
                        .finally(() => { this.busy = false; });
                    } }">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                            Universal Sale Screen
                            <span class="text-[9px] px-1.5 py-0.5 bg-blue-600 text-white rounded font-bold uppercase tracking-wider">New</span>
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Modern billing screen with product grid, cart and full keyboard flow — same experience as Universal POS.
                            Turn OFF anytime to return to the classic screen. FBR submission stays exactly the same.
                        </p>
                        <p class="text-xs mt-2 font-medium" :class="on ? 'text-blue-600' : 'text-gray-400'" x-show="msg" x-text="msg" x-cloak></p>
                    </div>
                    <button type="button" @click="flip()" :disabled="busy"
                            :class="on ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-700'"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition disabled:opacity-50"
                            role="switch" :aria-checked="on.toString()" aria-label="Toggle Universal Sale Screen">
                        <span :class="on ? 'translate-x-6' : 'translate-x-1'" class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition"></span>
                    </button>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">FBR Registration Details</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">These values are used in FBR invoice payloads. Update them from your main DI company profile if needed.</p>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Seller NTN/CNIC</span>
                        <span class="text-gray-900 dark:text-white font-mono">{{ $company->fbr_registration_no ?: ($company->ntn ?? 'Not Set') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">FBR Business Name</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->fbr_business_name ?: ($company->name ?? 'Not Set') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Province</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->province ?? 'Not Set' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Address</span>
                        <span class="text-gray-900 dark:text-white text-right max-w-[60%]">{{ $company->address ?? 'Not Set' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Status</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">FBR POS</span>
                        <span class="{{ $company->fbr_pos_enabled ? 'text-blue-600 font-semibold' : 'text-red-500' }}">
                            {{ $company->fbr_pos_enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Environment</span>
                        <span class="text-gray-900 dark:text-white">{{ ucfirst($company->fbr_pos_environment ?? 'sandbox') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">POS ID</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->fbr_pos_id ?? 'Not Set' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">POS Token</span>
                        <span class="{{ $maskedPosToken ? 'text-blue-600 font-semibold' : 'text-red-500' }}">
                            {{ $maskedPosToken ? 'Configured' : 'Not Set' }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Connection</span>
                        <span class="text-blue-600 font-semibold">{{ ($company->fbr_connection_mode ?? 'cloud') === 'fiscal_device' ? 'Fiscal Device (Local)' : 'IMS POS (Direct Cloud)' }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Recent FBR Logs</h3>
                @forelse($fbrLogs as $log)
                <div class="border-b border-gray-100 dark:border-gray-800 last:border-0 py-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium {{ $log->status === 'success' ? 'text-blue-600' : ($log->status === 'failed' ? 'text-red-600' : 'text-amber-600') }}">
                            {{ strtoupper($log->status) }}
                        </span>
                        <span class="text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">Code: {{ $log->response_code ?? 'N/A' }}</p>
                    @if($log->error_message)
                    <p class="text-xs text-red-400 mt-0.5 truncate">{{ Str::limit($log->error_message, 80) }}</p>
                    @endif
                </div>
                @empty
                <p class="text-xs text-gray-400">No FBR logs yet</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

<script>
function copyField(id, btn) {
    const input = document.getElementById(id);
    if (!input || input.value === '') return;
    const wasPassword = input.type === 'password';
    navigator.clipboard.writeText(input.value).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Copied';
        setTimeout(() => { btn.textContent = orig; }, 1500);
    }).catch(() => {
        input.type = 'text';
        input.select();
        input.setSelectionRange(0, 99999);
        document.execCommand('copy');
        if (wasPassword) input.type = 'password';
    });
}

function toggleAgentKey() {
    const input = document.getElementById('agentKey');
    const btn = document.getElementById('agentKeyToggle');
    if (!input) return;
    if (input.type === 'password') { input.type = 'text'; if (btn) btn.textContent = 'Hide'; }
    else { input.type = 'password'; if (btn) btn.textContent = 'Show'; }
}

function testFbrConnection() {
    const btn = document.getElementById('testBtn');
    const resultDiv = document.getElementById('testResult');
    const resultContent = document.getElementById('testResultContent');

    btn.disabled = true;
    btn.textContent = 'Testing...';
    btn.classList.add('opacity-50');
    resultDiv.classList.add('hidden');

    fetch('{{ route("fbrpos.testConnection") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
    })
    .then(r => r.json())
    .then(data => {
        resultDiv.classList.remove('hidden');
        if (data.success) {
            resultContent.className = 'p-3 rounded-lg text-sm bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 border border-blue-200 dark:border-blue-800';
        } else {
            resultContent.className = 'p-3 rounded-lg text-sm bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800';
        }
        resultContent.textContent = data.message;
    })
    .catch(err => {
        resultDiv.classList.remove('hidden');
        resultContent.className = 'p-3 rounded-lg text-sm bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800';
        resultContent.textContent = 'Connection test failed: ' + err.message;
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
        btn.classList.remove('opacity-50');
    });
}
</script>
</x-fbr-pos-layout>
