<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <nav class="flex items-center text-xs text-gray-400 mb-1">
                    <a href="{{ route('dashboard') }}" class="hover:text-emerald-600 transition">Dashboard</a>
                    <svg class="w-3.5 h-3.5 mx-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    <a href="/invoices?tab={{ in_array($invoice->status, ['draft', 'failed']) ? ($invoice->status === 'failed' ? 'failed' : 'draft') : 'completed' }}" class="hover:text-emerald-600 transition">Invoices</a>
                    <svg class="w-3.5 h-3.5 mx-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    <span class="text-gray-600 dark:text-gray-300 font-medium">{{ $invoice->display_invoice_number }}</span>
                </nav>
                <div class="flex items-center space-x-3">
                    <a href="/invoices?tab={{ in_array($invoice->status, ['draft', 'failed']) ? ($invoice->status === 'failed' ? 'failed' : 'draft') : 'completed' }}" class="inline-flex items-center text-gray-500 dark:text-gray-400 hover:text-emerald-600 transition text-sm">
                        <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back to Invoices
                    </a>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Invoice {{ $invoice->display_invoice_number }}</h2>
                </div>
            </div>
            <div class="flex items-center flex-wrap gap-2" id="actionButtonsBlock">
                @if($invoice->status === 'draft')
                {{-- DRAFT: Edit, Verify Integrity, Submit to FBR, Duplicate, Delete, WHT --}}
                <a href="/invoice/{{ $invoice->id }}/edit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Edit</a>
                <form method="POST" action="{{ route('invoice.verify', $invoice->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Verify Integrity
                    </button>
                </form>
                <div x-data="fbrSubmitEngine()" x-ref="submitRoot">
                    <button @click="showSubmitModal = true" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700 transition">Submit to FBR</button>
                    <div x-show="showSubmitModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @keydown.escape.window="if(showSubmitModal && !submitting) showSubmitModal = false">
                        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-xl max-w-md w-full mx-4 p-6">
                            <h3 class="text-lg font-bold text-gray-900 mb-4">Submit to FBR</h3>
                            <div class="flex gap-3 mb-4">
                                <label class="flex-1 flex items-center gap-2 px-3 py-2 rounded-lg border-2 cursor-pointer transition text-sm"
                                    :class="submitEnv === 'sandbox' ? 'border-amber-400 bg-amber-50' : 'border-gray-200 dark:border-gray-700'">
                                    <input type="radio" value="sandbox" x-model="submitEnv" class="text-amber-500" :disabled="submitting">
                                    <span class="font-medium">Sandbox</span>
                                </label>
                                <label class="flex-1 flex items-center gap-2 px-3 py-2 rounded-lg border-2 cursor-pointer transition text-sm"
                                    :class="submitEnv === 'production' ? 'border-red-400 bg-red-50' : 'border-gray-200 dark:border-gray-700'">
                                    <input type="radio" value="production" x-model="submitEnv" class="text-red-500" :disabled="submitting">
                                    <span class="font-medium">Production</span>
                                </label>
                            </div>
                            <div class="space-y-4">
                                <button @click="doSubmit('smart')" :disabled="submitting" class="w-full p-4 border-2 border-emerald-200 rounded-lg hover:bg-emerald-50 text-left disabled:opacity-50 disabled:cursor-not-allowed">
                                    <p class="font-semibold text-emerald-700" x-text="submitting && submitMode === 'smart' ? 'Submitting to FBR...' : 'Smart Mode (Recommended)'"></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400" x-show="!(submitting && submitMode === 'smart')">Runs compliance scoring, blocks CRITICAL risk invoices</p>
                                    <div x-show="submitting && submitMode === 'smart'" x-cloak class="flex items-center gap-2 mt-1">
                                        <svg class="animate-spin h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                        <p class="text-xs text-emerald-600 animate-pulse">Please wait, sending to FBR...</p>
                                    </div>
                                </button>
                                <div>
                                    <button @click="showOverride = !showOverride" :disabled="submitting" class="w-full p-4 border-2 border-orange-200 rounded-lg hover:bg-orange-50 text-left disabled:opacity-50">
                                        <p class="font-semibold text-orange-700">Direct MIS Mode</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Skips compliance check - requires override reason</p>
                                    </button>
                                    <div x-show="showOverride" class="mt-3">
                                        <textarea x-model="overrideReason" :disabled="submitting" minlength="10" placeholder="Enter override reason (min 10 characters)..." class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                                        <button @click="doSubmit('direct_mis')" :disabled="submitting || overrideReason.length < 10" class="mt-2 w-full px-4 py-2 bg-orange-600 text-white rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                                            <span x-show="!(submitting && submitMode === 'direct_mis')">Submit with Override</span>
                                            <span x-show="submitting && submitMode === 'direct_mis'" x-cloak class="flex items-center justify-center gap-2">
                                                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                                Submitting...
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button @click="showSubmitModal = false" :disabled="submitting" class="mt-4 w-full text-center text-sm text-gray-500 hover:text-gray-700 dark:text-gray-300 disabled:opacity-30">Cancel</button>
                        </div>
                    </div>
                </div>
                @if(in_array(auth()->user()->role, ['company_admin', 'employee']))
                <form method="POST" action="{{ route('invoice.duplicate', $invoice->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-cyan-600 text-white rounded-lg text-sm font-medium hover:bg-cyan-700 transition" onclick="return confirm('Create a duplicate of this invoice as a new draft?')">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
                        Duplicate
                    </button>
                </form>
                @endif
                <form method="POST" action="{{ route('invoice.destroy', $invoice->id) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition" onclick="return confirm('Are you sure you want to delete this draft invoice? This action cannot be undone.')">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete
                    </button>
                </form>
                <div x-data="draftPdfHandler()" class="inline-flex items-center gap-2">
                    <template x-if="whtLocked">
                        <div class="inline-flex items-center gap-2">
                            <button @click="openPdfPopup()" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition">
                                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                Download / Print
                            </button>
                            <span class="inline-flex items-center px-3 py-2 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg text-sm font-medium">
                                <svg class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                WHT <span x-text="selectedWht + '%'"></span> Locked
                            </span>
                        </div>
                    </template>
                    <template x-if="!whtLocked">
                        <button @click="showWhtFirst = true" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition">
                            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download PDF
                        </button>
                    </template>
                    <div x-show="showWhtFirst" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background-color: rgba(0,0,0,0.4);">
                        <div @click.away="showWhtFirst = false" class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 p-6 w-96 max-w-[90vw]">
                            <div class="flex items-center justify-between mb-4">
                                <p class="text-base font-bold text-gray-800 dark:text-gray-100">Select WHT Rate</p>
                                <button @click="showWhtFirst = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-400 text-xl leading-none">&times;</button>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">WHT rate will be locked and applied to this invoice. PDF will open after saving.</p>
                            <div class="space-y-2 mb-4">
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 0 ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="0" x-model.number="selectedWht" class="text-emerald-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">No WHT (0%)</span>
                                </label>
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 0.5 ? 'border-amber-400 bg-amber-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="0.5" x-model.number="selectedWht" class="text-amber-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 0.5%</span>
                                </label>
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 1 ? 'border-blue-400 bg-blue-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="1" x-model.number="selectedWht" class="text-blue-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 1%</span>
                                </label>
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 2 ? 'border-orange-400 bg-orange-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="2" x-model.number="selectedWht" class="text-orange-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 2%</span>
                                </label>
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 2.5 ? 'border-red-400 bg-red-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="2.5" x-model.number="selectedWht" class="text-red-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 2.5%</span>
                                </label>
                            </div>
                            <button @click="saveWhtAndOpen()" :disabled="savingWht" class="w-full px-4 py-2.5 bg-emerald-600 text-white rounded-lg text-sm font-bold hover:bg-emerald-700 transition disabled:opacity-50">
                                <span x-show="!savingWht">Lock WHT & Open PDF</span>
                                <span x-show="savingWht" x-cloak>Saving...</span>
                            </button>
                        </div>
                    </div>
                </div>
                <script>
                function draftPdfHandler() {
                    return {
                        showWhtFirst: false,
                        selectedWht: {{ $invoice->wht_rate ?? 0 }},
                        savingWht: false,
                        whtLocked: {{ $invoice->wht_locked ? 'true' : 'false' }},
                        openPdfPopup() {
                            openInlinePdfPopup();
                        },
                        async saveWhtAndOpen() {
                            this.savingWht = true;
                            try {
                                const body = new FormData();
                                body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
                                body.append('wht_rate', this.selectedWht);
                                const res = await fetch('/invoice/{{ $invoice->id }}/update-wht-ajax', {
                                    method: 'POST',
                                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    body: body
                                });
                                const data = await res.json();
                                if (data.status === 'ok') {
                                    this.whtLocked = true;
                                    this.showWhtFirst = false;
                                    this.savingWht = false;
                                    openInlinePdfPopup();
                                    return;
                                } else {
                                    alert(data.message || 'Failed to save WHT rate');
                                }
                            } catch(e) {
                                alert('Network error saving WHT rate');
                            }
                            this.savingWht = false;
                        }
                    };
                }
                </script>
                @elseif($invoice->status === 'locked' && $invoice->fbr_status === 'production')
                {{-- LOCKED+PRODUCTION: PDF with WHT lock, Print, Duplicate, WhatsApp --}}
                <div x-data="lockedPdfHandler()" class="inline-flex items-center gap-2">
                    <template x-if="whtLocked">
                        <div class="inline-flex items-center gap-2">
                            <button @click="openPdfPopup()" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition">
                                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                Download / Print
                            </button>
                            <span class="inline-flex items-center px-2.5 py-1.5 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg text-xs font-medium">
                                <svg class="w-3 h-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                WHT <span x-text="lockedWhtRate"></span>%
                            </span>
                        </div>
                    </template>
                    <template x-if="!whtLocked">
                        <button @click="showWhtFirst = true" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition">
                            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download / Print
                        </button>
                    </template>

                    <div x-show="showWhtFirst" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background-color: rgba(0,0,0,0.4);">
                        <div @click.away="showWhtFirst = false" class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 p-6 w-96 max-w-[90vw]">
                            <div class="flex items-center justify-between mb-4">
                                <p class="text-base font-bold text-gray-800 dark:text-gray-100">Select WHT Rate</p>
                                <button @click="showWhtFirst = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-400 text-xl leading-none">&times;</button>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">WHT rate will be locked and applied to this invoice permanently.</p>
                            <div class="space-y-2 mb-4">
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 0 ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="0" x-model.number="selectedWht" class="text-emerald-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">No WHT (0%)</span>
                                </label>
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 0.5 ? 'border-amber-400 bg-amber-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="0.5" x-model.number="selectedWht" class="text-amber-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 0.5%</span>
                                </label>
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 1 ? 'border-blue-400 bg-blue-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="1" x-model.number="selectedWht" class="text-blue-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 1%</span>
                                </label>
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 2 ? 'border-orange-400 bg-orange-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="2" x-model.number="selectedWht" class="text-orange-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 2%</span>
                                </label>
                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                    :class="selectedWht == 2.5 ? 'border-red-400 bg-red-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                    <input type="radio" value="2.5" x-model.number="selectedWht" class="text-red-500">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 2.5%</span>
                                </label>
                            </div>
                            <button @click="saveWhtAndOpen()" :disabled="savingWht" class="w-full px-4 py-2.5 bg-emerald-600 text-white rounded-lg text-sm font-bold hover:bg-emerald-700 transition disabled:opacity-50">
                                <span x-show="!savingWht">Lock WHT & Open PDF</span>
                                <span x-show="savingWht" x-cloak>Saving...</span>
                            </button>
                        </div>
                    </div>

                </div>
                <script>
                function lockedPdfHandler() {
                    return {
                        whtLocked: {{ $invoice->wht_locked ? 'true' : 'false' }},
                        lockedWhtRate: {{ $invoice->wht_rate ?? 0 }},
                        showWhtFirst: false,
                        selectedWht: {{ $invoice->wht_rate ?? 0 }},
                        savingWht: false,
                        openPdfPopup() {
                            openInlinePdfPopup();
                        },
                        async saveWhtAndOpen() {
                            this.savingWht = true;
                            try {
                                const body = new FormData();
                                body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
                                body.append('wht_rate', this.selectedWht);
                                const res = await fetch('/invoice/{{ $invoice->id }}/update-wht-ajax', {
                                    method: 'POST',
                                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    body: body
                                });
                                const data = await res.json();
                                if (data.status === 'ok') {
                                    this.whtLocked = true;
                                    this.lockedWhtRate = this.selectedWht;
                                    this.showWhtFirst = false;
                                    this.savingWht = false;
                                    openInlinePdfPopup();
                                    return;
                                } else {
                                    alert(data.message || 'Failed to save WHT rate');
                                }
                            } catch(e) {
                                alert('Network error saving WHT rate');
                            }
                            this.savingWht = false;
                        }
                    };
                }
                </script>
                @if(in_array(auth()->user()->role, ['company_admin', 'employee']))
                <form method="POST" action="{{ route('invoice.duplicate', $invoice->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-cyan-600 text-white rounded-lg text-sm font-medium hover:bg-cyan-700 transition" onclick="return confirm('Create a duplicate of this invoice as a new draft?')">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
                        Duplicate
                    </button>
                </form>
                @endif
                @if($invoice->share_uuid)
                <a href="https://wa.me/?text={{ urlencode('Invoice ' . $invoice->display_invoice_number . "\nDownload PDF: " . url('/share/invoice/' . $invoice->share_uuid . '/pdf')) }}" target="_blank" class="inline-flex items-center px-4 py-2 bg-green-500 text-white rounded-lg text-sm font-medium hover:bg-green-600 transition">WhatsApp</a>
                @endif
                @elseif($invoice->status === 'failed')
                {{-- FAILED: Edit & Fix, Retry Submit, Validate, Delete, Duplicate --}}
                <a href="/invoice/{{ $invoice->id }}/edit" class="inline-flex items-center px-4 py-2 bg-amber-500 text-white rounded-lg text-sm font-medium hover:bg-amber-600 transition">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Edit & Fix
                </a>
                <div x-data="{ retrying: false }" class="inline">
                    <button @click="retrySubmitToFbr($el)" :disabled="retrying" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg x-show="!retrying" class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <svg x-show="retrying" x-cloak class="animate-spin w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <span x-text="retrying ? 'Retrying...' : 'Retry Submit'"></span>
                    </button>
                </div>
                <div x-data="fbrValidator()">
                    <button @click="doValidate()" :disabled="validating" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-medium hover:bg-amber-700 transition disabled:opacity-50">
                        <svg x-show="validating" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        Validate FBR Payload
                    </button>
                    <div x-show="validationResult" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="validationResult = null">
                        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-xl max-w-lg w-full mx-4 p-6 max-h-[80vh] overflow-y-auto">
                            <h3 class="text-lg font-bold text-gray-900 mb-4">FBR Payload Validation</h3>
                            <div x-show="validationResult?.status === 'valid'" class="p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
                                <p class="text-sm text-emerald-700 font-medium" x-text="validationResult?.message"></p>
                            </div>
                            <div x-show="validationResult?.status === 'invalid'" class="space-y-2">
                                <div class="p-3 bg-red-50 border border-red-200 rounded-lg">
                                    <p class="text-sm text-red-700 font-medium mb-2">Validation Failed</p>
                                    <template x-for="err in validationResult?.errors || []">
                                        <p class="text-xs text-red-600" x-text="err"></p>
                                    </template>
                                </div>
                            </div>
                            <div x-show="validationResult?.status === 'error'" class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                                <p class="text-sm text-amber-700" x-text="validationResult?.message"></p>
                            </div>
                            <button @click="validationResult = null" class="mt-4 w-full text-center text-sm text-gray-500 hover:text-gray-700 dark:text-gray-300">Close</button>
                        </div>
                    </div>
                </div>
                <script>
                function fbrValidator() {
                    return {
                        validating: false,
                        validationResult: null,
                        async doValidate() {
                            this.validating = true;
                            this.validationResult = null;
                            try {
                                let res = await fetch('/invoice/{{ $invoice->id }}/validate-fbr', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json' }
                                });
                                this.validationResult = await res.json();
                            } catch(e) {
                                this.validationResult = { status: 'error', message: 'Failed to validate. Please try again.' };
                            }
                            this.validating = false;
                        }
                    };
                }
                </script>
                @if(in_array(auth()->user()->role, ['company_admin', 'employee']))
                <form method="POST" action="{{ route('invoice.duplicate', $invoice->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-cyan-600 text-white rounded-lg text-sm font-medium hover:bg-cyan-700 transition" onclick="return confirm('Create a duplicate of this invoice as a new draft?')">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
                        Duplicate
                    </button>
                </form>
                @endif
                <form method="POST" action="{{ route('invoice.destroy', $invoice->id) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 transition" onclick="return confirm('Are you sure you want to delete this failed invoice? This action cannot be undone.')">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete
                    </button>
                </form>
                @else
                {{-- OTHER STATUSES (pending_verification, locked non-production): basic actions --}}
                @if(in_array(auth()->user()->role, ['company_admin', 'employee']))
                <form method="POST" action="{{ route('invoice.duplicate', $invoice->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-cyan-600 text-white rounded-lg text-sm font-medium hover:bg-cyan-700 transition" onclick="return confirm('Create a duplicate of this invoice as a new draft?')">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
                        Duplicate
                    </button>
                </form>
                @endif
                <a href="/invoice/{{ $invoice->id }}/download" target="_blank" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition">Download PDF</a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            @if($invoice->status === 'locked')
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6" x-data="{ editingFbr: false, fbrNum: '{{ $invoice->fbr_invoice_number ?? '' }}' }">
                <div class="flex items-start gap-3">
                    <svg class="w-6 h-6 text-emerald-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-emerald-800">FBR Production - Invoice Locked</p>
                        <div class="mt-1">
                            <template x-if="!editingFbr">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm text-emerald-700">FBR Invoice Number: <strong>{{ $invoice->fbr_invoice_number ?? 'Not set' }}</strong></p>
                                    @if(in_array(auth()->user()->role, ['company_admin', 'super_admin']))
                                    <button @click="editingFbr = true" class="text-xs text-blue-600 hover:text-blue-800 font-medium underline">{{ $invoice->fbr_invoice_number ? 'Edit' : 'Add FBR #' }}</button>
                                    @endif
                                </div>
                            </template>
                            <template x-if="editingFbr">
                                <form method="POST" action="/invoice/{{ $invoice->id }}/update-fbr-number" class="flex items-center gap-2 mt-1">
                                    @csrf
                                    <input type="text" name="fbr_invoice_number" x-model="fbrNum" placeholder="Enter FBR Invoice Number" class="px-3 py-1.5 text-sm border border-emerald-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500 w-72">
                                    <button type="submit" class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-semibold hover:bg-emerald-700 transition">Save</button>
                                    <button type="button" @click="editingFbr = false" class="px-3 py-1.5 bg-gray-200 text-gray-700 dark:text-gray-300 rounded-lg text-xs font-semibold hover:bg-gray-300 transition">Cancel</button>
                                </form>
                            </template>
                        </div>
                        @if($invoice->fbr_submission_date)
                        <p class="text-xs text-emerald-600 mt-1">Submitted: {{ \Carbon\Carbon::parse($invoice->fbr_submission_date)->format('d-M-Y h:i A') }}</p>
                        @endif
                    </div>
                </div>
            </div>
            @endif
            @if($invoice->status === 'pending_verification')
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6">
                <div class="flex items-start gap-3">
                    <svg class="w-6 h-6 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-amber-800">Pending FBR Verification</p>
                        <p class="mt-1 text-sm text-amber-700">FBR returned an ambiguous response. The invoice may have been accepted. Please check the FBR portal to confirm and then update this invoice's status.</p>
                        @if(in_array(auth()->user()->role, ['company_admin', 'super_admin']))
                        <div class="mt-3 space-y-3">
                            <form method="POST" action="/invoice/{{ $invoice->id }}/update-fbr-number" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <div>
                                    <label class="block text-xs font-medium text-amber-800 mb-1">FBR Invoice Number (from portal)</label>
                                    <input type="text" name="fbr_invoice_number" placeholder="e.g. 3620291786117DIA..." class="px-3 py-1.5 text-xs border border-amber-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500 w-64">
                                </div>
                                <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-semibold hover:bg-emerald-700 transition">Confirm & Save FBR #</button>
                            </form>
                            <div class="flex gap-2">
                                <form method="POST" action="/invoice/{{ $invoice->id }}/confirm-fbr" class="inline">
                                    @csrf
                                    <input type="hidden" name="action" value="confirm">
                                    <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-amber-600 text-white rounded-lg text-xs font-semibold hover:bg-amber-700 transition">Confirm Without Number</button>
                                </form>
                                <form method="POST" action="/invoice/{{ $invoice->id }}/confirm-fbr" class="inline">
                                    @csrf
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-semibold hover:bg-red-700 transition">Not on FBR Portal (Reset to Draft)</button>
                                </form>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif
            @if($invoice->status === 'failed')
            @php
                $lastFbrLog = \App\Models\FbrLog::where('invoice_id', $invoice->id)->orderBy('created_at', 'desc')->first();
                $failErrors = [];
                if ($lastFbrLog && $lastFbrLog->response_payload) {
                    $resp = json_decode($lastFbrLog->response_payload, true);
                    if (!empty($resp['errors'])) {
                        $failErrors = is_array($resp['errors']) ? $resp['errors'] : [$resp['errors']];
                    } elseif (!empty($resp['error'])) {
                        $failErrors = [$resp['error']];
                    }
                }
            @endphp
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
                <div class="flex items-start gap-3">
                    <svg class="w-6 h-6 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    <div class="flex-1">
                        <p class="text-sm font-bold text-red-800">FBR Submission Failed</p>
                        @if(count($failErrors) > 0)
                        <ul class="mt-1 text-sm text-red-700 list-disc list-inside space-y-0.5">
                            @foreach($failErrors as $err)
                            <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                        @else
                        <p class="mt-1 text-sm text-red-700">Invoice could not be submitted to FBR. Please fix the issues and retry.</p>
                        @endif
                        <div class="mt-3 flex gap-2">
                            <a href="/invoice/{{ $invoice->id }}/edit" class="inline-flex items-center px-3 py-1.5 bg-amber-500 text-white rounded-lg text-xs font-semibold hover:bg-amber-600 transition">Edit & Fix</a>
                            <form method="POST" action="/invoice/{{ $invoice->id }}/retry" class="inline">
                                @csrf
                                <button type="submit" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-semibold hover:bg-emerald-700 transition">Retry Submit</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            @endif
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden mb-8">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">{{ $invoice->company->name ?? 'Company' }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">NTN: {{ $invoice->company->ntn ?? 'N/A' }}</p>
                            @if($invoice->company->registration_no)
                            <p class="text-xs text-gray-400">Reg #: {{ $invoice->company->registration_no }}</p>
                            @endif
                            @if($invoice->company->address)
                            <p class="text-xs text-gray-400">{{ $invoice->company->address }}@if($invoice->company->city), {{ $invoice->company->city }}@endif</p>
                            @endif
                            @if($invoice->company->phone || $invoice->company->mobile)
                            <p class="text-xs text-gray-400">{{ $invoice->company->mobile ?? $invoice->company->phone }}</p>
                            @endif
                            @if($invoice->company->email)
                            <p class="text-xs text-gray-400">{{ $invoice->company->email }}</p>
                            @endif
                        </div>
                        <div class="text-right" id="statusBadgeBlock">
                            <span id="invoiceStatusBadge" class="inline-flex px-3 py-1 rounded-full text-sm font-bold transition-all duration-200
                                @if($invoice->status === 'draft') bg-gray-200 text-gray-700 dark:text-gray-300
                                @elseif($invoice->status === 'failed') bg-red-100 text-red-800
                                @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                @elseif($invoice->status === 'pending_verification') bg-amber-100 text-amber-800
                                @endif">
                                {{ $invoice->status === 'pending_verification' ? 'Pending Verification' : ucfirst($invoice->status) }}
                            </span>
                            <span id="fbrStatusBadge" class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ml-2 transition-all duration-200
                                @if(!$invoice->fbr_status) hidden @endif
                                @if($invoice->fbr_status === 'production') bg-emerald-100 text-emerald-800
                                @elseif($invoice->fbr_status === 'validated') bg-emerald-100 text-emerald-800
                                @elseif($invoice->fbr_status === 'failed' || $invoice->fbr_status === 'validation_failed') bg-red-100 text-red-800
                                @elseif($invoice->fbr_status === 'sandbox') bg-amber-100 text-amber-800
                                @else bg-gray-100 text-gray-800 dark:text-gray-100 @endif">
                                FBR: {{ $invoice->fbr_status === 'production' ? 'Production' : ($invoice->fbr_status === 'validation_failed' ? 'Validation Failed' : ucfirst($invoice->fbr_status ?? '')) }}
                            </span>
                            @if($invoice->status === 'locked' && $invoice->integrity_hash)
                            <p class="text-xs text-green-600 mt-1">SHA256 Protected</p>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <h4 class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Buyer Details</h4>
                            <p class="text-sm font-semibold text-gray-900">{{ $invoice->buyer_name }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">NTN: {{ $invoice->buyer_ntn ?: 'N/A' }}</p>
                            @if($invoice->buyer_cnic)
                            <p class="text-sm text-gray-600 dark:text-gray-400">CNIC: {{ $invoice->buyer_cnic }}</p>
                            @endif
                            <p class="text-sm text-gray-600 dark:text-gray-400">Address: {{ $invoice->buyer_address }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Registration: <span class="font-medium {{ $invoice->buyer_registration_type === 'Registered' ? 'text-green-700' : 'text-gray-600 dark:text-gray-400' }}">{{ $invoice->buyer_registration_type ?? 'N/A' }}</span></p>
                            @if($invoice->destination_province)
                            <p class="text-sm text-gray-600 dark:text-gray-400">Destination: {{ $invoice->destination_province }}</p>
                            @endif
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <h4 class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Invoice Details</h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Internal #: <span class="font-semibold text-gray-900">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-' . $invoice->id }}</span></p>
@if($invoice->fbr_invoice_number)
<p class="text-sm text-gray-600 dark:text-gray-400">FBR #: <span class="font-semibold text-emerald-700">{{ $invoice->fbr_invoice_number }}</span></p>
@elseif(in_array($invoice->status, ['locked', 'pending_verification']) && in_array(auth()->user()->role, ['company_admin', 'super_admin']))
<div x-data="{ showFbrInput: false, fbrVal: '' }" class="mt-1">
    <template x-if="!showFbrInput">
        <button @click="showFbrInput = true" class="text-xs text-blue-600 hover:text-blue-800 font-medium underline">+ Add FBR Invoice Number</button>
    </template>
    <template x-if="showFbrInput">
        <form method="POST" action="/invoice/{{ $invoice->id }}/update-fbr-number" class="flex items-center gap-1 mt-1">
            @csrf
            <input type="text" name="fbr_invoice_number" x-model="fbrVal" placeholder="FBR Invoice #" class="px-2 py-1 text-xs border border-gray-300 rounded focus:ring-emerald-500 w-48">
            <button type="submit" class="px-2 py-1 bg-emerald-600 text-white rounded text-xs font-semibold">Save</button>
        </form>
    </template>
</div>
@endif
@if($invoice->fbr_submission_date)
<p class="text-sm text-gray-600 dark:text-gray-400">FBR Date: <span class="font-semibold text-gray-900">{{ $invoice->fbr_submission_date->format('d M Y H:i') }}</span></p>
@endif
                            <p class="text-sm text-gray-600 dark:text-gray-400">Date: <span class="font-semibold text-gray-900">{{ $invoice->created_at->format('d M Y') }}</span></p>
                            @if($invoice->branch)
                            <p class="text-sm text-gray-600 dark:text-gray-400">Branch: <span class="font-semibold text-gray-900">{{ $invoice->branch->name }}</span></p>
                            @endif
                            @if($invoice->document_type && $invoice->document_type !== 'Sale Invoice')
                            <p class="text-sm text-gray-600 dark:text-gray-400">Type: <span class="font-semibold text-amber-700">{{ $invoice->document_type }}</span></p>
                            @endif
                            @if($invoice->reference_invoice_number)
                            <p class="text-sm text-gray-600 dark:text-gray-400">Ref Invoice: <span class="font-semibold text-gray-900">{{ $invoice->reference_invoice_number }}</span></p>
                            @endif
                            @if($invoice->supplier_province)
                            <p class="text-sm text-gray-600 dark:text-gray-400">Supplier Province: <span class="font-semibold text-gray-900">{{ $invoice->supplier_province }}</span></p>
                            @endif
                            @if($invoice->integrity_hash)
                            <p class="text-xs text-gray-400 mt-2 font-mono break-all">Hash: {{ $invoice->integrity_hash }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">#</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">HS Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Description</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Qty</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Price</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Tax</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">MRP</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">ST WHT</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Pet. Levy</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-orange-500 uppercase">Further Tax</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($invoice->items as $index => $item)
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $index + 1 }}</td>
                                <td class="px-6 py-4 text-sm font-mono text-gray-700 dark:text-gray-300">{{ $item->hs_code }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{{ $item->description }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300 text-right">{{ $item->quantity }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300 text-right">PKR {{ number_format($item->price, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300 text-right">PKR {{ number_format($item->tax, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300 text-right">{{ ($item->schedule_type === '3rd_schedule' && $item->mrp) ? 'PKR ' . number_format($item->mrp, 2) : '—' }}</td>
                                <td class="px-6 py-4 text-sm text-center">
                                    @if($item->st_withheld_at_source) <span class="text-emerald-600 font-medium">Yes</span> @else <span class="text-gray-400">—</span> @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300 text-right">{{ $item->petroleum_levy ? 'PKR ' . number_format($item->petroleum_levy, 2) : '—' }}</td>
                                <td class="px-6 py-4 text-sm text-orange-600 text-right">{{ ($item->further_tax ?? 0) > 0 ? 'PKR ' . number_format($item->further_tax, 2) : '—' }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">PKR {{ number_format(($item->price * $item->quantity) + $item->tax, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <td colspan="9" class="px-6 py-3 text-right text-sm text-gray-600 dark:text-gray-400">Value Excl. ST</td>
                                <td class="px-6 py-3 text-right text-sm font-semibold text-gray-800 dark:text-gray-100">PKR {{ number_format($invoice->total_value_excluding_st ?? ($invoice->total_amount - $invoice->items->sum('tax')), 2) }}</td>
                            </tr>
                            <tr>
                                <td colspan="9" class="px-6 py-3 text-right text-sm text-gray-600 dark:text-gray-400">Total Sales Tax</td>
                                <td class="px-6 py-3 text-right text-sm font-semibold text-gray-800 dark:text-gray-100">PKR {{ number_format($invoice->total_sales_tax ?? $invoice->items->sum('tax'), 2) }}</td>
                            </tr>
                            @php $totalFurtherTax = $invoice->items->sum('further_tax'); @endphp
                            @if($totalFurtherTax > 0)
                            <tr>
                                <td colspan="9" class="px-6 py-3 text-right text-sm text-orange-600">Further Tax (4%)</td>
                                <td class="px-6 py-3 text-right text-sm font-semibold text-orange-600">PKR {{ number_format($totalFurtherTax, 2) }}</td>
                            </tr>
                            @endif
                            <tr>
                                <td colspan="9" class="px-6 py-3 text-right text-sm font-bold text-gray-700 dark:text-gray-300 uppercase border-t-2 border-emerald-500">Grand Total</td>
                                <td class="px-6 py-3 text-right text-lg font-bold text-emerald-600 border-t-2 border-emerald-500">PKR {{ number_format($invoice->total_amount, 2) }}</td>
                            </tr>
                            @if($invoice->wht_locked)
                            <tr x-data="whtCorrectionHandler()" id="whtCorrectionRow">
                                <td colspan="9" class="px-6 py-2 text-right text-sm text-blue-600">
                                    WHT (<span x-text="currentRate"></span>%)
                                    <svg class="w-3.5 h-3.5 inline-block ml-1 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    <button @click="showCorrection = true" class="ml-2 text-xs text-amber-600 hover:text-amber-800 underline font-medium">Correct</button>
                                    <div x-show="showCorrection" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background-color: rgba(0,0,0,0.4);">
                                        <div @click.away="showCorrection = false" class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 p-6 w-96 max-w-[90vw]">
                                            <div class="flex items-center justify-between mb-4">
                                                <p class="text-base font-bold text-gray-800 dark:text-gray-100">Correct WHT Rate</p>
                                                <button @click="showCorrection = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-400 text-xl leading-none">&times;</button>
                                            </div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Current Rate: <span class="font-bold" x-text="currentRate + '%'"></span></p>
                                            <p class="text-xs text-amber-600 mb-3">This will update the invoice and all future PDF downloads will reflect the new rate.</p>
                                            <div class="space-y-2 mb-4">
                                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                    :class="newRate == 0 ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                    <input type="radio" value="0" x-model.number="newRate" class="text-emerald-500">
                                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">No WHT (0%)</span>
                                                </label>
                                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                    :class="newRate == 0.5 ? 'border-amber-400 bg-amber-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                    <input type="radio" value="0.5" x-model.number="newRate" class="text-amber-500">
                                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 0.5%</span>
                                                </label>
                                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                    :class="newRate == 1 ? 'border-blue-400 bg-blue-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                    <input type="radio" value="1" x-model.number="newRate" class="text-blue-500">
                                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 1%</span>
                                                </label>
                                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                    :class="newRate == 2 ? 'border-orange-400 bg-orange-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                    <input type="radio" value="2" x-model.number="newRate" class="text-orange-500">
                                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 2%</span>
                                                </label>
                                                <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                    :class="newRate == 2.5 ? 'border-red-400 bg-red-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                    <input type="radio" value="2.5" x-model.number="newRate" class="text-red-500">
                                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 2.5%</span>
                                                </label>
                                            </div>
                                            <button @click="saveCorrection()" :disabled="saving || newRate == currentRate" class="w-full px-4 py-2.5 bg-amber-600 text-white rounded-lg text-sm font-bold hover:bg-amber-700 transition disabled:opacity-50">
                                                <span x-show="!saving">Update WHT Rate</span>
                                                <span x-show="saving" x-cloak>Saving...</span>
                                            </button>
                                            <template x-if="successMsg">
                                                <p class="mt-2 text-xs text-emerald-600 font-medium text-center" x-text="successMsg"></p>
                                            </template>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-2 text-right text-sm font-semibold text-blue-600">+ PKR <span x-text="parseFloat(currentAmount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span></td>
                            </tr>
                            <tr>
                                <td colspan="9" class="px-6 py-3 text-right text-sm font-bold text-emerald-700">Net Receivable</td>
                                <td class="px-6 py-3 text-right text-lg font-bold text-emerald-700" id="netReceivableCell">PKR <span x-data="{ val: {{ $invoice->net_receivable ?? $invoice->total_amount }} }" x-text="parseFloat(val).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span></td>
                            </tr>
                            <script>
                            function whtCorrectionHandler() {
                                return {
                                    currentRate: {{ $invoice->wht_rate ?? 0 }},
                                    currentAmount: {{ $invoice->wht_amount ?? 0 }},
                                    currentNetReceivable: {{ $invoice->net_receivable ?? $invoice->total_amount }},
                                    newRate: {{ $invoice->wht_rate ?? 0 }},
                                    showCorrection: false,
                                    saving: false,
                                    successMsg: '',
                                    async saveCorrection() {
                                        this.saving = true;
                                        this.successMsg = '';
                                        try {
                                            const fd = new FormData();
                                            fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
                                            fd.append('wht_rate', this.newRate);
                                            const res = await fetch('/invoice/{{ $invoice->id }}/correct-wht-ajax', {
                                                method: 'POST',
                                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                                body: fd
                                            });
                                            const data = await res.json();
                                            if (data.status === 'ok') {
                                                this.currentRate = data.wht_rate;
                                                this.currentAmount = data.wht_amount;
                                                this.currentNetReceivable = data.net_receivable;
                                                this.successMsg = data.message || 'WHT rate updated successfully';
                                                const nrCell = document.getElementById('netReceivableCell');
                                                if (nrCell) {
                                                    nrCell.innerHTML = 'PKR ' + parseFloat(data.net_receivable).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                                }
                                                setTimeout(() => { this.showCorrection = false; this.successMsg = ''; }, 1500);
                                            } else {
                                                alert(data.message || 'Failed to update WHT rate');
                                            }
                                        } catch(e) {
                                            alert('Network error updating WHT rate');
                                        }
                                        this.saving = false;
                                    }
                                };
                            }
                            </script>
                            @elseif($invoice->wht_rate > 0)
                            <tr>
                                <td colspan="9" class="px-6 py-2 text-right text-sm text-blue-600">
                                    WHT ({{ $invoice->wht_rate }}%)
                                </td>
                                <td class="px-6 py-2 text-right text-sm font-semibold text-blue-600">+ PKR {{ number_format($invoice->wht_amount ?? 0, 2) }}</td>
                            </tr>
                            <tr>
                                <td colspan="9" class="px-6 py-3 text-right text-sm font-bold text-emerald-700">Net Receivable</td>
                                <td class="px-6 py-3 text-right text-lg font-bold text-emerald-700">PKR {{ number_format($invoice->net_receivable ?? $invoice->total_amount, 2) }}</td>
                            </tr>
                            @endif
                        </tfoot>
                    </table>
                </div>
            </div>

            @if(!($invoice->status === 'locked' && $invoice->fbr_status === 'production') && !empty($riskAnalysis) && $riskAnalysis['risk_count'] > 0)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border {{ $riskAnalysis['risk_color']['border'] }} overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span>Intelligence Risk Analysis</span>
                    </h3>
                    <div class="flex items-center space-x-3">
                        <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold {{ $riskAnalysis['risk_color']['bg'] }} {{ $riskAnalysis['risk_color']['text'] }}">
                            Score: {{ $riskAnalysis['risk_score'] }}/100 - {{ ucfirst($riskAnalysis['risk_level']) }}
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $riskAnalysis['risk_count'] }} risk(s)</span>
                    </div>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        @foreach($riskAnalysis['risks'] as $risk)
                        <div class="flex items-start space-x-3 p-3 rounded-lg {{ $risk['severity'] === 'high' ? 'bg-red-50 border border-red-200' : ($risk['severity'] === 'medium' ? 'bg-yellow-50 border border-yellow-200' : 'bg-blue-50 border border-blue-200') }}">
                            @if($risk['severity'] === 'high')
                            <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                            @else
                            <svg class="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
                            @endif
                            <div class="flex-1">
                                <p class="text-sm font-medium {{ $risk['severity'] === 'high' ? 'text-red-800' : 'text-yellow-800' }}">
                                    {{ ucfirst(str_replace('_', ' ', $risk['type'])) }}
                                    <span class="text-xs font-normal ml-1">(weight: {{ $risk['weight'] }})</span>
                                </p>
                                <p class="text-sm {{ $risk['severity'] === 'high' ? 'text-red-700' : 'text-yellow-700' }} mt-0.5">{{ $risk['message'] }}</p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @if($riskAnalysis['should_block'])
                    <div class="mt-4 p-3 bg-red-100 border border-red-300 rounded-lg">
                        <p class="text-sm font-bold text-red-800">FBR submission will be BLOCKED due to critical risk level. Resolve the issues above before submitting.</p>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            @if(!($invoice->status === 'locked' && $invoice->fbr_status === 'production') && !empty($sroSuggestions) && count($sroSuggestions) > 0)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-blue-200 overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-blue-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                        <span>SRO Suggestions</span>
                    </h3>
                    <span class="text-xs text-blue-600 bg-blue-50 px-2 py-1 rounded">Non-mandatory - Verify before use</span>
                </div>
                <div class="p-6">
                    <div class="space-y-2">
                        @foreach($sroSuggestions as $itemIndex => $suggestion)
                        <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg border border-blue-100">
                            <div>
                                <p class="text-sm font-medium text-blue-800">Item #{{ $itemIndex + 1 }}: {{ $suggestion['sro'] }} / Serial {{ $suggestion['serial'] }}</p>
                                <p class="text-xs text-blue-600">{{ $suggestion['description'] }}</p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $suggestion['confidence'] === 'high' ? 'bg-green-100 text-green-700' : ($suggestion['confidence'] === 'medium' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-700 dark:text-gray-300') }}">
                                    {{ ucfirst($suggestion['confidence']) }} confidence
                                </span>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif

            @if(!($invoice->status === 'locked' && $invoice->fbr_status === 'production') && !empty($vendorRisk) && $vendorRisk->vendor_score < 70)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-orange-200 overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-orange-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                        <span>Vendor Risk Alert</span>
                    </h3>
                    <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold {{ $vendorRisk->vendor_score < 40 ? 'bg-red-100 text-red-800' : 'bg-orange-100 text-orange-800' }}">
                        Score: {{ $vendorRisk->vendor_score }}/100
                    </span>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-center">
                            <p class="text-xs text-gray-500 dark:text-gray-400">Total Invoices</p>
                            <p class="text-lg font-bold text-gray-900">{{ $vendorRisk->total_invoices }}</p>
                        </div>
                        <div class="p-3 {{ $vendorRisk->rejected_invoices > 0 ? 'bg-red-50' : 'bg-gray-50' }} rounded-lg text-center">
                            <p class="text-xs text-gray-500 dark:text-gray-400">Rejected</p>
                            <p class="text-lg font-bold {{ $vendorRisk->rejected_invoices > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $vendorRisk->rejected_invoices }}</p>
                        </div>
                        <div class="p-3 {{ $vendorRisk->tax_mismatches > 0 ? 'bg-orange-50' : 'bg-gray-50' }} rounded-lg text-center">
                            <p class="text-xs text-gray-500 dark:text-gray-400">Tax Mismatches</p>
                            <p class="text-lg font-bold {{ $vendorRisk->tax_mismatches > 0 ? 'text-orange-700' : 'text-gray-900' }}">{{ $vendorRisk->tax_mismatches }}</p>
                        </div>
                        <div class="p-3 {{ $vendorRisk->anomaly_count > 0 ? 'bg-yellow-50' : 'bg-gray-50' }} rounded-lg text-center">
                            <p class="text-xs text-gray-500 dark:text-gray-400">Anomalies</p>
                            <p class="text-lg font-bold {{ $vendorRisk->anomaly_count > 0 ? 'text-yellow-700' : 'text-gray-900' }}">{{ $vendorRisk->anomaly_count }}</p>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            @if(!($invoice->status === 'locked' && $invoice->fbr_status === 'production'))
            <div x-data="{ rejResult: null, rejLoading: false }" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 dark:border-gray-700 p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        <span>Rejection Probability Shield</span>
                    </h3>
                    <button @click="rejLoading=true; fetch('/api/invoice/{{ $invoice->id }}/rejection-probability').then(r=>r.json()).then(d=>{rejResult=d;rejLoading=false}).catch(()=>rejLoading=false)" :disabled="rejLoading" class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition">
                        <span x-show="!rejLoading">Analyze</span>
                        <span x-show="rejLoading">Analyzing...</span>
                    </button>
                </div>
                <template x-if="rejResult">
                    <div>
                        <div class="flex items-center space-x-4 mb-3">
                            <div class="flex-1 bg-gray-200 dark:bg-gray-600 rounded-full h-4">
                                <div class="h-4 rounded-full transition-all duration-500" :class="rejResult.probability <= 25 ? 'bg-green-500' : (rejResult.probability <= 60 ? 'bg-yellow-500' : 'bg-red-500')" :style="'width:'+rejResult.probability+'%'"></div>
                            </div>
                            <span class="text-2xl font-bold" :class="rejResult.probability <= 25 ? 'text-green-600' : (rejResult.probability <= 60 ? 'text-yellow-600' : 'text-red-600')" x-text="rejResult.probability+'%'"></span>
                        </div>
                        <p class="text-sm font-semibold mb-2" :class="rejResult.probability <= 25 ? 'text-green-600' : (rejResult.probability <= 60 ? 'text-yellow-600' : 'text-red-600')" x-text="rejResult.label"></p>
                        <template x-if="rejResult.checks && rejResult.checks.length > 0">
                            <div class="space-y-1 mt-3">
                                <template x-for="check in rejResult.checks" :key="check.message">
                                    <div class="flex items-center space-x-2 text-xs p-2 rounded" :class="check.severity === 'critical' ? 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300' : (check.severity === 'high' ? 'bg-orange-50 dark:bg-orange-900/20 text-orange-700 dark:text-orange-300' : 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-300')">
                                        <span class="font-bold uppercase" x-text="check.severity"></span>
                                        <span x-text="check.message"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="!rejResult.checks || rejResult.checks.length === 0">
                            <p class="text-sm text-green-600 dark:text-green-400 mt-2">All pre-submission checks passed.</p>
                        </template>
                    </div>
                </template>
                <template x-if="!rejResult">
                    <p class="text-sm text-gray-400 text-center py-4">Click "Analyze" to run pre-submission rejection simulation</p>
                </template>
            </div>
            @endif

            @if(!($invoice->status === 'locked' && $invoice->fbr_status === 'production') && !empty($complianceReport))
            @php
                $isFbrValidated = $complianceReport->is_fbr_validated ?? false;
                $ruleFlags = $complianceReport->rule_flags ?? [];
                $preFlags = $complianceReport->pre_validation_flags ?? [];
                $panelBorder = $isFbrValidated ? 'border-emerald-200' : ($complianceReport->risk_level === 'CRITICAL' ? 'border-red-200' : ($complianceReport->risk_level === 'HIGH' ? 'border-orange-200' : 'border-gray-100'));
            @endphp
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border {{ $panelBorder }} overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between flex-wrap gap-2">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                        <svg class="w-5 h-5 {{ $isFbrValidated ? 'text-emerald-600' : 'text-indigo-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        <span>Compliance Analysis</span>
                    </h3>
                    <div class="flex items-center gap-2 flex-wrap">
                        @if($isFbrValidated)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            FBR VALIDATED &mdash; Structural Compliance Confirmed
                        </span>
                        @endif
                        @php $crBadge = \App\Services\HybridComplianceScorer::getRiskBadge($complianceReport->risk_level); @endphp
                        <span class="inline-flex px-3 py-1 rounded-full text-xs font-bold {{ $crBadge['bg'] }} {{ $crBadge['text'] }}">
                            Score: {{ $complianceReport->final_score }} - {{ $complianceReport->risk_level }}
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    @if($isFbrValidated && !empty($preFlags) && count(array_filter($preFlags)) > 0)
                    <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 rounded-lg">
                        <p class="text-xs font-medium text-emerald-700">
                            <svg class="w-4 h-4 inline-block mr-1 -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Pre-submission simulation flagged {{ count(array_filter($preFlags)) }} issue(s). These were cleared after FBR confirmed statusCode "00" (production success).
                        </p>
                    </div>
                    @endif
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        @php
                            $rateMismatch = $ruleFlags['RATE_MISMATCH'] ?? false;
                            $rateCleared = $isFbrValidated && ($preFlags['RATE_MISMATCH'] ?? false) && !$rateMismatch;
                        @endphp
                        <div class="p-3 rounded-lg {{ $rateMismatch ? 'bg-red-50 border border-red-200' : ($rateCleared ? 'bg-emerald-50 border border-emerald-200' : 'bg-green-50 border border-green-200') }}">
                            <p class="text-xs font-medium {{ $rateMismatch ? 'text-red-700' : ($rateCleared ? 'text-emerald-700' : 'text-green-700') }}">Tax Rate</p>
                            <p class="text-sm font-bold {{ $rateMismatch ? 'text-red-800' : ($rateCleared ? 'text-emerald-800' : 'text-green-800') }}">{{ $rateMismatch ? 'MISMATCH' : ($rateCleared ? 'CLEARED' : 'OK') }}</p>
                        </div>
                        @php
                            $buyerRisk = $ruleFlags['BUYER_RISK'] ?? false;
                            $buyerCleared = $isFbrValidated && ($preFlags['BUYER_RISK'] ?? false) && !$buyerRisk;
                        @endphp
                        <div class="p-3 rounded-lg {{ $buyerRisk ? 'bg-red-50 border border-red-200' : ($buyerCleared ? 'bg-emerald-50 border border-emerald-200' : 'bg-green-50 border border-green-200') }}">
                            <p class="text-xs font-medium {{ $buyerRisk ? 'text-red-700' : ($buyerCleared ? 'text-emerald-700' : 'text-green-700') }}">Buyer NTN (S.23)</p>
                            <p class="text-sm font-bold {{ $buyerRisk ? 'text-red-800' : ($buyerCleared ? 'text-emerald-800' : 'text-green-800') }}">{{ $buyerRisk ? 'AT RISK' : ($buyerCleared ? 'CLEARED' : 'OK') }}</p>
                        </div>
                        <div class="p-3 rounded-lg {{ ($ruleFlags['BANKING_RISK'] ?? false) ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200' }}">
                            <p class="text-xs font-medium {{ ($ruleFlags['BANKING_RISK'] ?? false) ? 'text-red-700' : 'text-green-700' }}">Banking (S.73)</p>
                            <p class="text-sm font-bold {{ ($ruleFlags['BANKING_RISK'] ?? false) ? 'text-red-800' : 'text-green-800' }}">{{ ($ruleFlags['BANKING_RISK'] ?? false) ? 'VIOLATION' : 'OK' }}</p>
                        </div>
                        @php
                            $structError = $ruleFlags['STRUCTURE_ERROR'] ?? false;
                            $structCleared = $isFbrValidated && ($preFlags['STRUCTURE_ERROR'] ?? false) && !$structError;
                        @endphp
                        <div class="p-3 rounded-lg {{ $structError ? 'bg-red-50 border border-red-200' : ($structCleared ? 'bg-emerald-50 border border-emerald-200' : 'bg-green-50 border border-green-200') }}">
                            <p class="text-xs font-medium {{ $structError ? 'text-red-700' : ($structCleared ? 'text-emerald-700' : 'text-green-700') }}">Structure (S.23)</p>
                            <p class="text-sm font-bold {{ $structError ? 'text-red-800' : ($structCleared ? 'text-emerald-800' : 'text-green-800') }}">{{ $structError ? 'ERROR' : ($structCleared ? 'CLEARED' : 'OK') }}</p>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            @if($invoice->activityLogs && $invoice->activityLogs->count() > 0)
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Invoice Timeline</h3>
                </div>
                <div class="p-6">
                    <div class="relative">
                        <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-gray-200"></div>
                        @foreach($invoice->activityLogs as $log)
                        <div class="relative flex items-start space-x-4 pb-6 last:pb-0">
                            <div class="relative z-10 flex-shrink-0">
                                @if($log->action === 'created')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-green-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg></span>
                                @elseif($log->action === 'edited')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-blue-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></span>
                                @elseif($log->action === 'submitted')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-purple-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg></span>
                                @elseif($log->action === 'locked')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-emerald-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></span>
                                @elseif($log->action === 'retry')
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-yellow-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg></span>
                                @else
                                    <span class="flex items-center justify-center w-8 h-8 rounded-full bg-red-500 text-white"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg></span>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-semibold text-gray-900 capitalize">{{ str_replace('_', ' ', $log->action) }}</p>
                                    <p class="text-xs text-gray-400">{{ $log->created_at->format('d M Y, h:i A') }}</p>
                                </div>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">by {{ $log->user->name ?? 'System' }} &middot; {{ $log->ip_address }}</p>
                                @if($log->changes_json)
                                <div class="mt-1 text-xs text-gray-400 font-mono bg-gray-50 dark:bg-gray-800 rounded p-2 max-h-20 overflow-y-auto">
                                    {{ json_encode($log->changes_json, JSON_PRETTY_PRINT) }}
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>

<div id="fbrSuccessModal" style="display:none;" class="fixed inset-0 z-[60] flex items-center justify-center transition-opacity duration-300 opacity-0">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeFbrSuccessModal()"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
        <div class="bg-gradient-to-r from-emerald-600 to-emerald-700 px-6 py-6 text-center">
            <div class="flex items-center justify-center w-16 h-16 rounded-full bg-white/20 mx-auto mb-3">
                <svg class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h3 class="text-xl font-bold text-white mb-1">FBR Production Successful</h3>
            <p class="text-emerald-100 text-sm" id="modalFbrNumber"></p>
            <p class="text-emerald-200 text-xs mt-1" id="modalTimestamp"></p>
        </div>

        <div class="px-6 py-5">
            <div id="modalWhtSection" class="mb-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                <div id="modalWhtSelector">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-sm font-bold text-gray-800 dark:text-gray-200">
                            <svg class="w-4 h-4 inline mr-1 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            Select WHT Rate
                        </p>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Lock before printing</span>
                    </div>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <label class="modal-wht-opt flex items-center gap-1.5 px-3 py-2 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 text-xs font-bold transition">
                            <input type="radio" name="modal_wht" value="0" checked class="text-emerald-500"> 0%
                        </label>
                        <label class="modal-wht-opt flex items-center gap-1.5 px-3 py-2 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 text-xs font-bold transition">
                            <input type="radio" name="modal_wht" value="0.5" class="text-amber-500"> 0.5%
                        </label>
                        <label class="modal-wht-opt flex items-center gap-1.5 px-3 py-2 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 text-xs font-bold transition">
                            <input type="radio" name="modal_wht" value="1" class="text-blue-500"> 1%
                        </label>
                        <label class="modal-wht-opt flex items-center gap-1.5 px-3 py-2 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 text-xs font-bold transition">
                            <input type="radio" name="modal_wht" value="2" class="text-orange-500"> 2%
                        </label>
                        <label class="modal-wht-opt flex items-center gap-1.5 px-3 py-2 rounded-lg border-2 border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 text-xs font-bold transition">
                            <input type="radio" name="modal_wht" value="2.5" class="text-red-500"> 2.5%
                        </label>
                    </div>
                    <button onclick="lockWhtInModal()" id="modalWhtLockBtn" class="w-full px-4 py-2.5 bg-amber-600 text-white rounded-lg text-sm font-bold hover:bg-amber-700 transition">
                        Lock WHT Rate
                    </button>
                </div>
                <div id="modalWhtLocked" style="display:none;" class="flex items-center justify-center gap-2">
                    <span class="inline-flex items-center px-4 py-2 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg text-sm font-bold">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        WHT <span id="modalWhtRateText">0</span>% Locked
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                <a href="/invoice/{{ $invoice->id }}/pdf" target="_blank" class="flex flex-col items-center gap-2 px-4 py-4 bg-indigo-600 text-white rounded-xl text-center font-bold hover:bg-indigo-700 transition shadow-lg shadow-indigo-200 dark:shadow-none">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    <span class="text-sm">View & Print</span>
                </a>
                <a href="/invoice/{{ $invoice->id }}/download" class="flex flex-col items-center gap-2 px-4 py-4 bg-emerald-600 text-white rounded-xl text-center font-bold hover:bg-emerald-700 transition shadow-lg shadow-emerald-200 dark:shadow-none">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span class="text-sm">Download PDF</span>
                </a>
                <a href="https://wa.me/?text={{ urlencode('Invoice ' . ($invoice->display_invoice_number ?? '') . "\nDownload: " . url('/share/invoice/' . ($invoice->share_uuid ?? '') . '/pdf')) }}" target="_blank" class="flex flex-col items-center gap-2 px-4 py-4 bg-green-500 text-white rounded-xl text-center font-bold hover:bg-green-600 transition shadow-lg shadow-green-200 dark:shadow-none">
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492a.75.75 0 00.917.918l4.462-1.494A11.945 11.945 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-2.4 0-4.637-.734-6.482-1.988l-.452-.305-2.971.993.994-2.969-.316-.461A9.955 9.955 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/></svg>
                    <span class="text-sm">WhatsApp</span>
                </a>
            </div>

            <button onclick="closeFbrSuccessModal()" class="w-full px-4 py-3 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-bold hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                Close
            </button>
            <p class="text-xs text-gray-400 mt-3 text-center">Protected by TaxNest Idempotency Shield</p>
        </div>
    </div>
</div>

<div id="fbrPendingModal" style="display:none;" class="fixed inset-0 z-[60] flex items-center justify-center transition-opacity duration-300 opacity-0">
    <div class="absolute inset-0 bg-black/40" onclick="closeFbrPendingModal()"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden">
        <div class="px-6 py-5 border-b border-amber-100 bg-amber-50">
            <div class="flex items-center gap-3">
                <div class="flex items-center justify-center w-10 h-10 rounded-full bg-amber-100">
                    <svg class="w-6 h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-amber-900">FBR Pending Verification</h3>
                    <p class="text-xs text-amber-700">Response requires manual verification</p>
                </div>
            </div>
        </div>
        <div class="px-6 py-4">
            <p class="text-sm text-gray-700 dark:text-gray-300" id="pendingMessage"></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">FBR returned an ambiguous response. The invoice has been marked as pending verification. Please check FBR portal to confirm submission status.</p>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800 flex flex-col gap-2">
            <a href="https://e.fbr.gov.pk" target="_blank" rel="noopener" class="w-full text-center px-4 py-2.5 bg-amber-600 text-white rounded-lg text-sm font-semibold hover:bg-amber-700 transition">
                Verify on FBR Portal
            </a>
            <button onclick="closeFbrPendingModal()" class="w-full text-center px-4 py-2.5 bg-gray-200 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-semibold hover:bg-gray-300 transition">
                Close
            </button>
        </div>
    </div>
</div>

<div id="fbrErrorToast" style="display:none;" class="fixed top-4 right-4 z-[60] max-w-md w-full">
    <div class="bg-red-50 border-2 border-red-300 rounded-xl p-4 shadow-xl flex items-start gap-3">
        <svg class="w-6 h-6 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <div class="flex-1">
            <p class="text-sm font-bold text-red-800">FBR Submission Failed</p>
            <p class="text-xs text-red-700 mt-1" id="errorMessage"></p>
            <button onclick="document.getElementById('fbrErrorToast').style.display='none'" class="mt-2 text-xs text-red-600 hover:text-red-800 font-medium underline">Dismiss</button>
        </div>
    </div>
</div>

<script>
let _fbrPdfUrl = '';
let _lastFbrNumber = '';

function fbrSubmitEngine() {
    return {
        showSubmitModal: false,
        submitEnv: '{{ $invoice->company->fbr_environment ?? "sandbox" }}',
        submitting: false,
        submitMode: '',
        showOverride: false,
        overrideReason: '',

        async doSubmit(mode) {
            if (this.submitting) return;
            this.submitting = true;
            this.submitMode = mode;

            const body = new FormData();
            body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            body.append('mode', mode);
            body.append('fbr_environment', this.submitEnv);
            if (mode === 'direct_mis') {
                body.append('override_reason', this.overrideReason);
            }

            try {
                const res = await fetch('/invoice/{{ $invoice->id }}/submit', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body
                });

                const contentType = res.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    this.showSubmitModal = false;
                    if (res.redirected || res.status === 302) {
                        window.location.reload();
                        return;
                    }
                    showFbrError('Server returned unexpected response (HTTP ' + res.status + '). Please refresh and try again.');
                    return;
                }

                const data = await res.json();
                this.showSubmitModal = false;
                handleFbrResponse(data);
            } catch (e) {
                this.showSubmitModal = false;
                console.error('FBR Submit Error:', e);
                showFbrError('Network error: ' + (e.message || 'Please check your connection and try again.'));
            }
            this.submitting = false;
        }
    };
}

async function retrySubmitToFbr(el) {
    const wrapper = el.closest('[x-data]');
    if (!wrapper) return;
    const comp = Alpine.$data(wrapper);
    if (comp.retrying) return;
    comp.retrying = true;

    const body = new FormData();
    body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');

    try {
        const res = await fetch('/invoice/{{ $invoice->id }}/retry', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        });

        const data = await res.json();
        handleFbrResponse(data);
    } catch (e) {
        showFbrError('Network error. Please check your connection and try again.');
    }
    comp.retrying = false;
}

async function resubmitToFbr(el) {
    const wrapper = el.closest('[x-data]');
    if (!wrapper) return;
    const comp = Alpine.$data(wrapper);
    if (comp.resubmitting) return;
    comp.resubmitting = true;

    const body = new FormData();
    body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');

    try {
        const res = await fetch('/invoice/{{ $invoice->id }}/resubmit-fbr', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        });

        const data = await res.json();
        handleFbrResponse(data);
    } catch (e) {
        showFbrError('Network error. Please check your connection and try again.');
    }
    comp.resubmitting = false;
}

function handleFbrResponse(data) {
    if (data.status === 'success') {
        openFbrSuccessModal(data);
    } else if (data.status === 'pending_verification') {
        showFbrPending(data.message);
    } else {
        showFbrError(data.error_details || data.message || 'Unknown error');
    }
}

function openFbrSuccessModal(data) {
    _fbrPdfUrl = data.pdf_url || '/invoice/{{ $invoice->id }}/pdf';
    _lastFbrNumber = data.fbr_invoice_number || '';
    document.getElementById('modalFbrNumber').textContent = 'FBR #: ' + _lastFbrNumber;
    document.getElementById('modalTimestamp').textContent = 'Submitted: ' + new Date().toLocaleString('en-PK', { dateStyle: 'medium', timeStyle: 'short' });
    const modal = document.getElementById('fbrSuccessModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => { modal.classList.remove('opacity-0'); modal.classList.add('opacity-100'); });
}

function closeFbrSuccessModal() {
    const modal = document.getElementById('fbrSuccessModal');
    modal.classList.remove('opacity-100');
    modal.classList.add('opacity-0');
    setTimeout(() => {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        smartRefreshInvoiceStatus();
    }, 250);
}

async function smartRefreshInvoiceStatus() {
    try {
        const res = await fetch('/invoice/{{ $invoice->id }}/status-json', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) throw new Error('Status fetch failed');
        const data = await res.json();
        patchStatusBadge(data.status, data.fbr_status);
        patchActionButtons(data.status, data.fbr_status, data.fbr_invoice_number, data.share_uuid, data.display_invoice_number, data.wht_rate, data.wht_locked);
        showSuccessToast(data.fbr_invoice_number);
    } catch (e) {
        window.location.reload();
    }
}

function patchStatusBadge(status, fbrStatus) {
    const badge = document.getElementById('invoiceStatusBadge');
    const fbrBadge = document.getElementById('fbrStatusBadge');
    if (badge) {
        badge.className = 'inline-flex px-3 py-1 rounded-full text-sm font-bold transition-all duration-200';
        const statusMap = { draft: 'bg-gray-200 text-gray-700 dark:text-gray-300', failed: 'bg-red-100 text-red-800', locked: 'bg-green-100 text-green-800', pending_verification: 'bg-amber-100 text-amber-800' };
        badge.classList.add(...(statusMap[status] || 'bg-gray-200 text-gray-700 dark:text-gray-300').split(' '));
        badge.textContent = status === 'pending_verification' ? 'Pending Verification' : status.charAt(0).toUpperCase() + status.slice(1);
        badge.style.opacity = '0';
        requestAnimationFrame(() => { badge.style.transition = 'opacity 200ms ease'; badge.style.opacity = '1'; });
    }
    if (fbrBadge) {
        if (fbrStatus) {
            fbrBadge.classList.remove('hidden');
            fbrBadge.className = 'inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ml-2 transition-all duration-200';
            const fbrMap = { production: 'bg-emerald-100 text-emerald-800', validated: 'bg-emerald-100 text-emerald-800', failed: 'bg-red-100 text-red-800', validation_failed: 'bg-red-100 text-red-800', sandbox: 'bg-amber-100 text-amber-800' };
            fbrBadge.classList.add(...(fbrMap[fbrStatus] || 'bg-gray-100 text-gray-800 dark:text-gray-100').split(' '));
            const fbrLabel = fbrStatus === 'production' ? 'Production' : fbrStatus === 'validation_failed' ? 'Validation Failed' : fbrStatus.charAt(0).toUpperCase() + fbrStatus.slice(1);
            fbrBadge.textContent = 'FBR: ' + fbrLabel;
            fbrBadge.style.opacity = '0';
            requestAnimationFrame(() => { fbrBadge.style.transition = 'opacity 200ms ease'; fbrBadge.style.opacity = '1'; });
        } else {
            fbrBadge.classList.add('hidden');
            fbrBadge.textContent = '';
        }
    }
}

function patchActionButtons(status, fbrStatus, fbrInvoiceNumber, shareUuid, displayNumber, whtRate, whtLocked) {
    if (status === 'locked' && fbrStatus === 'production') {
        window.location.reload();
    }
}

function showSuccessToast(fbrNumber) {
    const existing = document.getElementById('successToastLive');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.id = 'successToastLive';
    toast.className = 'fixed top-4 right-4 z-[70] max-w-sm w-full transition-all duration-200';
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(2rem)';
    toast.innerHTML = `
        <div class="bg-emerald-50 border-2 border-emerald-300 rounded-xl p-4 shadow-xl flex items-start gap-3">
            <svg class="w-6 h-6 text-emerald-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="flex-1">
                <p class="text-sm font-bold text-emerald-800">Invoice Successfully Submitted to FBR</p>
                ${fbrNumber ? `<p class="text-xs text-emerald-700 mt-1">FBR Invoice #: ${fbrNumber}</p>` : ''}
            </div>
            <button onclick="this.closest('#successToastLive').remove()" class="text-emerald-400 hover:text-emerald-600"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
        </div>
    `;
    document.body.appendChild(toast);
    requestAnimationFrame(() => { toast.style.opacity = '1'; toast.style.transform = 'translateX(0)'; });
    setTimeout(() => {
        if (toast.parentNode) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(2rem)';
            setTimeout(() => toast.remove(), 200);
        }
    }, 4000);
}

async function lockWhtInModal() {
    const btn = document.getElementById('modalWhtLockBtn');
    const selectedRadio = document.querySelector('input[name="modal_wht"]:checked');
    const whtRate = selectedRadio ? parseFloat(selectedRadio.value) : 0;
    btn.disabled = true;
    btn.textContent = 'Locking...';
    try {
        const body = new FormData();
        body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        body.append('wht_rate', whtRate);
        const res = await fetch('/invoice/{{ $invoice->id }}/update-wht-ajax', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        });
        const data = await res.json();
        if (data.status === 'ok') {
            document.getElementById('modalWhtSelector').style.display = 'none';
            document.getElementById('modalWhtLocked').style.display = 'flex';
            document.getElementById('modalWhtRateText').textContent = whtRate;
            _fbrPdfUrl = '/invoice/{{ $invoice->id }}/pdf';
        } else {
            alert(data.message || 'Failed to lock WHT rate');
            btn.disabled = false;
            btn.textContent = 'Lock WHT Rate';
        }
    } catch(e) {
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Lock WHT Rate';
    }
}

function printFbrPdf() {
    const url = _fbrPdfUrl || '/invoice/{{ $invoice->id }}/pdf';
    const printWin = window.open(url, '_blank');
    if (!printWin) {
        window.location.href = url;
    }
}

function downloadFbrPdf() {
    if (_fbrPdfUrl) {
        const downloadUrl = _fbrPdfUrl.replace('/pdf', '/download');
        const a = document.createElement('a');
        a.href = downloadUrl;
        a.download = '';
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => document.body.removeChild(a), 100);
    }
}

function openInlinePdfPopup() {
    const existing = document.getElementById('inlinePdfPopup');
    if (existing) existing.remove();
    const popup = document.createElement('div');
    popup.id = 'inlinePdfPopup';
    popup.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;background:rgba(0,0,0,0.7);';
    popup.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 24px;background:#ffffff;border-bottom:2px solid #e5e7eb;flex-shrink:0;">
            <span style="font-size:15px;font-weight:800;color:#111827;">Invoice PDF Preview</span>
            <div style="display:flex;gap:10px;align-items:center;">
                <button onclick="printInlinePdf()" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#4f46e5;color:white;border-radius:8px;font-size:13px;font-weight:700;border:none;cursor:pointer;box-shadow:0 2px 4px rgba(79,70,229,0.3);">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Print
                </button>
                <a href="/invoice/{{ $invoice->id }}/download" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#059669;color:white;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 2px 4px rgba(5,150,105,0.3);">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download
                </a>
                <a href="/invoice/{{ $invoice->id }}/pdf" target="_blank" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#2563eb;color:white;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 2px 4px rgba(37,99,235,0.3);">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    Open Full
                </a>
                <button onclick="closeInlinePdfPopup()" style="display:inline-flex;align-items:center;padding:8px 16px;background:#f3f4f6;color:#374151;border-radius:8px;font-size:13px;font-weight:700;border:1px solid #d1d5db;cursor:pointer;">Close</button>
            </div>
        </div>
        <div style="flex:1;overflow:hidden;padding:12px;background:#e5e7eb;display:flex;align-items:stretch;justify-content:center;">
            <iframe src="/invoice/{{ $invoice->id }}/pdf?t=${Date.now()}" style="width:100%;max-width:900px;height:100%;border:none;border-radius:8px;background:white;box-shadow:0 4px 20px rgba(0,0,0,0.15);"></iframe>
        </div>
    `;
    document.body.appendChild(popup);
    document.body.style.overflow = 'hidden';
}
function closeInlinePdfPopup() {
    const popup = document.getElementById('inlinePdfPopup');
    if (popup) { popup.remove(); document.body.style.overflow = ''; }
}
function printInlinePdf() {
    const printWin = window.open('/invoice/{{ $invoice->id }}/pdf', '_blank');
    if (!printWin) {
        window.location.href = '/invoice/{{ $invoice->id }}/pdf';
    }
}

function showFbrPending(message) {
    document.getElementById('pendingMessage').textContent = message || 'FBR returned an ambiguous response. Please verify on FBR portal.';
    const modal = document.getElementById('fbrPendingModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => { modal.classList.remove('opacity-0'); modal.classList.add('opacity-100'); });
}

function closeFbrPendingModal() {
    const modal = document.getElementById('fbrPendingModal');
    modal.classList.remove('opacity-100');
    modal.classList.add('opacity-0');
    setTimeout(() => {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        smartRefreshInvoiceStatus();
    }, 250);
}

function showFbrError(message) {
    document.getElementById('errorMessage').textContent = message || 'FBR submission failed. Please try again.';
    document.getElementById('fbrErrorToast').style.display = 'block';
    setTimeout(() => { document.getElementById('fbrErrorToast').style.display = 'none'; }, 10000);
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('fbrSuccessModal').style.display === 'flex') {
        closeFbrSuccessModal();
    }
});

if (window.location.hash === '#submit') {
    setTimeout(function() {
        const submitRoot = document.querySelector('[x-ref="submitRoot"]');
        if (submitRoot) {
            Alpine.$data(submitRoot).showSubmitModal = true;
            history.replaceState(null, '', window.location.pathname);
        }
    }, 500);
}
</script>
</x-app-layout>
