@php
    // Encoded here (not via @json) so one malformed byte in a buyer name can
    // never blow up the Alpine component — see the bad-UTF-8 @json trap.
    $reviewPayload = json_encode([
        'rows' => $rows,
        'summary' => $summary,
        'provinces' => $provinces,
        'documentTypes' => $documentTypes,
        'scheduleTypes' => $scheduleTypes,
        'branches' => $branches,
        'urls' => [
            'rows' => route('invoices.batch-review.rows', [$batch['type'], $batch['ref']], false),
            'save' => route('invoices.batch-review.save', [$batch['type'], $batch['ref']], false),
            'bulkFix' => route('invoices.batch-review.bulk-fix', [$batch['type'], $batch['ref']], false),
            'matchBranches' => route('invoices.batch-review.match-branches', [$batch['type'], $batch['ref']], false),
            'bulkSubmit' => route('invoices.bulk-submit', [], false),
            'bulkSubmitStatus' => route('invoices.bulk-submit-status', [], false),
            'invoice' => url('/invoice', [], false),
        ],
        'csrf' => csrf_token(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($reviewPayload === false) {
        $reviewPayload = '{"rows":[],"summary":{"total":0,"ok":0,"error":0,"submitted":0}}';
    }
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100">Batch Review</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $batch['source_label'] }} &middot; {{ $batch['label'] }}
                    @if($batch['created_at']) &middot; {{ $batch['created_at'] }} @endif
                </p>
            </div>
            <a href="{{ route('invoices.index') }}" class="text-sm text-gray-600 dark:text-gray-300 hover:underline">&larr; Back to invoices</a>
        </div>
    </x-slot>

    <script type="application/json" id="batch-review-data">{!! $reviewPayload !!}</script>

    <div class="py-6" x-data="batchReview()" x-init="boot()">
        <div class="max-w-[100rem] mx-auto px-3 sm:px-6 lg:px-8 space-y-4">

            @if($truncated)
                <div class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-700 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
                    This batch created {{ number_format($totalInvoices) }} invoices. Only the first {{ number_format($maxReview) }} are shown here — review and submit these, the rest stay in your invoice list.
                </div>
            @endif

            {{-- Summary --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="premium-card p-4">
                    <div class="text-xs uppercase tracking-widest text-gray-500 dark:text-gray-400">Invoices</div>
                    <div class="text-2xl font-semibold text-gray-800 dark:text-gray-100" x-text="summary.total"></div>
                </div>
                <div class="premium-card p-4">
                    <div class="text-xs uppercase tracking-widest text-emerald-600 dark:text-emerald-400">Ready</div>
                    <div class="text-2xl font-semibold text-emerald-700 dark:text-emerald-300" x-text="summary.ok"></div>
                </div>
                <div class="premium-card p-4">
                    <div class="text-xs uppercase tracking-widest text-red-600 dark:text-red-400">Needs fix</div>
                    <div class="text-2xl font-semibold text-red-700 dark:text-red-300" x-text="summary.error"></div>
                </div>
                <div class="premium-card p-4">
                    <div class="text-xs uppercase tracking-widest text-gray-500 dark:text-gray-400">Already submitted</div>
                    <div class="text-2xl font-semibold text-gray-700 dark:text-gray-200" x-text="summary.submitted"></div>
                </div>
            </div>

            {{-- Toolbar --}}
            <div class="premium-card p-3 flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <button type="button" @click="filter = 'error'; page = 1"
                        :class="filter === 'error' ? 'bg-red-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300'"
                        class="px-3 py-1.5 text-xs font-medium">Needs fix</button>
                    <button type="button" @click="filter = 'all'; page = 1"
                        :class="filter === 'all' ? 'bg-gray-700 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300'"
                        class="px-3 py-1.5 text-xs font-medium border-l border-gray-200 dark:border-gray-700">All</button>
                    <button type="button" @click="filter = 'ok'; page = 1"
                        :class="filter === 'ok' ? 'bg-emerald-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300'"
                        class="px-3 py-1.5 text-xs font-medium border-l border-gray-200 dark:border-gray-700">Ready</button>
                </div>

                <input type="text" x-model="search" @input="page = 1" placeholder="Search buyer, invoice #, HS code…"
                    autocomplete="off" name="batch_review_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                    class="text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 w-64">

                <div class="flex-1"></div>

                <span x-show="dirtyCount > 0" x-cloak class="text-xs text-amber-700 dark:text-amber-300 font-medium"
                    x-text="dirtyCount + ' unsaved'"></span>

                <button type="button" @click="saveAll()" :disabled="dirtyCount === 0 || busy"
                    class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 text-white disabled:opacity-40 disabled:cursor-not-allowed">
                    <span x-show="!saving">Save changes</span>
                    <span x-show="saving" x-cloak>Saving…</span>
                </button>

                <a :href="exportUrl" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200">
                    Download Excel
                </a>

                <button type="button" @click="matchBranches()" :disabled="busy"
                    class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-teal-600 text-teal-700 dark:text-teal-300 disabled:opacity-40 disabled:cursor-not-allowed">
                    Match branch by city
                </button>

                <button type="button" @click="submitReady()" :disabled="busy || readyDraftIds().length === 0"
                    class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-teal-700 text-white disabled:opacity-40 disabled:cursor-not-allowed">
                    <span x-text="'Submit ready to FBR (' + readyDraftIds().length + ')'"></span>
                </button>
            </div>

            {{-- Notices --}}
            <template x-if="notice">
                <div class="rounded-lg px-4 py-2.5 text-sm"
                    :class="noticeType === 'error'
                        ? 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800'
                        : 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800'">
                    <span x-text="notice"></span>
                </div>
            </template>

            {{-- Submit progress --}}
            <template x-if="submitBatch">
                <div class="premium-card p-4 space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-200">Submitting to FBR…</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400"
                            x-text="(submitBatch.done || 0) + ' / ' + (submitBatch.total || 0)"></span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                        <div class="h-full bg-teal-600 transition-all"
                            :style="'width: ' + Math.round(((submitBatch.done || 0) / Math.max(1, submitBatch.total || 1)) * 100) + '%'"></div>
                    </div>
                    <div class="text-xs text-gray-600 dark:text-gray-300"
                        x-text="'Accepted: ' + (submitBatch.success || 0) + ' · Rejected: ' + (submitBatch.failed || 0)"></div>
                </div>
            </template>

            {{-- Empty state --}}
            <template x-if="visibleRows.length === 0">
                <div class="premium-card p-10 text-center">
                    <p class="text-sm text-gray-600 dark:text-gray-300"
                        x-text="rows.length === 0
                            ? 'This batch has no drafts to review.'
                            : (filter === 'error' ? 'Nothing left to fix in this batch.' : 'No rows match this filter.')"></p>
                </div>
            </template>

            {{-- Invoice cards --}}
            <template x-for="row in pagedRows" :key="row.id">
                <div class="premium-card overflow-hidden"
                    :class="row.status === 'error' ? 'border-l-4 border-l-red-500' : (row.status === 'ok' ? 'border-l-4 border-l-emerald-500' : 'border-l-4 border-l-gray-300')">

                    {{-- Card head --}}
                    <div class="flex items-center gap-3 flex-wrap px-4 py-2.5 bg-gray-50 dark:bg-gray-800/60 border-b border-gray-200 dark:border-gray-700">
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide"
                            :class="row.status === 'error' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                : (row.status === 'ok' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300')"
                            x-text="row.status === 'error' ? 'Needs fix' : (row.status === 'ok' ? 'Ready' : 'Submitted')"></span>

                        <a :href="urls.invoice + '/' + row.id" target="_blank"
                            class="text-sm font-semibold text-gray-800 dark:text-gray-100 hover:underline" x-text="row.number"></a>

                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="'Rs ' + row.total_amount"></span>

                        <template x-if="!row.editable">
                            <span class="text-[11px] text-gray-500 dark:text-gray-400 italic" x-text="row.lock_reason"></span>
                        </template>

                        <div class="flex-1"></div>

                        <template x-if="row._dirty">
                            <span class="text-[11px] font-medium text-amber-700 dark:text-amber-300">unsaved</span>
                        </template>
                    </div>

                    {{-- Issues --}}
                    <template x-if="row.issues.length">
                        <ul class="px-4 py-2 bg-red-50 dark:bg-red-900/10 border-b border-red-100 dark:border-red-900/40 space-y-0.5">
                            <template x-for="(issue, i) in row.issues" :key="i">
                                <li class="text-xs text-red-700 dark:text-red-300 flex gap-1.5">
                                    <span>&bull;</span><span x-text="issue"></span>
                                </li>
                            </template>
                        </ul>
                    </template>

                    {{-- Buyer fields --}}
                    <div class="px-4 py-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <template x-for="field in headerFields" :key="field.key">
                            <div>
                                <div class="flex items-center justify-between gap-1 mb-0.5">
                                    <label class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="field.label"></label>
                                    <button type="button" x-show="row.editable" @click="openBulkFix(field, row.header[field.key])"
                                        class="text-[10px] text-teal-700 dark:text-teal-400 hover:underline" title="Fix this value everywhere in the batch">fix all</button>
                                </div>
                                <template x-if="field.type === 'select'">
                                    <select :disabled="!row.editable" x-model="row.header[field.key]" @change="markDirty(row)"
                                        class="w-full text-xs rounded-lg dark:bg-gray-800 dark:text-gray-100 disabled:opacity-60"
                                        :class="row.header_issues[field.key] ? 'border-red-400 dark:border-red-600' : 'border-gray-300 dark:border-gray-600'">
                                        <option value=""></option>
                                        <template x-for="opt in field.options" :key="opt">
                                            <option :value="opt" x-text="opt"></option>
                                        </template>
                                    </select>
                                </template>
                                <template x-if="field.type !== 'select'">
                                    <input type="text" :disabled="!row.editable" x-model="row.header[field.key]" @input="markDirty(row)"
                                        autocomplete="off" :name="field.key + '_nofill'" data-lpignore="true" data-form-type="other" data-1p-ignore
                                        class="w-full text-xs rounded-lg dark:bg-gray-800 dark:text-gray-100 disabled:opacity-60"
                                        :class="row.header_issues[field.key] ? 'border-red-400 dark:border-red-600' : 'border-gray-300 dark:border-gray-600'">
                                </template>
                                <template x-if="row.header_issues[field.key]">
                                    <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5" x-text="row.header_issues[field.key]"></p>
                                </template>
                            </div>
                        </template>
                    </div>

                    {{-- Items --}}
                    <div class="px-4 pb-3 overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <template x-for="field in itemFields" :key="field.key">
                                        <th class="py-1 pr-2 font-medium uppercase tracking-wide text-[10px]" x-text="field.label"></th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <template x-for="(item, idx) in row.items" :key="item.id">
                                    <tr>
                                        <template x-for="field in itemFields" :key="field.key">
                                            <td class="py-1 pr-2 align-top">
                                                <div class="flex items-center gap-1">
                                                    <template x-if="field.type === 'select'">
                                                        <select :disabled="!row.editable" x-model="item[field.key]" @change="markDirty(row)"
                                                            class="text-xs rounded-lg dark:bg-gray-800 dark:text-gray-100 disabled:opacity-60"
                                                            :class="[field.w, item.issues[field.key] ? 'border-red-400 dark:border-red-600' : 'border-gray-300 dark:border-gray-600']">
                                                            <template x-for="opt in field.options" :key="opt">
                                                                <option :value="opt" x-text="opt"></option>
                                                            </template>
                                                        </select>
                                                    </template>
                                                    <template x-if="field.type !== 'select'">
                                                        <input type="text" :disabled="!row.editable" x-model="item[field.key]" @input="markDirty(row)"
                                                            autocomplete="off" :name="field.key + '_nofill'" data-lpignore="true" data-form-type="other" data-1p-ignore
                                                            class="text-xs rounded-lg dark:bg-gray-800 dark:text-gray-100 disabled:opacity-60"
                                                            :class="[field.w, item.issues[field.key] ? 'border-red-400 dark:border-red-600' : 'border-gray-300 dark:border-gray-600']">
                                                    </template>
                                                    <button type="button" x-show="row.editable" @click="openBulkFix(field, item[field.key])"
                                                        class="text-[10px] text-teal-700 dark:text-teal-400 hover:underline shrink-0" title="Fix this value everywhere in the batch">all</button>
                                                </div>
                                                <template x-if="item.issues[field.key]">
                                                    <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5 max-w-xs" x-text="item.issues[field.key]"></p>
                                                </template>
                                            </td>
                                        </template>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            {{-- Pagination --}}
            <template x-if="totalPages > 1">
                <div class="flex items-center justify-center gap-2 pt-2">
                    <button type="button" @click="page = Math.max(1, page - 1)" :disabled="page === 1"
                        class="px-3 py-1.5 text-xs rounded-lg border border-gray-300 dark:border-gray-600 disabled:opacity-40 text-gray-700 dark:text-gray-200">Previous</button>
                    <span class="text-xs text-gray-600 dark:text-gray-300" x-text="'Page ' + page + ' of ' + totalPages"></span>
                    <button type="button" @click="page = Math.min(totalPages, page + 1)" :disabled="page === totalPages"
                        class="px-3 py-1.5 text-xs rounded-lg border border-gray-300 dark:border-gray-600 disabled:opacity-40 text-gray-700 dark:text-gray-200">Next</button>
                </div>
            </template>
        </div>

        {{-- Bulk fix modal --}}
        <div x-show="bulkFix.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
            style="background: rgba(15,23,42,0.55)" @click.self="bulkFix.open = false">
            <div class="w-full max-w-lg rounded-xl bg-white dark:bg-gray-900 shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Fix this everywhere</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        Change one value across the whole batch in a single step. Invoices already sent to FBR are never touched.
                    </p>
                </div>
                <div class="px-5 py-4 space-y-3">
                    <div>
                        <label class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">Column</label>
                        <div class="text-sm font-medium text-gray-800 dark:text-gray-100" x-text="bulkFix.label"></div>
                    </div>
                    <div>
                        <label class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">Current value</label>
                        <input type="text" x-model="bulkFix.matchValue" autocomplete="off" name="bulk_match_nofill"
                            data-lpignore="true" data-form-type="other" data-1p-ignore
                            class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1"
                            x-text="bulkFixMatchCount() + ' row(s) in this batch have this value'"></p>
                    </div>
                    <div>
                        <label class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">New value</label>
                        <template x-if="bulkFix.options">
                            <select x-model="bulkFix.value" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                <option value=""></option>
                                <template x-for="opt in bulkFix.options" :key="opt">
                                    <option :value="opt" x-text="opt"></option>
                                </template>
                            </select>
                        </template>
                        <template x-if="!bulkFix.options">
                            <input type="text" x-model="bulkFix.value" autocomplete="off" name="bulk_value_nofill"
                                data-lpignore="true" data-form-type="other" data-1p-ignore
                                class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                        </template>
                    </div>
                </div>
                <div class="px-5 py-3 bg-gray-50 dark:bg-gray-800/60 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-2">
                    <button type="button" @click="bulkFix.open = false"
                        class="px-3 py-1.5 text-xs rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200">Cancel</button>
                    <button type="button" @click="applyBulkFix()" :disabled="busy || bulkFixMatchCount() === 0"
                        class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-teal-700 text-white disabled:opacity-40 disabled:cursor-not-allowed">
                        <span x-show="!busy">Apply to all</span>
                        <span x-show="busy" x-cloak>Applying…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Inlined on purpose: this layout has no @stack('scripts'). --}}
    <script>
        function batchReview() {
            var boot = {};
            try {
                boot = JSON.parse(document.getElementById('batch-review-data').textContent || '{}');
            } catch (e) {
                boot = {};
            }

            return {
                rows: boot.rows || [],
                summary: boot.summary || { total: 0, ok: 0, error: 0, submitted: 0 },
                urls: boot.urls || {},
                csrf: boot.csrf || '',
                exportUrl: (boot.urls && boot.urls.rows ? boot.urls.rows.replace(/\/rows$/, '/export') : '#'),

                headerFields: [
                    { key: 'invoice_date', label: 'Invoice date' },
                    { key: 'branch', label: 'Branch', type: 'select', options: boot.branches || [] },
                    { key: 'buyer_name', label: 'Buyer name' },
                    { key: 'buyer_ntn', label: 'Buyer NTN' },
                    { key: 'buyer_cnic', label: 'Buyer CNIC' },
                    { key: 'buyer_address', label: 'Buyer address' },
                    { key: 'destination_province', label: 'Province', type: 'select', options: boot.provinces || [] },
                    { key: 'document_type', label: 'Document type', type: 'select', options: boot.documentTypes || [] },
                    { key: 'reference_invoice_number', label: 'Reference invoice' },
                ],
                itemFields: [
                    { key: 'hs_code', label: 'HS code', w: 'w-28' },
                    { key: 'description', label: 'Description', w: 'w-56' },
                    { key: 'quantity', label: 'Qty', w: 'w-20' },
                    { key: 'price', label: 'Price', w: 'w-24' },
                    { key: 'tax', label: 'Tax', w: 'w-24' },
                    { key: 'tax_rate', label: 'Rate %', w: 'w-20' },
                    { key: 'schedule_type', label: 'Schedule', type: 'select', options: boot.scheduleTypes || [], w: 'w-36' },
                    { key: 'mrp', label: 'MRP', w: 'w-24' },
                    { key: 'sro_schedule_no', label: 'SRO', w: 'w-24' },
                    { key: 'serial_no', label: 'SRO serial', w: 'w-24' },
                ],

                filter: 'error',
                search: '',
                page: 1,
                perPage: 25,
                busy: false,
                saving: false,
                notice: '',
                noticeType: 'ok',
                submitBatch: null,
                submitTimer: null,
                bulkFix: { open: false, key: '', label: '', matchValue: '', value: '', options: null, isHeader: true },

                boot() {
                    if (this.summary.error === 0) {
                        this.filter = 'all';
                    }
                },

                get visibleRows() {
                    var q = this.search.trim().toLowerCase();
                    var self = this;

                    return this.rows.filter(function (row) {
                        if (self.filter === 'error' && row.status !== 'error') return false;
                        if (self.filter === 'ok' && row.status !== 'ok') return false;
                        if (!q) return true;

                        if ((row.number || '').toLowerCase().indexOf(q) !== -1) return true;
                        var header = row.header || {};
                        for (var k in header) {
                            if (String(header[k] || '').toLowerCase().indexOf(q) !== -1) return true;
                        }
                        for (var i = 0; i < row.items.length; i++) {
                            var item = row.items[i];
                            if (String(item.hs_code || '').toLowerCase().indexOf(q) !== -1) return true;
                            if (String(item.description || '').toLowerCase().indexOf(q) !== -1) return true;
                        }
                        return false;
                    });
                },

                get totalPages() {
                    return Math.max(1, Math.ceil(this.visibleRows.length / this.perPage));
                },

                get pagedRows() {
                    var p = Math.min(this.page, this.totalPages);
                    return this.visibleRows.slice((p - 1) * this.perPage, p * this.perPage);
                },

                get dirtyCount() {
                    return this.rows.filter(function (r) { return r._dirty; }).length;
                },

                markDirty(row) {
                    row._dirty = true;
                },

                readyDraftIds() {
                    return this.rows
                        .filter(function (r) { return r.status === 'ok' && r.editable && r.invoice_status === 'draft' && !r._dirty; })
                        .map(function (r) { return r.id; });
                },

                flash(message, type) {
                    this.notice = message;
                    this.noticeType = type || 'ok';
                    var self = this;
                    setTimeout(function () { self.notice = ''; }, 6000);
                },

                post(url, body) {
                    return fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(body),
                    });
                },

                replaceRow(fresh) {
                    for (var i = 0; i < this.rows.length; i++) {
                        if (this.rows[i].id === fresh.id) {
                            fresh._dirty = false;
                            this.rows.splice(i, 1, fresh);
                            return;
                        }
                    }
                },

                recomputeSummary() {
                    var s = { total: this.rows.length, ok: 0, error: 0, submitted: 0 };
                    this.rows.forEach(function (r) {
                        if (r.status === 'submitted') s.submitted++;
                        else if (r.status === 'error') s.error++;
                        else s.ok++;
                    });
                    this.summary = s;
                },

                async saveAll() {
                    var dirty = this.rows.filter(function (r) { return r._dirty; });
                    if (dirty.length === 0 || this.saving) return;

                    this.saving = true;
                    this.busy = true;
                    try {
                        var savedCount = 0;
                        var problems = [];

                        for (var start = 0; start < dirty.length; start += 50) {
                            var chunk = dirty.slice(start, start + 50).map(function (row) {
                                return {
                                    id: row.id,
                                    header: row.header,
                                    items: row.items.map(function (item) {
                                        return {
                                            id: item.id,
                                            hs_code: item.hs_code,
                                            description: item.description,
                                            quantity: item.quantity,
                                            price: item.price,
                                            tax: item.tax,
                                            tax_rate: item.tax_rate,
                                            schedule_type: item.schedule_type,
                                            mrp: item.mrp,
                                            sro_schedule_no: item.sro_schedule_no,
                                            serial_no: item.serial_no,
                                        };
                                    }),
                                };
                            });

                            var res = await this.post(this.urls.save, { invoices: chunk });
                            if (!res.ok) {
                                var text = await res.text();
                                throw new Error('Save failed (' + res.status + ') ' + text.slice(0, 200));
                            }
                            var data = await res.json();
                            (data.saved || []).forEach(this.replaceRow.bind(this));
                            savedCount += (data.saved || []).length;
                            (data.skipped || []).forEach(function (s) { problems.push(s.message); });
                        }

                        this.recomputeSummary();
                        if (problems.length) {
                            this.flash(savedCount + ' saved. ' + problems.length + ' skipped: ' + problems[0], 'error');
                        } else {
                            this.flash(savedCount + ' invoice(s) saved and re-checked.', 'ok');
                        }
                    } catch (e) {
                        this.flash(e.message || 'Could not save changes.', 'error');
                    } finally {
                        this.saving = false;
                        this.busy = false;
                    }
                },

                openBulkFix(field, currentValue) {
                    var isHeader = this.headerFields.some(function (f) { return f.key === field.key; });
                    this.bulkFix = {
                        open: true,
                        key: field.key,
                        label: field.label,
                        matchValue: currentValue === null || currentValue === undefined ? '' : String(currentValue),
                        value: '',
                        options: field.options && field.options.length ? field.options : null,
                        isHeader: isHeader,
                    };
                },

                bulkFixMatchCount() {
                    var needle = String(this.bulkFix.matchValue || '').trim().toLowerCase();
                    var key = this.bulkFix.key;
                    var isHeader = this.bulkFix.isHeader;
                    var count = 0;

                    this.rows.forEach(function (row) {
                        if (!row.editable) return;
                        if (isHeader) {
                            if (String(row.header[key] || '').trim().toLowerCase() === needle) count++;
                            return;
                        }
                        row.items.forEach(function (item) {
                            if (String(item[key] || '').trim().toLowerCase() === needle) count++;
                        });
                    });

                    return count;
                },

                async applyBulkFix() {
                    if (this.busy) return;
                    this.busy = true;
                    try {
                        var res = await this.post(this.urls.bulkFix, {
                            field: this.bulkFix.key,
                            match_value: this.bulkFix.matchValue,
                            value: this.bulkFix.value,
                        });
                        if (!res.ok) {
                            var text = await res.text();
                            throw new Error('Bulk fix failed (' + res.status + ') ' + text.slice(0, 200));
                        }
                        var data = await res.json();
                        this.rows = data.rows || [];
                        this.summary = data.summary || this.summary;
                        this.bulkFix.open = false;
                        this.flash(data.changed_rows + ' row(s) updated across ' + data.changed_invoices + ' invoice(s)'
                            + (data.skipped ? ' · ' + data.skipped + ' already-submitted invoice(s) left untouched' : '') + '.', 'ok');
                    } catch (e) {
                        this.flash(e.message || 'Could not apply the fix.', 'error');
                    } finally {
                        this.busy = false;
                    }
                },

                async refreshRows() {
                    try {
                        var res = await fetch(this.urls.rows, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res.ok) return;
                        var data = await res.json();
                        this.rows = data.rows || [];
                        this.summary = data.summary || this.summary;
                    } catch (e) {
                        /* a failed refresh leaves the last known state on screen */
                    }
                },

                async matchBranches() {
                    if (this.busy) return;
                    if (this.dirtyCount > 0) {
                        this.flash('Save your changes before matching branches.', 'error');
                        return;
                    }

                    this.busy = true;
                    try {
                        var res = await this.post(this.urls.matchBranches, {});
                        var data = await res.json();
                        if (!res.ok) {
                            throw new Error(data.error || data.message || 'Could not match branches.');
                        }
                        this.rows = data.rows || [];
                        this.summary = data.summary || this.summary;
                        this.flash((data.matched || 0) + ' draft(s) matched to a branch, '
                            + (data.no_match || 0) + ' had no matching city, '
                            + (data.ambiguous || 0) + ' matched more than one branch.'
                            + ((data.already_set || 0) ? ' ' + data.already_set + ' already had a branch.' : '')
                            + ((data.locked || 0) ? ' ' + data.locked + ' already sent to FBR were left alone.' : ''), 'ok');
                    } catch (e) {
                        this.flash(e.message || 'Could not match branches.', 'error');
                    } finally {
                        this.busy = false;
                    }
                },

                async submitReady() {
                    var ids = this.readyDraftIds();
                    if (ids.length === 0 || this.busy) return;
                    if (this.dirtyCount > 0) {
                        this.flash('Save your changes first — unsaved invoices are not submitted.', 'error');
                        return;
                    }

                    this.busy = true;
                    try {
                        var res = await this.post(this.urls.bulkSubmit, { invoice_ids: ids, status: 'draft' });
                        var data = await res.json();
                        if (!res.ok) {
                            if (res.status === 409 && data.batch_key) {
                                this.pollSubmit(data.batch_key);
                                this.flash('A bulk submit was already running — showing its progress.', 'ok');
                                return;
                            }
                            throw new Error(data.message || 'Could not start the submission.');
                        }
                        this.pollSubmit(data.batch_key);
                    } catch (e) {
                        this.flash(e.message || 'Could not start the submission.', 'error');
                        this.busy = false;
                    }
                },

                pollSubmit(batchKey) {
                    var self = this;
                    if (this.submitTimer) clearTimeout(this.submitTimer);

                    var tick = async function () {
                        try {
                            var res = await fetch(self.urls.bulkSubmitStatus + '?batch_key=' + encodeURIComponent(batchKey), {
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            });
                            if (res.ok) {
                                var data = await res.json();
                                self.submitBatch = data.batch || null;
                                if (self.submitBatch && self.submitBatch.finished) {
                                    self.busy = false;
                                    await self.refreshRows();
                                    self.flash('Submission finished — ' + (self.submitBatch.success || 0) + ' accepted, '
                                        + (self.submitBatch.failed || 0) + ' rejected.', (self.submitBatch.failed ? 'error' : 'ok'));
                                    self.submitTimer = setTimeout(function () { self.submitBatch = null; }, 8000);
                                    return;
                                }
                            }
                        } catch (e) {
                            /* keep polling — a dropped poll must never end the run */
                        }
                        self.submitTimer = setTimeout(tick, 2500);
                    };

                    tick();
                },
            };
        }
    </script>
</x-app-layout>
