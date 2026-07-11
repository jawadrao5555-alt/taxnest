<x-fbr-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">FBR Integration Settings</h1>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
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
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Submission Mode</label>
                        <select name="fbr_connection_mode" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="cloud" {{ ($company->fbr_connection_mode ?? 'cloud') === 'cloud' ? 'selected' : '' }}>Cloud — Direct to FBR</option>
                            <option value="fiscal_device" {{ ($company->fbr_connection_mode ?? 'cloud') === 'fiscal_device' ? 'selected' : '' }}>Fiscal Device — Local component on shop PC</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">FBR has retired cloud bulk upload for POS. New POS registrations must use <strong>Fiscal Device</strong> — bills sync through the FBR IMS component installed on your shop PC via the Desktop Sync Agent.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">FBR POS Registration ID <span class="text-red-500">*</span></label>
                        <input type="text" name="fbr_pos_id" value="{{ $company->fbr_pos_id }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. 196339">
                        <p class="text-xs text-gray-400 mt-1">Your FBR-assigned POS Registration Number (POSID). Required for IMS submission.</p>
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

            @if(($company->fbr_connection_mode ?? 'cloud') === 'fiscal_device')
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-blue-200 dark:border-blue-800 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
                    Desktop Sync Agent
                    <span class="text-[9px] px-1.5 py-0.5 bg-blue-600 text-white rounded font-bold uppercase tracking-wider">Fiscal Device</span>
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    In Fiscal Device mode this server never submits directly to FBR. Bills are queued and the Desktop Sync Agent, running on your shop PC, forwards each one to the local FBR IMS Fiscal component (<code>localhost:8524</code>). The FBR invoice number then flows back automatically.
                </p>

                <div class="mb-3">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Agent API Key</label>
                    <div class="flex gap-2">
                        <input type="text" id="agentKey" readonly value="{{ $company->agent_api_key ?? '' }}"
                               placeholder="Select Fiscal Device + Save Settings to generate a key"
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs font-mono focus:ring-blue-500 focus:border-blue-500">
                        <button type="button" onclick="copyAgentKey()" class="px-3 py-2 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700 transition whitespace-nowrap">Copy</button>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Paste this key into the Desktop Sync Agent on the shop PC to link it to this company.</p>
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
                    <p class="text-xs font-semibold text-blue-700 dark:text-blue-400 mb-1">Setup on the shop PC</p>
                    <ol class="text-xs text-blue-700 dark:text-blue-400 list-decimal list-inside space-y-0.5">
                        <li>Install PRAL's FBR IMS Fiscal Device service on the shop PC (from your FBR registration).</li>
                        <li>Install &amp; run the TaxNest Desktop Sync Agent on the same PC.</li>
                        <li>Paste the Agent API Key above into the agent and click Connect.</li>
                        <li>Keep the PC on during business hours — bills sync automatically.</li>
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
function copyAgentKey() {
    const input = document.getElementById('agentKey');
    if (!input || !input.value) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        const btn = event.target;
        const orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = orig; }, 1500);
    }).catch(() => { document.execCommand('copy'); });
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
