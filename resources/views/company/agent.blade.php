<x-app-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
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
                            <p class="text-xs text-indigo-200 mt-1">Version: {{ $company->agent_version }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <div class="text-3xl font-bold">{{ $stats['submitted_today'] }}</div>
                        <div class="text-xs text-indigo-200 uppercase">Submitted Today</div>
                    </div>
                </div>
            </div>

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
                                <input type="password" id="apiKey" readonly value="{{ $company->agent_api_key }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white font-mono">
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
                            <form method="POST" action="{{ route('company.agent.generate') }}">
                                @csrf
                                <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold">
                                    🔑 Generate Key
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('company.agent.regenerate') }}" onsubmit="return confirm('Regenerating will disconnect existing agents. Continue?')">
                                @csrf
                                <button type="submit" class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-semibold">
                                    🔄 Regenerate Key
                                </button>
                            </form>
                            <form method="POST" action="{{ route('company.agent.toggle') }}">
                                @csrf
                                <button type="submit" class="px-5 py-2 {{ $company->agent_enabled ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700' }} text-white rounded-lg font-semibold">
                                    {{ $company->agent_enabled ? '⏸ Disable Agent' : '▶ Enable Agent' }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Download Section --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Download TaxNest Agent</h3>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <a href="{{ route('company.agent.download') }}" class="flex flex-col items-center p-6 border-2 border-dashed border-gray-300 dark:border-gray-700 rounded-lg hover:border-emerald-500 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition">
                        <div class="text-5xl mb-2">🪟</div>
                        <div class="font-semibold text-gray-800 dark:text-gray-100">Windows</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">.exe Installer</div>
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
            </div>

            {{-- Setup Instructions --}}
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-700 p-6">
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-200 mb-3">📖 Setup Instructions</h3>
                <ol class="list-decimal list-inside space-y-2 text-sm text-blue-900 dark:text-blue-200">
                    <li>Download the TaxNest Agent installer for your operating system above</li>
                    <li>Install the agent on your Pakistan-based PC (must have internet access)</li>
                    <li>Launch the agent and enter your <strong>Company ID</strong>, <strong>API Key</strong>, and <strong>Server URL</strong> shown above</li>
                    <li>Click "Connect" — the agent will now auto-sync your invoices to PRA</li>
                    <li>Keep the PC online during business hours (agent runs in system tray)</li>
                </ol>
                <div class="mt-4 p-3 bg-white dark:bg-gray-900 rounded-lg text-xs text-gray-700 dark:text-gray-300">
                    <strong>How it works:</strong> Your PC will check for pending invoices every 30 seconds, submit them to PRA using your local Pakistani IP, and report results back to TaxNest. No relay, no proxy, no PC dependency on TaxNest's servers.
                </div>
            </div>
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
</x-app-layout>
