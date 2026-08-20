<x-app-layout>
    <div class="py-8 pb-24" x-data="bulkAiImages()" x-init="init()">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="flex items-center text-xs text-gray-500 dark:text-gray-400 mb-2">
                <a href="/invoices" class="hover:text-violet-600">Invoices</a><span class="mx-2">/</span>
                <a href="{{ route('invoices.ai-reader') }}" class="hover:text-violet-600">AI Invoice Reader</a><span class="mx-2">/</span>
                <span class="font-semibold text-gray-800 dark:text-gray-200">Bulk photos</span>
            </nav>
            <div class="flex flex-wrap justify-between gap-4 items-start">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white">Bulk AI Image Import</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Each photo is treated as one separate source invoice. Nothing in this workspace is sent to FBR automatically.</p>
                </div>
                <a href="{{ route('invoices.ai-reader') }}" class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Single file reader</a>
            </div>

            @if(!$allowed)
            <div class="mt-6 rounded-2xl border border-violet-200 bg-violet-50 p-8 text-center dark:border-violet-800 dark:bg-violet-950/30">
                <h2 class="font-extrabold text-gray-900 dark:text-white">Bulk AI Image Import is a Premium feature</h2>
                <a href="/billing/plans" class="mt-4 inline-flex rounded-xl bg-violet-600 px-4 py-2 text-sm font-bold text-white">View Premium plans</a>
            </div>
            @elseif(!$configured)
            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">AI service is not configured yet. Please contact support.</div>
            @else
            <div class="mt-6 grid gap-6 lg:grid-cols-3">
                <section class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div x-show="!batchId">
                        <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-800 dark:text-gray-100">Select invoice photos</h2>
                        <p class="mt-1 text-xs text-gray-500">JPG, PNG, or WebP · up to 5MB each · maximum {{ \App\Services\BulkAiImageImportService::MAX_IMAGES }} photos per batch.</p>
                        <label class="mt-4 block cursor-pointer rounded-xl border-2 border-dashed border-violet-300 bg-violet-50/50 px-5 py-8 text-center hover:bg-violet-50 dark:border-violet-800 dark:bg-violet-950/20">
                            <input x-ref="files" type="file" class="hidden" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple @change="choose($event.target.files)">
                            <span class="block text-sm font-bold text-violet-800 dark:text-violet-200">Choose many separate invoice photos</span>
                            <span class="mt-1 block text-xs text-violet-600 dark:text-violet-400">Every selected file becomes its own review draft.</span>
                        </label>
                        <template x-if="files.length">
                            <div class="mt-4">
                                <div class="flex justify-between text-xs font-bold text-gray-600 dark:text-gray-300"><span x-text="files.length + ' source invoice(s) selected'"></span><button @click="clear()" class="text-red-600">Clear</button></div>
                                <div class="mt-2 max-h-52 space-y-1 overflow-y-auto">
                                    <template x-for="file in files" :key="file.name + file.size + file.lastModified">
                                        <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-gray-800"><span class="truncate pr-3" x-text="file.name"></span><span class="shrink-0 text-gray-400" x-text="size(file.size)"></span></div>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <button @click="start()" :disabled="busy || !files.length" class="mt-5 rounded-xl bg-violet-600 px-4 py-2.5 text-xs font-extrabold uppercase tracking-wider text-white disabled:cursor-not-allowed disabled:opacity-50">
                            <span x-text="busy ? busyLabel : 'Reserve allowance & upload'"></span>
                        </button>
                    </div>

                    <div x-show="batchId" x-cloak>
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-800 dark:text-gray-100">Batch progress</h2>
                            <span class="rounded-full bg-violet-100 px-2 py-1 text-[10px] font-bold text-violet-700 dark:bg-violet-900/40 dark:text-violet-200" x-text="(batch.processed || 0) + ' / ' + (batch.total || files.length) + ' processed'"></span>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-full bg-violet-600 transition-all" :style="'width:' + progress() + '%'"></div></div>
                        <p class="mt-2 text-xs text-gray-500" x-text="busy ? busyLabel : 'Photos are processed separately in the background. You may keep this page open to review live results.'"></p>

                        <div class="mt-5 overflow-x-auto">
                            <table class="min-w-full text-left text-xs">
                                <thead class="border-b border-gray-100 text-[10px] uppercase tracking-wider text-gray-400 dark:border-gray-800"><tr><th class="pb-2 pr-3">Photo</th><th class="pb-2 pr-3">Status</th><th class="pb-2 pr-3">Review notes</th><th class="pb-2">Action</th></tr></thead>
                                <tbody>
                                    <template x-for="item in batch.items || []" :key="item.id">
                                        <tr class="border-b border-gray-50 align-top dark:border-gray-800/70">
                                            <td class="max-w-[180px] py-3 pr-3 font-semibold text-gray-700 dark:text-gray-200"><span x-text="item.position + '. ' + item.filename"></span></td>
                                            <td class="py-3 pr-3"><span class="rounded-full px-2 py-1 text-[10px] font-bold" :class="statusClass(item.status)" x-text="label(item.status)"></span></td>
                                            <td class="max-w-sm py-3 pr-3 text-gray-500 dark:text-gray-400">
                                                <template x-if="item.warnings && item.warnings.length"><ul class="space-y-1"><template x-for="warning in item.warnings.slice(0,2)"><li x-text="warning"></li></template></ul></template>
                                                <template x-if="item.error"><span class="text-red-600" x-text="item.error"></span></template>
                                                <template x-if="!item.error && (!item.warnings || !item.warnings.length)"><span>—</span></template>
                                            </td>
                                            <td class="py-3 whitespace-nowrap">
                                                <a x-show="item.invoice_url" :href="item.invoice_url" class="font-bold text-violet-700 hover:text-violet-900 dark:text-violet-300">Open draft</a>
                                                <button x-show="item.retryable" @click="retry(item)" class="font-bold text-red-600 hover:text-red-800">Retry photo</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p x-show="error" x-cloak class="mt-4 rounded-lg bg-red-50 p-3 text-sm font-semibold text-red-700 dark:bg-red-950/30 dark:text-red-300" x-text="error"></p>
                </section>

                <aside class="space-y-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-800 dark:text-gray-100">Bulk AI allowance</h2>
                        @if($quota['unlimited'])
                        <p class="mt-2 text-sm font-bold text-emerald-600">Unlimited</p>
                        @else
                        <p class="mt-2 text-2xl font-extrabold text-gray-900 dark:text-white"><span x-text="quota.remaining"></span> <span class="text-xs text-gray-400">available</span></p>
                        <p class="mt-1 text-xs text-gray-500"><span x-text="quota.used"></span> used + <span x-text="quota.reserved"></span> reserved of {{ $quota['quota'] }} this month.</p>
                        @endif
                        <p class="mt-3 text-[11px] text-gray-400">One credit is reserved for every selected source invoice before uploading. A failed read releases its reservation.</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 text-xs leading-5 text-gray-600 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        <h2 class="mb-2 text-sm font-extrabold uppercase tracking-wider text-gray-800 dark:text-gray-100">Safe by default</h2>
                        <ul class="list-disc space-y-1 pl-4"><li>Same buyer does not merge photos.</li><li>Printed quantity, price, and tax stay intact.</li><li>Product mapping conflicts are flagged for review.</li><li>Photos are private and removed after {{ \App\Services\BulkAiImageImportService::RETENTION_DAYS }} days.</li></ul>
                    </div>
                </aside>
            </div>
            @endif
        </div>
    </div>

    @if($allowed && $configured)
    <script>
    function bulkAiImages() {
        return {
            files: [], batchId: null, batch: {items: []}, busy: false, busyLabel: '', error: null,
            quota: @json($quota),
            csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; },
            init() {},
            size(n) { return (n / 1024 / 1024).toFixed(2) + ' MB'; },
            choose(list) {
                this.error = null;
                this.files = Array.from(list || []).filter(f => /\.(jpe?g|png|webp)$/i.test(f.name) && f.size > 0 && f.size <= {{ \App\Services\BulkAiImageImportService::MAX_IMAGE_BYTES }});
                if (!this.files.length && list && list.length) this.error = 'Select JPG, PNG, or WebP invoice photos smaller than 5MB.';
                if (this.files.length > {{ \App\Services\BulkAiImageImportService::MAX_IMAGES }}) this.error = 'Select at most {{ \App\Services\BulkAiImageImportService::MAX_IMAGES }} photos in one batch.';
                this.files = this.files.slice(0, {{ \App\Services\BulkAiImageImportService::MAX_IMAGES }});
            },
            clear() { this.files = []; this.error = null; if (this.$refs.files) this.$refs.files.value = ''; },
            async json(url, options = {}) {
                const r = await fetch(url, {headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest', ...(options.headers || {})}, ...options});
                const data = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(data.error || 'Request failed. Please try again.');
                return data;
            },
            async start() {
                if (this.busy || !this.files.length) return;
                this.busy = true; this.error = null; this.busyLabel = 'Reserving AI allowance…';
                try {
                    const start = await this.json('/invoices/ai-reader/bulk-images/start', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf()}, body:JSON.stringify({files:this.files.map(f => ({name:f.name,size:f.size,type:f.type}))})});
                    this.batchId = start.batch_id; this.quota = start.quota; this.batch = {total:this.files.length,processed:0,items:start.items.map(i => ({...i,status:'not_started',warnings:[]}))};
                    for (let i = 0; i < this.files.length; i++) { this.busyLabel = 'Uploading photo ' + (i + 1) + ' of ' + this.files.length + '…'; await this.upload(start.items[i], this.files[i]); }
                    this.busy = false; this.poll(); this.timer = setInterval(() => this.poll(), 2500);
                } catch (e) { this.error = e.message; this.busy = false; }
            },
            async upload(item, file) {
                const bytes = {{ \App\Services\BulkAiImageImportService::CHUNK_BYTES }}, total = Math.ceil(file.size / bytes);
                for (let i = 0; i < total; i++) {
                    const form = new FormData(); form.append('chunk', file.slice(i * bytes, Math.min(file.size, (i + 1) * bytes)), file.name); form.append('index', i); form.append('total_chunks', total); form.append('_token', this.csrf());
                    await this.json('/invoices/ai-reader/bulk-images/' + this.batchId + '/items/' + item.id + '/chunk', {method:'POST', body:form});
                }
                await this.json('/invoices/ai-reader/bulk-images/' + this.batchId + '/items/' + item.id + '/complete', {method:'POST', headers:{'X-CSRF-TOKEN':this.csrf()}});
            },
            async poll() {
                if (!this.batchId) return;
                try { this.batch = await this.json('/invoices/ai-reader/bulk-images/' + this.batchId + '/status'); if (this.batch.processed >= this.batch.total && this.timer) clearInterval(this.timer); } catch (e) { this.error = e.message; }
            },
            async retry(item) {
                this.error = null;
                try { await this.json('/invoices/ai-reader/bulk-images/' + this.batchId + '/items/' + item.id + '/retry', {method:'POST',headers:{'X-CSRF-TOKEN':this.csrf()}}); await this.poll(); } catch(e) { this.error = e.message; }
            },
            progress() { return this.batch.total ? Math.round(((this.batch.processed || 0) / this.batch.total) * 100) : 0; },
            label(s) { return ({not_started:'Not started',uploading:'Uploading',queued:'Queued',processing:'Reading',ready:'Ready',needs_review:'Needs review',duplicate:'Duplicate',failed:'Failed'})[s] || s; },
            statusClass(s) { return ({ready:'bg-emerald-100 text-emerald-700',needs_review:'bg-amber-100 text-amber-800',duplicate:'bg-gray-200 text-gray-700',failed:'bg-red-100 text-red-700',processing:'bg-violet-100 text-violet-700',queued:'bg-blue-100 text-blue-700'})[s] || 'bg-gray-100 text-gray-600'; }
        }
    }
    </script>
    @endif
</x-app-layout>