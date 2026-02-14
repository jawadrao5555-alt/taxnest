<x-app-layout>
    <div class="py-8" x-data="fbrSettingsPage()">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">FBR Integration Settings</h2>
            </div>

            <div x-show="saveMessage" x-cloak x-transition class="mb-4 p-4 rounded-lg text-sm"
                :class="saveSuccess ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700'"
                x-text="saveMessage"></div>

            <form @submit.prevent="saveSettings()" class="space-y-6">

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Company FBR Details</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">FBR Registration No</label>
                            <input type="text" x-model="form.fbr_registration_no" placeholder="e.g. 1234567890123" class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">FBR Business Name</label>
                            <input type="text" x-model="form.fbr_business_name" class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">Active Environment</h3>
                    <p class="text-sm text-gray-500 mb-4">Select which environment to use for FBR submissions.</p>
                    <div class="flex gap-4">
                        <label class="flex-1 flex items-center gap-3 px-4 py-3 rounded-lg border-2 cursor-pointer transition"
                            :class="form.fbr_environment === 'sandbox' ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                            <input type="radio" value="sandbox" x-model="form.fbr_environment" class="text-amber-500 focus:ring-amber-500">
                            <div>
                                <span class="font-medium text-gray-800 dark:text-gray-200">Sandbox</span>
                                <p class="text-xs text-gray-500">Test environment</p>
                            </div>
                        </label>
                        <label class="flex-1 flex items-center gap-3 px-4 py-3 rounded-lg border-2 cursor-pointer transition"
                            :class="form.fbr_environment === 'production' ? 'border-red-400 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                            <input type="radio" value="production" x-model="form.fbr_environment" class="text-red-500 focus:ring-red-500">
                            <div>
                                <span class="font-medium text-gray-800 dark:text-gray-200">Production</span>
                                <p class="text-xs text-gray-500">Live FBR PRAL</p>
                            </div>
                        </label>
                    </div>

                    <template x-if="form.fbr_environment === 'production' && originalEnv !== 'production'">
                        <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <p class="text-sm text-red-700 font-medium mb-2">Switching to Production requires confirmation</p>
                            <p class="text-xs text-red-600 mb-3">All invoices will be submitted to the live FBR PRAL system. This action cannot be undone for submitted invoices.</p>
                            <label class="block text-sm font-medium text-red-700 mb-1">Type CONFIRM to proceed</label>
                            <input type="text" x-model="confirmText" placeholder="Type CONFIRM" class="w-full rounded-lg border-red-300 shadow-sm focus:ring-red-500 focus:border-red-500">
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
                                <input type="text" x-model="form.fbr_sandbox_url" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-amber-500 focus:border-amber-500 text-sm font-mono" placeholder="https://gw.fbr.gov.pk/...">
                                <span class="text-xs text-gray-400 whitespace-nowrap">POST URL</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Default: https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb</p>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sandbox Token</label>
                            <input type="password" x-model="form.fbr_sandbox_token" placeholder="Enter sandbox API token" class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-amber-500 focus:border-amber-500">
                        </div>

                        <div class="p-3 rounded-lg" :class="hasSandboxToken ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border border-gray-200'">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full" :class="hasSandboxToken ? 'bg-green-500' : 'bg-gray-400'"></span>
                                <span class="text-sm font-medium" :class="hasSandboxToken ? 'text-green-700' : 'text-gray-600'" x-text="hasSandboxToken ? 'Token Configured' : 'No Token Set'"></span>
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
                                <input type="text" x-model="form.fbr_production_url" class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-red-500 focus:border-red-500 text-sm font-mono" placeholder="https://gw.fbr.gov.pk/...">
                                <span class="text-xs text-gray-400 whitespace-nowrap">POST URL</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Default: https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata</p>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Production Token</label>
                            <input type="password" x-model="form.fbr_production_token" placeholder="Enter production API token" class="w-full rounded-lg border-gray-300 dark:border-gray-700 shadow-sm focus:ring-red-500 focus:border-red-500">
                        </div>

                        <div class="p-3 rounded-lg" :class="hasProductionToken ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border border-gray-200'">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full" :class="hasProductionToken ? 'bg-green-500' : 'bg-gray-400'"></span>
                                <span class="text-sm font-medium" :class="hasProductionToken ? 'text-green-700' : 'text-gray-600'" x-text="hasProductionToken ? 'Token Configured' : 'No Token Set'"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Connection Health</h3>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold"
                            :class="form.fbr_environment === 'sandbox' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800'"
                            x-text="form.fbr_environment === 'sandbox' ? 'Sandbox Mode' : 'Production Mode'"></span>
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
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Connection Status</p>
                            <div class="flex items-center space-x-2">
                                <span class="inline-block w-3 h-3 rounded-full"
                                    :class="{
                                        'bg-green-500': connStatus === 'green',
                                        'bg-red-500': connStatus === 'red',
                                        'bg-gray-400': connStatus !== 'green' && connStatus !== 'red'
                                    }"></span>
                                <span class="text-sm font-semibold" x-text="connStatus === 'green' ? 'Healthy' : (connStatus === 'red' ? 'Unhealthy' : 'Unknown')"></span>
                            </div>
                        </div>
                    </div>
                    <div x-show="testMessage" x-cloak x-transition class="mb-4 p-3 rounded-lg text-sm"
                        :class="connStatus === 'green' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200'"
                        x-text="testMessage"></div>
                    <button type="button" @click="testConn()" :disabled="testing" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition shadow-sm disabled:opacity-50">
                        <svg x-show="testing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        Test Connection
                    </button>
                </div>

                <div class="border-2 border-emerald-200 dark:border-emerald-700 rounded-xl p-6 bg-emerald-50 dark:bg-emerald-900/20">
                    <h3 class="text-lg font-semibold text-emerald-900 dark:text-emerald-300 mb-3">Sandbox Testing Panel</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Test your FBR integration without submitting real invoices. Available in Sandbox mode only.</p>

                    <template x-if="form.fbr_environment !== 'sandbox'">
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                            <p class="text-sm text-amber-700">Switch to Sandbox environment to access testing tools.</p>
                        </div>
                    </template>

                    <template x-if="form.fbr_environment === 'sandbox'">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <button type="button" @click="runSandboxTest('ping')" :disabled="sandboxRunning" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Ping Endpoint</p>
                                <p class="text-xs text-gray-500">Check if FBR API is reachable</p>
                            </button>
                            <button type="button" @click="runSandboxTest('token')" :disabled="sandboxRunning" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Validate Token</p>
                                <p class="text-xs text-gray-500">Verify token is valid</p>
                            </button>
                            <button type="button" @click="runSandboxTest('payload')" :disabled="sandboxRunning" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Test Payload</p>
                                <p class="text-xs text-gray-500">Validate sample payload format</p>
                            </button>
                            <button type="button" @click="runSandboxTest('config')" :disabled="sandboxRunning" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Check Config</p>
                                <p class="text-xs text-gray-500">Verify company settings</p>
                            </button>
                            <button type="button" @click="runSandboxTest('dryrun')" :disabled="sandboxRunning" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Dry Run</p>
                                <p class="text-xs text-gray-500">Test invoice submission</p>
                            </button>
                            <button type="button" @click="runSandboxTest('provinces')" :disabled="sandboxRunning" class="p-3 border border-emerald-200 rounded-lg bg-white dark:bg-gray-800 hover:bg-emerald-50 text-left transition">
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-400">Province Mapping</p>
                                <p class="text-xs text-gray-500">Verify province codes</p>
                            </button>
                        </div>
                    </template>

                    <div x-show="sandboxRunning" x-cloak class="mt-3 flex items-center gap-2 text-sm text-emerald-700">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        Running test...
                    </div>

                    <div x-show="sandboxResult" x-cloak x-transition class="mt-3 p-3 rounded-lg text-sm border"
                        :class="sandboxResult?.success ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800'">
                        <p class="font-semibold" x-text="sandboxResult?.title"></p>
                        <p class="mt-1 text-xs" x-text="sandboxResult?.message"></p>
                        <template x-if="sandboxResult?.details">
                            <pre class="mt-2 text-xs bg-white p-2 rounded border overflow-x-auto" x-text="JSON.stringify(sandboxResult?.details, null, 2)"></pre>
                        </template>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="submit" :disabled="saving" class="inline-flex items-center px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition shadow-sm disabled:opacity-50">
                        <svg x-show="saving" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <span x-text="saving ? 'Saving...' : 'Save FBR Settings'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function fbrSettingsPage() {
        var initData = @json($initData);
        return {
            form: {
                fbr_environment: initData.environment,
                fbr_registration_no: initData.registration_no,
                fbr_business_name: initData.business_name,
                fbr_sandbox_url: initData.sandbox_url,
                fbr_production_url: initData.production_url,
                fbr_sandbox_token: initData.sandbox_token,
                fbr_production_token: initData.production_token,
            },
            originalEnv: initData.environment,
            confirmText: '',
            saving: false,
            saveMessage: '',
            saveSuccess: false,
            connStatus: initData.connection_status,
            testing: false,
            testMessage: '',
            sandboxRunning: false,
            sandboxResult: null,
            hasSandboxToken: initData.has_sandbox,
            hasProductionToken: initData.has_production,

            async saveSettings() {
                if (this.form.fbr_environment === 'production' && this.originalEnv !== 'production') {
                    if (this.confirmText !== 'CONFIRM') {
                        this.saveMessage = 'Production switch requires confirmation. Please type CONFIRM.';
                        this.saveSuccess = false;
                        window.scrollTo({top: 0, behavior: 'smooth'});
                        return;
                    }
                }
                this.saving = true;
                this.saveMessage = '';
                try {
                    let res = await fetch('/company/fbr-settings-ajax', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(this.form)
                    });
                    let data = await res.json();
                    if (data.success) {
                        this.saveMessage = data.message || 'FBR settings saved successfully!';
                        this.saveSuccess = true;
                        this.originalEnv = this.form.fbr_environment;
                        this.confirmText = '';
                        this.hasSandboxToken = !!this.form.fbr_sandbox_token;
                        this.hasProductionToken = !!this.form.fbr_production_token;
                        window.scrollTo({top: 0, behavior: 'smooth'});
                        await this.testConn();
                    } else {
                        this.saveMessage = data.message || data.errors ? Object.values(data.errors || {}).flat().join(', ') : 'Failed to save settings.';
                        this.saveSuccess = false;
                        window.scrollTo({top: 0, behavior: 'smooth'});
                    }
                } catch(e) {
                    this.saveMessage = 'Error saving settings. Please try again.';
                    this.saveSuccess = false;
                    window.scrollTo({top: 0, behavior: 'smooth'});
                }
                this.saving = false;
            },

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
            },

            async runSandboxTest(type) {
                this.sandboxRunning = true;
                this.sandboxResult = null;
                try {
                    let res = await fetch('/company/sandbox-test/' + type, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        }
                    });
                    this.sandboxResult = await res.json();
                } catch(e) {
                    this.sandboxResult = { success: false, title: 'Error', message: 'Test failed. Please try again.' };
                }
                this.sandboxRunning = false;
            }
        }
    }
    </script>
</x-app-layout>
