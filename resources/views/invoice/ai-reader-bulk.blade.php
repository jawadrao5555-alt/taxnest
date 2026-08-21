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
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('invoices.ai-reader.bulk.history') }}" class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Batch history</a>
                    <a href="{{ route('invoices.ai-reader') }}" class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Single file reader</a>
                </div>
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
                        <div class="mt-4 rounded-xl border border-dashed border-emerald-300 bg-emerald-50/60 p-4 dark:border-emerald-800 dark:bg-emerald-950/20">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-extrabold text-emerald-800 dark:text-emerald-200">Optional: attach Product Master / Annexure</p>
                                    <p class="mt-1 text-[11px] text-emerald-700 dark:text-emerald-300">Excel or CSV reference data only — it is never created as an invoice. You will review its column mapping before photos are read.</p>
                                </div>
                                <label class="cursor-pointer rounded-lg border border-emerald-300 bg-white px-3 py-2 text-xs font-bold text-emerald-700 dark:border-emerald-700 dark:bg-gray-900 dark:text-emerald-300">
                                    <input x-ref="annexureFile" type="file" class="hidden" accept=".xlsx,.xls,.csv,.txt" @change="chooseAnnexure($event.target.files[0])">
                                    Choose Annexure
                                </label>
                            </div>
                            <p x-show="annexureFile" class="mt-2 text-xs font-semibold text-emerald-800 dark:text-emerald-200" x-text="annexureFile ? annexureFile.name + ' (' + size(annexureFile.size) + ')' : ''"></p>
                        </div>
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
                        <template x-if="batch.annexure_audits && batch.annexure_audits.length">
                            <div class="mb-5 rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                                <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">Annexure catalog history</h3>
                                <template x-for="audit in batch.annexure_audits" :key="audit.id">
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                        <span x-text="'Row ' + audit.annexure_row + ': ' + audit.action"></span>
                                        <span x-text="audit.decision"></span>
                                        <button x-show="audit.reversible" @click="reverseAnnexureAudit(audit)" class="font-semibold text-red-600 hover:text-red-800">Reverse catalog change</button>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <div x-show="annexure.status === 'mapping_pending'" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/20">
                            <h2 class="text-sm font-extrabold text-emerald-900 dark:text-emerald-100">Review Annexure column mapping</h2>
                            <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">Confirm the Product Master columns below. Invalid rows stay visible for manual correction and are never used as catalog data.</p>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                <template x-for="field in annexure.fields || []" :key="field.key">
                                    <label class="text-xs text-gray-700 dark:text-gray-200">
                                        <span class="font-bold" x-text="field.label + (field.required ? ' *' : '')"></span>
                                        <select class="mt-1 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" :value="annexure.mapping[field.key] || ''" @change="annexure.mapping[field.key] = $event.target.value">
                                            <option value="">Do not import</option>
                                            <template x-for="header in annexure.headers || []" :key="header"><option :value="header" x-text="header"></option></template>
                                        </select>
                                    </label>
                                </template>
                            </div>
                            <button @click="applyAnnexure()" :disabled="busy" class="mt-4 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-extrabold text-white disabled:opacity-50">Confirm mapping &amp; upload photos</button>
                        </div>
                        {{-- Task 1342: a reopened batch has no local files, so say plainly what can still be done with it. --}}
                        <div x-show="reopened" x-cloak class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 dark:border-violet-800 dark:bg-violet-950/20">
                            <p class="text-xs text-violet-800 dark:text-violet-200">
                                <span class="font-extrabold">Batch #<span x-text="batchId"></span> reopened.</span>
                                <span x-show="pendingUploads() > 0" x-text="' ' + pendingUploads() + ' photo(s) were never uploaded from the original tab — start a new batch to read those.'"></span>
                                <span x-show="pendingUploads() === 0"> Its review results, draft links, and summary download are below.</span>
                            </p>
                            <a href="{{ route('invoices.ai-reader.bulk') }}" class="rounded-lg bg-violet-600 px-3 py-1.5 text-[10px] font-extrabold uppercase tracking-wider text-white hover:bg-violet-700">Start a new batch</a>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-800 dark:text-gray-100">Batch progress</h2>
                            <div class="flex flex-wrap items-center gap-2">
                                <template x-if="(batch.processed || 0) > 0">
                                    <span class="flex items-center gap-2">
                                        <a :href="reportUrl('csv')" class="rounded-lg border border-gray-200 px-2 py-1 text-[10px] font-bold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Download CSV</a>
                                        <a :href="reportUrl('pdf')" class="rounded-lg border border-gray-200 px-2 py-1 text-[10px] font-bold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">Download PDF</a>
                                        <button type="button" @click="shareOpen = !shareOpen" class="rounded-lg border border-violet-200 bg-violet-50 px-2 py-1 text-[10px] font-bold text-violet-700 hover:bg-violet-100 dark:border-violet-800 dark:bg-violet-950/30 dark:text-violet-300">Email summary</button>
                                    </span>
                                </template>
                                <span class="rounded-full bg-violet-100 px-2 py-1 text-[10px] font-bold text-violet-700 dark:bg-violet-900/40 dark:text-violet-200" x-text="(batch.processed || 0) + ' / ' + (batch.total || files.length) + ' processed'"></span>
                            </div>
                        </div>
                        <p x-show="(batch.processed || 0) > 0" class="mt-2 text-[11px] text-gray-400">Share the review summary with another reviewer — it lists each source filename, its status, short notes, and the draft number. The private source photos are never included.</p>

                        {{-- Task 1343: email the PDF summary straight to another reviewer (e.g. the shop's accountant). --}}
                        <div x-show="shareOpen && (batch.processed || 0) > 0" x-cloak class="mt-3 rounded-xl border border-violet-200 bg-violet-50/60 p-4 dark:border-violet-800 dark:bg-violet-950/20">
                            <h3 class="text-xs font-extrabold uppercase tracking-wider text-violet-900 dark:text-violet-200">Email this summary</h3>
                            <p class="mt-1 text-[11px] text-violet-700 dark:text-violet-300">Email the PDF summary to someone else — your accountant, for example. Up to {{ \App\Services\BulkAiImageImportService::REPORT_SHARE_MAX_RECIPIENTS }} addresses, separated by commas. The private source photos are never attached.</p>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <input type="text" x-model="shareEmails" @keydown.enter.prevent="sendReport()" placeholder="accountant@example.com, reviewer@example.com" autocomplete="off" name="bulk_share_emails_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore="true" class="min-w-[260px] flex-1 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                                <button type="button" @click="sendReport()" :disabled="shareBusy || !shareEmails.trim()" class="rounded-lg bg-violet-600 px-3 py-2 text-[11px] font-extrabold uppercase tracking-wider text-white disabled:cursor-not-allowed disabled:opacity-50">
                                    <span x-text="shareBusy ? 'Sending…' : 'Send summary'"></span>
                                </button>
                            </div>
                            <p x-show="shareMessage" x-cloak class="mt-2 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300" x-text="shareMessage"></p>
                            <p x-show="shareError" x-cloak class="mt-2 text-[11px] font-semibold text-red-600 dark:text-red-400" x-text="shareError"></p>

                            <template x-if="(batch.report_shares || []).length">
                                <div class="mt-3 border-t border-violet-200 pt-3 dark:border-violet-800">
                                    <p class="text-[10px] font-extrabold uppercase tracking-wider text-violet-800 dark:text-violet-300">Sent to</p>
                                    <ul class="mt-1 space-y-1">
                                        <template x-for="share in batch.report_shares" :key="share.id">
                                            <li class="flex flex-wrap items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300">
                                                <span class="font-semibold" x-text="share.recipient"></span>
                                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="share.status === 'sent' ? 'bg-emerald-100 text-emerald-700' : (share.status === 'queued' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700')" x-text="share.status === 'sent' ? 'Sent' : (share.status === 'queued' ? 'Sending…' : 'Failed')"></span>
                                                <span class="text-gray-400" x-text="share.at + (share.sent_by ? ' · ' + share.sent_by : '')"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>
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
                                                <template x-if="item.details && item.details.annexure_matches && item.details.annexure_matches.length">
                                                    <div class="mt-2 space-y-1 rounded-lg bg-emerald-50 p-2 text-[11px] text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">
                                                        <template x-for="match in item.details.annexure_matches" :key="match.line_index">
                                                            <div class="space-y-1">
                                                                <span class="font-bold" x-text="'Annexure: ' + match.status"></span>
                                                                <span x-text="' — ' + match.explanation"></span>
                                                                <template x-if="match.status === 'matched' && match.entry">
                                                                    <span class="ml-1">row <span x-text="match.source_row"></span></span>
                                                                </template>
                                                                <template x-if="match.price_conflict">
                                                                    <span class="ml-1 font-semibold text-amber-700 dark:text-amber-300" x-text="'Catalog price ' + match.catalog_price + ' differs from Annexure price ' + match.annexure_price"></span>
                                                                </template>
                                                                <template x-if="match.status === 'matched' && match.entry">
                                                                    <span class="mt-1 flex flex-wrap items-center gap-1">
                                                                        <select class="rounded border-gray-300 py-1 text-[10px] dark:border-gray-700 dark:bg-gray-900" :value="priceDecisions[item.id + ':' + match.line_index] || 'keep_current'" @change="priceDecisions[item.id + ':' + match.line_index] = $event.target.value">
                                                                            <option value="keep_current">Keep catalog price</option>
                                                                            <option value="update_catalog">Update catalog price</option>
                                                                            <option value="batch_only">Annexure reference only (invoice stays printed)</option>
                                                                        </select>
                                                                        <details class="relative">
                                                                            <summary class="cursor-pointer text-[10px] text-gray-600 underline dark:text-gray-300">Fields</summary>
                                                                            <div class="absolute left-0 z-20 mt-1 grid w-44 grid-cols-2 gap-1 rounded border border-gray-200 bg-white p-2 text-[10px] shadow-lg dark:border-gray-700 dark:bg-gray-900">
                                                                                <template x-for="field in metadataKeys" :key="field">
                                                                                    <label class="flex items-center gap-1"><input type="checkbox" :checked="(metadataFields[item.id + ':' + match.line_index] || metadataKeys).includes(field)" @change="toggleMetadataField(item.id + ':' + match.line_index, field, $event.target.checked)" class="rounded border-gray-300"><span x-text="field.replaceAll('_', ' ')"></span></label>
                                                                                </template>
                                                                            </div>
                                                                        </details>
                                                                        <button @click="saveMatchedAnnexure(item, match)" class="font-bold text-emerald-700 hover:text-emerald-900 dark:text-emerald-300">Save this product</button>
                                                                    </span>
                                                                </template>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
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
            files: [], annexureFile: null, annexure: {status: 'none', headers: [], fields: [], mapping: {}, rows: []},
            batchId: null, batch: {items: []}, priceDecisions: {}, metadataFields: {}, metadataKeys: ['name','barcode','sku','hs_code','pct_code','uom','default_tax_rate','tax_type','schedule_type','sro_reference','serial_number','mrp'], busy: false, busyLabel: '', error: null,
            shareOpen: false, shareEmails: '', shareBusy: false, shareMessage: '', shareError: '',
            reopened: false, timer: null,
            quota: @json($quota),
            csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; },
            // Task 1342: ?batch= restores a past batch — the results table, the
            // draft links, the per-photo retry, and the summary download.
            init() {
                const saved = {{ (int) ($openBatchId ?? 0) }};
                if (!saved) return;
                this.batchId = saved;
                this.reopened = true;
                this.reopen();
            },
            async reopen() {
                this.busy = true; this.busyLabel = 'Loading this batch…';
                await this.poll();
                this.busy = false;
                // A batch reopened while its photos are still being read keeps updating.
                if (!this.timer && (this.batch.processed || 0) < (this.batch.total || 0)) this.timer = setInterval(() => this.poll(), 2500);
            },
            pendingUploads() { return (this.batch.items || []).filter(i => i.status === 'not_started' || i.status === 'uploading').length; },
            size(n) { return (n / 1024 / 1024).toFixed(2) + ' MB'; },
            choose(list) {
                this.error = null;
                this.files = Array.from(list || []).filter(f => /\.(jpe?g|png|webp)$/i.test(f.name) && f.size > 0 && f.size <= {{ \App\Services\BulkAiImageImportService::MAX_IMAGE_BYTES }});
                if (!this.files.length && list && list.length) this.error = 'Select JPG, PNG, or WebP invoice photos smaller than 5MB.';
                if (this.files.length > {{ \App\Services\BulkAiImageImportService::MAX_IMAGES }}) this.error = 'Select at most {{ \App\Services\BulkAiImageImportService::MAX_IMAGES }} photos in one batch.';
                this.files = this.files.slice(0, {{ \App\Services\BulkAiImageImportService::MAX_IMAGES }});
            },
            chooseAnnexure(file) {
                this.error = null;
                if (!file) return;
                if (!/\.(xlsx|xls|csv|txt)$/i.test(file.name) || file.size < 1 || file.size > {{ \App\Services\AnnexureProductService::MAX_FILE_BYTES }}) {
                    this.error = 'Choose an Excel or CSV Annexure smaller than 5MB.';
                    return;
                }
                this.annexureFile = file;
            },
            clear() { this.files = []; this.annexureFile = null; this.error = null; if (this.$refs.files) this.$refs.files.value = ''; if (this.$refs.annexureFile) this.$refs.annexureFile.value = ''; },
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
                    if (this.annexureFile) {
                        this.busyLabel = 'Uploading Annexure for column review…';
                        const form = new FormData(); form.append('annexure', this.annexureFile); form.append('_token', this.csrf());
                        this.annexure = await this.json('/invoices/ai-reader/bulk-images/' + this.batchId + '/annexure', {method:'POST', body:form});
                        this.annexure.mapping = this.annexure.suggested_mapping || {};
                        this.busy = false;
                    } else {
                        await this.uploadPhotos(start.items);
                    }
                } catch (e) { this.error = e.message; this.busy = false; }
            },
            async applyAnnexure() {
                if (this.busy) return;
                this.busy = true; this.error = null; this.busyLabel = 'Saving approved Annexure mapping…';
                try {
                    const result = await this.json('/invoices/ai-reader/bulk-images/' + this.batchId + '/annexure/apply', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf()}, body:JSON.stringify({mapping:this.annexure.mapping})});
                    this.annexure = {...this.annexure, ...result};
                    await this.uploadPhotos(this.batch.items);
                } catch (e) { this.error = e.message; this.busy = false; }
            },
            async uploadPhotos(items) {
                for (let i = 0; i < this.files.length; i++) { this.busyLabel = 'Uploading photo ' + (i + 1) + ' of ' + this.files.length + '…'; await this.upload(items[i], this.files[i]); }
                this.busy = false; await this.poll(); this.timer = setInterval(() => this.poll(), 2500);
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
            async saveMatchedAnnexure(item, match) {
                if (!match || match.status !== 'matched' || !match.entry) return;
                const line = (item.details.mapping || []).find(m => m.annexure_match && Number(m.annexure_match.source_row) === Number(match.source_row));
                const existingProductId = line && line.product_id ? line.product_id : null;
                const key = item.id + ':' + match.line_index;
                const priceDecision = this.priceDecisions[key] || 'keep_current';
                try {
                    await this.json('/invoices/ai-reader/bulk-images/' + this.batchId + '/annexure/catalog', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf()}, body:JSON.stringify({annexure_row:match.source_row, action:existingProductId ? 'update' : 'create', product_id:existingProductId, price_decision:priceDecision, fields: existingProductId ? (this.metadataFields[key] || this.metadataKeys) : undefined})});
                    await this.poll();
                } catch (e) { this.error = e.message; }
            },
            async reverseAnnexureAudit(audit) {
                if (!confirm('Reverse this catalog decision? Invoice lines will remain unchanged.')) return;
                try {
                    await this.json('/invoices/ai-reader/bulk-images/' + this.batchId + '/annexure/audits/' + audit.id + '/reverse', {method:'POST', headers:{'X-CSRF-TOKEN':this.csrf()}});
                    await this.poll();
                } catch (e) { this.error = e.message; }
            },
            toggleMetadataField(itemId, field, checked) {
                const selected = [...(this.metadataFields[itemId] || this.metadataKeys)];
                this.metadataFields[itemId] = checked ? [...new Set([...selected, field])] : selected.filter(key => key !== field);
            },
            reportUrl(format) { return '/invoices/ai-reader/bulk-images/' + this.batchId + '/report?format=' + format; },
            async sendReport() {
                if (this.shareBusy || !this.batchId || !this.shareEmails.trim()) return;
                this.shareBusy = true; this.shareMessage = ''; this.shareError = '';
                try {
                    const result = await this.json('/invoices/ai-reader/bulk-images/' + this.batchId + '/report/email', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf()}, body:JSON.stringify({recipients:this.shareEmails})});
                    this.shareMessage = result.message || 'Review summary sent.';
                    this.shareEmails = '';
                    if (result.shares) this.batch = {...this.batch, report_shares: result.shares};
                } catch (e) { this.shareError = e.message; }
                this.shareBusy = false;
            },
            progress() { return this.batch.total ? Math.round(((this.batch.processed || 0) / this.batch.total) * 100) : 0; },
            label(s) { return ({not_started:'Not started',uploading:'Uploading',queued:'Queued',processing:'Reading',ready:'Ready',needs_review:'Needs review',duplicate:'Duplicate',failed:'Failed'})[s] || s; },
            statusClass(s) { return ({ready:'bg-emerald-100 text-emerald-700',needs_review:'bg-amber-100 text-amber-800',duplicate:'bg-gray-200 text-gray-700',failed:'bg-red-100 text-red-700',processing:'bg-violet-100 text-violet-700',queued:'bg-blue-100 text-blue-700'})[s] || 'bg-gray-100 text-gray-600'; }
        }
    }
    </script>
    @endif
</x-app-layout>