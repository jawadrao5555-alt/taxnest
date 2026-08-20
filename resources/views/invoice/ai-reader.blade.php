<x-app-layout>
    <div class="py-8 pb-24">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 mb-6">
            <nav class="flex items-center text-xs text-gray-500 dark:text-gray-400 mb-1.5">
                <a href="{{ route('dashboard') }}" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition font-medium">Dashboard</a>
                <svg class="w-3.5 h-3.5 mx-1.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <a href="/invoices" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition font-medium">Invoices</a>
                <svg class="w-3.5 h-3.5 mx-1.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="text-gray-800 dark:text-gray-200 font-semibold">AI Invoice Reader</span>
            </nav>
            <div class="flex flex-wrap items-center gap-3">
                <a href="/invoices" class="inline-flex items-center text-gray-500 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 transition text-sm font-medium">
                    <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Back to Invoices
                </a>
                <h2 class="font-extrabold text-2xl text-gray-900 dark:text-white leading-tight tracking-tight">AI Invoice Reader</h2>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-gradient-to-r from-violet-600 to-indigo-600 text-white uppercase tracking-wider">Premium</span>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Upload an old or supplier-format invoice (PDF, photo, or Excel) — AI reads it into a compliant draft you review and save. Nothing is ever submitted to FBR automatically.</p>
            @if($allowed)
            <a href="{{ route('invoices.ai-reader.bulk') }}" class="mt-3 inline-flex items-center rounded-xl bg-violet-100 dark:bg-violet-900/40 px-3.5 py-2 text-xs font-bold text-violet-800 dark:text-violet-200 hover:bg-violet-200 dark:hover:bg-violet-900/60 transition">
                Import many invoice photos separately
            </a>
            @endif
        </div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if(session('error'))
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-4">
                <p class="text-sm text-red-700 dark:text-red-400">{{ session('error') }}</p>
            </div>
            @endif

            @if(!$allowed)
            {{-- Locked upsell state --}}
            <div class="premium-card p-10 text-center">
                <div class="mx-auto w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-600 to-indigo-600 flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <h3 class="text-xl font-extrabold text-gray-900 dark:text-white">AI Invoice Reader is a Premium feature</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">
                    Turn any old invoice — PDF, photo, or Excel — into a ready-to-review FBR draft in seconds.
                    Buyer details, line items, quantities, prices, and HS code suggestions are extracted for you.
                </p>
                <a href="/billing/plans" class="mt-6 inline-flex items-center px-6 py-3 bg-gradient-to-r from-violet-600 to-indigo-600 rounded-xl font-bold text-sm text-white uppercase tracking-widest hover:from-violet-700 hover:to-indigo-700 transition">
                    Upgrade to Premium
                </a>
            </div>
            @else

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6" x-data="aiReader()">
                {{-- Upload column --}}
                <div class="lg:col-span-3 space-y-6">
                    <div class="premium-card p-6">
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wider mb-4">Upload Invoice File</h3>

                        @if(!$configured)
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl p-4">
                            <p class="text-sm text-amber-700 dark:text-amber-400">AI service is not configured yet. Please contact support.</p>
                        </div>
                        @elseif(!$quota['unlimited'] && $quota['remaining'] <= 0)
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl p-4">
                            <p class="text-sm text-amber-700 dark:text-amber-400 font-semibold">Monthly AI parse limit reached ({{ $quota['used'] }}/{{ $quota['quota'] }}).</p>
                            <p class="text-xs text-amber-600 dark:text-amber-500 mt-1">Your quota resets on the 1st of next month. You can still create invoices manually or via CSV import.</p>
                        </div>
                        @else
                        <div x-show="!busy"
                             @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false"
                             @drop.prevent="dragging = false; onFile($event.dataTransfer.files[0])"
                             :class="dragging ? 'border-violet-500 bg-violet-50 dark:bg-violet-900/20' : 'border-gray-300 dark:border-gray-600'"
                             class="border-2 border-dashed rounded-xl p-8 text-center transition cursor-pointer"
                             @click="$refs.fileInput.click()">
                            <input type="file" class="hidden" x-ref="fileInput"
                                   accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.csv"
                                   @change="onFile($event.target.files[0])">
                            <svg class="mx-auto h-12 w-12 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                            <p class="mt-3 text-sm font-semibold text-gray-700 dark:text-gray-200">Drop your invoice here, or click to choose a file</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">PDF, photo (JPG/PNG), Excel (.xlsx), or CSV &middot; max 5MB</p>
                            <template x-if="file">
                                <div class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-violet-100 dark:bg-violet-900/40 text-violet-800 dark:text-violet-200 text-xs font-semibold">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    <span x-text="file ? file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)' : ''"></span>
                                </div>
                            </template>
                        </div>

                        {{-- Busy state --}}
                        <div x-show="busy" x-cloak class="border-2 border-violet-200 dark:border-violet-800 rounded-xl p-8 text-center bg-violet-50/50 dark:bg-violet-900/10">
                            <svg class="mx-auto h-10 w-10 text-violet-600 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <p class="mt-3 text-sm font-bold text-violet-800 dark:text-violet-200" x-text="busyLabel"></p>
                            <p class="mt-1 text-xs text-violet-600 dark:text-violet-400">This usually takes 10–30 seconds. Please keep this page open.</p>
                        </div>

                        {{-- Error state --}}
                        <div x-show="error" x-cloak class="mt-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-4">
                            <p class="text-sm font-semibold text-red-700 dark:text-red-400" x-text="error"></p>
                            <ul class="mt-2 text-xs text-red-600 dark:text-red-500 list-disc list-inside space-y-0.5">
                                <li>Use a clear, well-lit photo or an original (not scanned) PDF when possible</li>
                                <li>Make sure the itemized section (descriptions, quantities, prices) is visible</li>
                                <li>Excel files: keep the invoice data on the first sheet</li>
                            </ul>
                            <button @click="reset()" class="mt-3 inline-flex items-center px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs font-bold uppercase tracking-wider hover:bg-red-700 transition">Try Again</button>
                        </div>

                        <div class="mt-4 flex items-center justify-between" x-show="!busy && !error">
                            <p class="text-[11px] text-gray-400 dark:text-gray-500">The file is read once and never stored on our servers.</p>
                            <button @click="upload()" :disabled="!file"
                                    :class="file ? 'bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-700 hover:to-indigo-700' : 'bg-gray-300 dark:bg-gray-700 cursor-not-allowed'"
                                    class="inline-flex items-center px-5 py-2.5 rounded-xl font-bold text-xs text-white uppercase tracking-widest transition">
                                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                                Read with AI
                            </button>
                        </div>
                        @endif
                    </div>

                    {{-- Recent attempts --}}
                    @if($recentParses->count())
                    <div class="premium-card p-6">
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wider mb-4">Recent Uploads</h3>
                        <div class="space-y-2">
                            @foreach($recentParses as $p)
                            <div class="flex items-center justify-between gap-3 py-2 px-3 rounded-lg bg-gray-50 dark:bg-gray-800/60">
                                <div class="min-w-0 flex items-center gap-2">
                                    @if($p->status === 'success')
                                    <span class="shrink-0 w-2 h-2 rounded-full bg-emerald-500"></span>
                                    @else
                                    <span class="shrink-0 w-2 h-2 rounded-full bg-red-500"></span>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-200 truncate">{{ $p->original_filename ?: strtoupper((string) $p->source_type) }}</p>
                                        <p class="text-[10px] text-gray-400">{{ $p->created_at->format('d M Y, h:i A') }}@if($p->status === 'failed') &middot; {{ \Illuminate\Support\Str::limit($p->error, 60) }}@endif</p>
                                    </div>
                                </div>
                                @if($p->status === 'success')
                                    @if($p->invoice_id)
                                    <span class="shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">Saved</span>
                                    @else
                                    <a href="/invoice/create?ai_parse={{ $p->id }}" class="shrink-0 text-[10px] font-bold px-2.5 py-1 rounded-lg bg-violet-600 text-white uppercase tracking-wider hover:bg-violet-700 transition">Review</a>
                                    @endif
                                @endif
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>

                {{-- Info column --}}
                <div class="lg:col-span-2 space-y-6">
                    <div class="premium-card p-6">
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wider mb-3">Monthly Usage</h3>
                        @if($quota['unlimited'])
                        <p class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ $quota['used'] }} parses this month <span class="text-xs font-semibold text-gray-400">&middot; unlimited</span></p>
                        @else
                        <div class="flex items-end justify-between mb-1.5">
                            <span class="text-2xl font-extrabold text-gray-900 dark:text-white">{{ $quota['used'] }}<span class="text-sm text-gray-400 font-bold"> / {{ $quota['quota'] }}</span></span>
                            <span class="text-[10px] font-bold uppercase tracking-wider {{ $quota['remaining'] > 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $quota['remaining'] }} left</span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                            <div class="h-full rounded-full bg-gradient-to-r from-violet-500 to-indigo-500" style="width: {{ min(100, (int) round($quota['used'] / max(1, $quota['quota']) * 100)) }}%"></div>
                        </div>
                        <p class="text-[10px] text-gray-400 mt-1.5">Resets on the 1st of every month. Failed reads don't count.</p>
                        @endif
                    </div>

                    <div class="premium-card p-6">
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wider mb-4">How It Works</h3>
                        <ol class="space-y-3">
                            <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300 text-xs font-extrabold flex items-center justify-center">1</span><p class="text-xs text-gray-600 dark:text-gray-300">Upload any invoice — supplier bill, old invoice PDF, a photo, or an Excel sheet.</p></li>
                            <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300 text-xs font-extrabold flex items-center justify-center">2</span><p class="text-xs text-gray-600 dark:text-gray-300">AI extracts the buyer, items, quantities and prices, then maps HS codes and tax schedules from your product list and the FBR schedule engine.</p></li>
                            <li class="flex gap-3"><span class="shrink-0 w-6 h-6 rounded-full bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300 text-xs font-extrabold flex items-center justify-center">3</span><p class="text-xs text-gray-600 dark:text-gray-300">You review everything on the invoice form — confidence badges mark uncertain fields — and save it as a <span class="font-bold">draft</span>.</p></li>
                        </ol>
                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
                            <p class="text-[11px] text-gray-400 dark:text-gray-500">Limits: 5MB per file &middot; first {{ \App\Services\AiInvoiceReaderService::MAX_PDF_PAGES }} PDF pages &middot; up to {{ \App\Services\AiInvoiceReaderService::MAX_ITEMS }} items per parse. Scanned PDFs work best uploaded as photos.</p>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>

    @if($allowed)
    <script>
        function aiReader() {
            return {
                file: null,
                busy: false,
                busyLabel: 'Uploading…',
                dragging: false,
                error: null,

                onFile(f) {
                    if (!f) return;
                    this.error = null;
                    if (f.size > {{ \App\Services\AiInvoiceReaderService::MAX_FILE_BYTES }}) {
                        this.error = 'File is too large — maximum size is 5MB.';
                        return;
                    }
                    this.file = f;
                },

                reset() {
                    this.error = null;
                    this.file = null;
                    this.busy = false;
                    if (this.$refs.fileInput) this.$refs.fileInput.value = '';
                },

                upload() {
                    if (!this.file || this.busy) return;
                    this.error = null;
                    this.busy = true;
                    this.busyLabel = 'Uploading…';

                    const fd = new FormData();
                    fd.append('invoice_file', this.file);
                    fd.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');

                    const controller = new AbortController();
                    const timer = setTimeout(() => controller.abort(), 150000);
                    setTimeout(() => { if (this.busy) this.busyLabel = 'AI is reading your invoice…'; }, 1500);

                    fetch('/invoices/ai-reader/parse', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd,
                        signal: controller.signal
                    })
                    .then(async r => {
                        clearTimeout(timer);
                        let d = null;
                        try { d = await r.json(); } catch (e) { d = null; }
                        if (r.ok && d && d.ok && d.redirect) {
                            this.busyLabel = 'Done! Opening the review screen…';
                            window.location.href = d.redirect;
                            return;
                        }
                        this.busy = false;
                        if (d && d.errors && d.errors.invoice_file) {
                            this.error = d.errors.invoice_file[0];
                        } else {
                            this.error = (d && d.error) ? d.error : 'Something went wrong. Please try again.';
                        }
                    })
                    .catch(() => {
                        clearTimeout(timer);
                        this.busy = false;
                        this.error = 'The request took too long or the connection dropped. Please try again.';
                    });
                }
            };
        }
    </script>
    @endif
</x-app-layout>
