<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">FBR Integration Settings</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{{ session('error') }}</div>
            @endif

            <form method="POST" action="/company/fbr-settings" x-data="fbrSettings()" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">FBR Registration No</label>
                    <input type="text" name="fbr_registration_no" value="{{ old('fbr_registration_no', $company->fbr_registration_no) }}" placeholder="e.g. 1234567890123" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">FBR Business Name</label>
                    <input type="text" name="fbr_business_name" value="{{ old('fbr_business_name', $company->fbr_business_name) }}" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <hr class="border-gray-200">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Environment</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 px-4 py-3 rounded-lg border-2 cursor-pointer transition"
                            :class="environment === 'sandbox' ? 'border-amber-400 bg-amber-50' : 'border-gray-200 hover:border-gray-300'">
                            <input type="radio" name="fbr_environment" value="sandbox" x-model="environment" class="text-amber-500 focus:ring-amber-500">
                            <div>
                                <span class="font-medium text-gray-800">Sandbox</span>
                                <p class="text-xs text-gray-500">Test environment for development</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-2 px-4 py-3 rounded-lg border-2 cursor-pointer transition"
                            :class="environment === 'production' ? 'border-red-400 bg-red-50' : 'border-gray-200 hover:border-gray-300'">
                            <input type="radio" name="fbr_environment" value="production" x-model="environment" class="text-red-500 focus:ring-red-500">
                            <div>
                                <span class="font-medium text-gray-800">Production</span>
                                <p class="text-xs text-gray-500">Live FBR PRAL system</p>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sandbox Token</label>
                    <input type="password" name="fbr_sandbox_token" value="{{ old('fbr_sandbox_token', $sandboxToken) }}" placeholder="Enter sandbox API token" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="text-xs text-gray-400 mt-1">Used when environment is set to Sandbox</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Production Token</label>
                    <input type="password" name="fbr_production_token" value="{{ old('fbr_production_token', $productionToken) }}" placeholder="Enter production API token" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="text-xs text-gray-400 mt-1">Used when environment is set to Production</p>
                </div>

                <template x-if="environment === 'production' && originalEnv !== 'production'">
                    <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                        <p class="text-sm text-red-700 font-medium mb-2">Switching to Production requires confirmation</p>
                        <p class="text-xs text-red-600 mb-3">All invoices will be submitted to the live FBR PRAL system. This action cannot be undone for submitted invoices.</p>
                        <label class="block text-sm font-medium text-red-700 mb-1">Type CONFIRM to proceed</label>
                        <input type="text" name="confirm_production" placeholder="Type CONFIRM" class="w-full rounded-lg border-red-300 shadow-sm focus:ring-red-500 focus:border-red-500">
                    </div>
                </template>

                <hr class="border-gray-200">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Token Expiry Date</label>
                    <input type="date" name="token_expiry_date" value="{{ old('token_expiry_date', $company->token_expiry_date ? \Carbon\Carbon::parse($company->token_expiry_date)->format('Y-m-d') : '') }}" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="text-xs text-gray-400 mt-1">Set when your FBR token expires. You'll receive notifications 48 hours before expiry.</p>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">Save FBR Settings</button>
                </div>
            </form>

            <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-100 p-6" x-data="tokenHealth()">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Token Health</h3>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Token Expiry</p>
                        <p class="text-sm font-semibold text-gray-900">
                            @if($company->token_expiry_date)
                                {{ \Carbon\Carbon::parse($company->token_expiry_date)->format('d M Y') }}
                                @if(\Carbon\Carbon::parse($company->token_expiry_date)->isPast())
                                    <span class="text-red-600 text-xs ml-1">(Expired)</span>
                                @elseif(\Carbon\Carbon::parse($company->token_expiry_date)->diffInHours(now()) <= 48)
                                    <span class="text-amber-600 text-xs ml-1">(Expiring Soon)</span>
                                @endif
                            @else
                                Not Set
                            @endif
                        </p>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Last Successful Submission</p>
                        <p class="text-sm font-semibold text-gray-900">
                            @if($company->last_successful_submission)
                                {{ \Carbon\Carbon::parse($company->last_successful_submission)->format('d M Y, h:i A') }}
                            @else
                                No submissions yet
                            @endif
                        </p>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4">
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

                <div x-show="testMessage" x-cloak class="mb-4 p-3 rounded-lg text-sm"
                    :class="connectionStatus === 'green' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200'"
                    x-text="testMessage"></div>

                <button type="button" @click="testConn()" :disabled="testing" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition disabled:opacity-50">
                    <svg x-show="testing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    Test Connection
                </button>
            </div>
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
            connectionStatus: '{{ $company->fbr_connection_status ?? "unknown" }}',
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
                    this.connectionStatus = data.status;
                    this.testMessage = data.message;
                } catch(e) {
                    this.connectionStatus = 'red';
                    this.testMessage = 'Connection test failed. Please try again.';
                }
                this.testing = false;
            }
        }
    }
    </script>
</x-app-layout>
