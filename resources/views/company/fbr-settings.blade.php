<x-app-layout>
    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Settings</h2>
            </div>

            <div class="flex gap-1 mb-6 bg-gray-100 dark:bg-gray-800 rounded-lg p-1">
                <a href="/company/profile" class="flex-1 text-center px-4 py-2.5 rounded-md text-sm font-semibold transition text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">Business Profile</a>
                <a href="/company/fbr-settings" class="flex-1 text-center px-4 py-2.5 rounded-md text-sm font-semibold transition bg-white dark:bg-gray-900 text-emerald-700 dark:text-emerald-400 shadow-sm">FBR Settings</a>
            </div>

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{{ session('error') }}</div>
            @endif

            <form method="POST" action="/company/fbr-settings" x-data="fbrSettings()" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Company FBR Details</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">FBR Registration No</label>
                            <input type="text" name="fbr_registration_no" value="{{ old('fbr_registration_no', $company->fbr_registration_no) }}" placeholder="e.g. 1234567890123" class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">FBR Business Name</label>
                            <input type="text" name="fbr_business_name" value="{{ old('fbr_business_name', $company->fbr_business_name) }}" class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">Active Environment</h3>
                    <p class="text-sm text-gray-500 mb-4">Select which environment to use for FBR submissions.</p>
                    <div class="flex gap-4">
                        <label class="flex-1 flex items-center gap-3 px-4 py-3 rounded-lg border-2 cursor-pointer transition"
                            :class="environment === 'sandbox' ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                            <input type="radio" name="fbr_environment" value="sandbox" x-model="environment" class="text-amber-500 focus:ring-amber-500">
                            <div>
                                <span class="font-medium text-gray-800 dark:text-gray-200">Sandbox</span>
                                <p class="text-xs text-gray-500">Test environment</p>
                            </div>
                        </label>
                        <label class="flex-1 flex items-center gap-3 px-4 py-3 rounded-lg border-2 cursor-pointer transition"
                            :class="environment === 'production' ? 'border-red-400 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                            <input type="radio" name="fbr_environment" value="production" x-model="environment" class="text-red-500 focus:ring-red-500">
                            <div>
                                <span class="font-medium text-gray-800 dark:text-gray-200">Production</span>
                                <p class="text-xs text-gray-500">Live FBR PRAL</p>
                            </div>
                        </label>
                    </div>

                    <template x-if="environment === 'production' && originalEnv !== 'production'">
                        <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <p class="text-sm text-red-700 font-medium mb-2">Switching to Production requires confirmation</p>
                            <p class="text-xs text-red-600 mb-3">All invoices will be submitted to the live FBR PRAL system. This action cannot be undone for submitted invoices.</p>
                            <label class="block text-sm font-medium text-red-700 mb-1">Type CONFIRM to proceed</label>
                            <input type="text" name="confirm_production" placeholder="Type CONFIRM" class="w-full rounded-lg border-red-300 shadow-sm focus:ring-red-500 focus:border-red-500">
                        </div>
                    </template>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border-2 border-amber-200 dark:border-amber-700 p-6">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800">SANDBOX</span>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Sandbox Configuration</h3>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sandbox API Endpoint</label>
                            <div class="flex items-center gap-2">
                                <input type="text" name="fbr_sandbox_url" value="{{ old('fbr_sandbox_url', $company->fbr_sandbox_url ?? 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb') }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-amber-500 focus:border-amber-500 text-sm font-mono" placeholder="https://gw.fbr.gov.pk/...">
                                <span class="text-xs text-gray-400 whitespace-nowrap">POST URL</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Default: https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb</p>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sandbox Token</label>
                            <input type="password" name="fbr_sandbox_token" value="{{ old('fbr_sandbox_token', $sandboxToken) }}" placeholder="Enter sandbox API token" class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-amber-500 focus:border-amber-500">
                        </div>

                        <div class="p-3 rounded-lg {{ $sandboxToken ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border border-gray-200' }}">
                            <div class="flex items-center gap-2">
                                @if($sandboxToken)
                                <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                                <span class="text-sm font-medium text-green-700">Token Configured</span>
                                @else
                                <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
                                <span class="text-sm font-medium text-gray-600">No Token Set</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border-2 border-red-200 dark:border-red-700 p-6">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-800">PRODUCTION</span>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Production Configuration</h3>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Production API Endpoint</label>
                            <div class="flex items-center gap-2">
                                <input type="text" name="fbr_production_url" value="{{ old('fbr_production_url', $company->fbr_production_url ?? 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata') }}" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-red-500 focus:border-red-500 text-sm font-mono" placeholder="https://gw.fbr.gov.pk/...">
                                <span class="text-xs text-gray-400 whitespace-nowrap">POST URL</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Default: https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata</p>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Production Token</label>
                            <input type="password" name="fbr_production_token" value="{{ old('fbr_production_token', $productionToken) }}" placeholder="Enter production API token" class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-red-500 focus:border-red-500">
                        </div>

                        <div class="p-3 rounded-lg {{ $productionToken ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border border-gray-200' }}">
                            <div class="flex items-center gap-2">
                                @if($productionToken)
                                <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                                <span class="text-sm font-medium text-green-700">Token Configured</span>
                                @else
                                <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
                                <span class="text-sm font-medium text-gray-600">No Token Set</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Connection Health</h3>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold"
                            :class="environment === 'sandbox' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800'"
                            x-text="environment === 'sandbox' ? 'Sandbox Mode' : 'Production Mode'"></span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Last Successful Submission</p>
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                @if($company->last_successful_submission)
                                    {{ \Carbon\Carbon::parse($company->last_successful_submission)->format('d M Y, h:i A') }}
                                @else
                                    No submissions yet
                                @endif
                            </p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4" x-data="{ connectionStatus: '{{ $company->fbr_connection_status ?? 'unknown' }}' }">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Connection Status</p>
                            <div class="flex items-center space-x-2">
                                <span class="inline-block w-3 h-3 rounded-full"
                                    :class="{
                                        'bg-green-500': connectionStatus === 'green',
                                        'bg-red-500': connectionStatus === 'red',
                                        'bg-gray-400': connectionStatus !== 'green' && connectionStatus !== 'red'
                                    }"></span>
                                <span class="text-sm font-semibold" x-text="connectionStatus === 'green' ? 'Healthy' : (connectionStatus === 'red' ? 'Unhealthy' : 'Unknown')"></span>
                            </div>
                        </div>
                    </div>
                    <div x-data="tokenHealth()">
                        <div x-show="testMessage" x-cloak class="mb-4 p-3 rounded-lg text-sm"
                            :class="connStatus === 'green' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200'"
                            x-text="testMessage"></div>
                        <button type="button" @click="testConn()" :disabled="testing" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition shadow-sm disabled:opacity-50">
                            <svg x-show="testing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Test Connection
                        </button>
                    </div>
                </div>

                <div class="border-2 border-emerald-200 dark:border-emerald-700 rounded-xl p-6 bg-emerald-50 dark:bg-emerald-900/20" x-data="sandboxPanel()">
                    <h3 class="text-lg font-semibold text-emerald-900 dark:text-emerald-300 mb-3">Sandbox Testing Panel</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Test your FBR integration without submitting real invoices. Available in Sandbox mode only.</p>

                    <template x-if="environment !== 'sandbox'">
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                            <p class="text-sm text-amber-700">Switch to Sandbox environment to access testing tools.</p>
                        </div>
                    </template>

                    <template x-if="environment === 'sandbox'">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <button type="button" @click="runTest('ping')" :disabled="running" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Ping Endpoint</p>
                                <p class="text-xs text-gray-500">Check if FBR API is reachable</p>
                            </button>
                            <button type="button" @click="runTest('token')" :disabled="running" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Validate Token</p>
                                <p class="text-xs text-gray-500">Verify token is valid</p>
                            </button>
                            <button type="button" @click="runTest('payload')" :disabled="running" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Test Payload</p>
                                <p class="text-xs text-gray-500">Validate sample payload format</p>
                            </button>
                            <button type="button" @click="runTest('config')" :disabled="running" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Check Config</p>
                                <p class="text-xs text-gray-500">Verify company settings</p>
                            </button>
                            <button type="button" @click="runTest('dryrun')" :disabled="running" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Dry Run</p>
                                <p class="text-xs text-gray-500">Test invoice submission</p>
                            </button>
                            <button type="button" @click="runTest('provinces')" :disabled="running" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Province Mapping</p>
                                <p class="text-xs text-gray-500">Verify province codes</p>
                            </button>
                        </div>
                    </template>

                    <div x-show="running" x-cloak class="mt-3 flex items-center gap-2 text-sm text-emerald-700">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        Running test...
                    </div>

                    <div x-show="testResult" x-cloak class="mt-3 p-3 rounded-lg text-sm border"
                        :class="testResult?.success ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'">
                        <p class="font-semibold" x-text="testResult?.title"></p>
                        <p class="mt-1 text-xs" x-text="testResult?.message"></p>
                        <template x-if="testResult?.details">
                            <pre class="mt-2 text-xs bg-white p-2 rounded border overflow-x-auto" x-text="JSON.stringify(testResult?.details, null, 2)"></pre>
                        </template>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition shadow-sm">Save FBR Settings</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function fbrSettings() {
        return {
            environment: '{{ $company->fbr_environment ?? "sandbox" }}',
            originalEnv: '{{ $company->fbr_environment ?? "sandbox" }}'
        }
    }
    function tokenHealth() {
        return {
            connStatus: '{{ $company->fbr_connection_status ?? "unknown" }}',
            testing: false,
            testMessage: '',
            async testConn() {
                this.testing = true;
                this.testMessage = '';
                try {
                    let res = await fetch('/company/test-connection', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        }
                    });
                    let data = await res.json();
                    this.connStatus = data.status;
                    this.testMessage = data.message;
                } catch(e) {
                    this.connStatus = 'red';
                    this.testMessage = 'Connection test failed. Please try again.';
                }
                this.testing = false;
            }
        }
    }
    function sandboxPanel() {
        return {
            running: false,
            testResult: null,
            environment: '{{ $company->fbr_environment ?? "sandbox" }}',
            async runTest(type) {
                this.running = true;
                this.testResult = null;
                try {
                    let res = await fetch('/company/sandbox-test/' + type, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        }
                    });
                    this.testResult = await res.json();
                } catch(e) {
                    this.testResult = { success: false, title: 'Error', message: 'Test failed. Please try again.' };
                }
                this.running = false;
            }
        }
    }
    </script>
</x-app-layout>
