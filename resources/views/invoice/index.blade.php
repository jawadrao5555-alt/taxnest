<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <nav class="flex items-center text-xs text-gray-500 dark:text-gray-400 mb-1.5">
                        <a href="{{ route('dashboard') }}" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition font-medium">Dashboard</a>
                        <svg class="w-3.5 h-3.5 mx-1.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span class="text-gray-800 dark:text-gray-200 font-semibold">Invoices</span>
                    </nav>
                    <h2 class="font-extrabold text-2xl text-gray-900 dark:text-white leading-tight tracking-tight">Invoices</h2>
                </div>
                <div class="flex gap-2 items-center flex-wrap">
                    {{-- Download ALL invoices as ZIP (works on any tab, no date filter) --}}
                    <a href="{{ route('invoices.bulk-pdf', ['all' => 1]) }}"
                       onclick="if(!confirm('Download ALL completed invoices as ZIP (latest 500)?\n\nNote: this may take 30-60 seconds.')){event.preventDefault();return false;} this.classList.add('opacity-60','pointer-events-none'); this.querySelector('span').textContent='Preparing ZIP...';"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 text-white rounded-xl text-xs font-bold uppercase tracking-widest hover:from-emerald-700 hover:to-teal-700 transition shadow-md"
                       title="Download all completed invoices in one ZIP">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download ALL ZIP</span>
                    </a>
                    {{-- WHT on PDF is now per-invoice: the row renders automatically
                         whenever the invoice has a WHT amount applied (session toggle removed). --}}
                    <a href="{{ route('invoices.import-history') }}"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:border-blue-300 dark:hover:border-blue-700 transition"
                       title="See past bulk imports and re-download error reports">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>Import History</span>
                    </a>
                    {{-- Task 142: AI Invoice Reader entry (Premium gate 'ai_reader') --}}
                    <a href="/invoices/ai-reader" class="btn-premium inline-flex items-center px-4 py-2.5 bg-gradient-to-r from-violet-600 to-indigo-600 border border-transparent rounded-xl font-bold text-xs text-white uppercase tracking-widest hover:from-violet-700 hover:to-indigo-700 transition">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                        AI Reader
                        @if(empty($aiReaderAllowed))
                            <span class="ml-1.5 text-[9px] bg-white/20 rounded px-1.5 py-0.5">PREMIUM</span>
                        @endif
                    </a>
                    @if(!empty($aiReaderAllowed))
                    <a href="{{ route('invoices.ai-reader.bulk') }}" class="inline-flex items-center px-3 py-2.5 border border-violet-200 dark:border-violet-800 rounded-xl font-bold text-xs text-violet-700 dark:text-violet-300 uppercase tracking-wider hover:bg-violet-50 dark:hover:bg-violet-950/30 transition" title="Import many invoice photos as separate drafts">Bulk photos</a>
                    @endif
                    <div x-data="bulkImport()" x-cloak>
                        <button @click="openModal()" class="btn-premium inline-flex items-center px-4 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 border border-transparent rounded-xl font-bold text-xs text-white uppercase tracking-widest hover:from-blue-700 hover:to-blue-800 transition">
                            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                            Bulk Import
                        </button>

                        <div x-show="showModal" class="fixed inset-0 z-50 flex items-center justify-center" style="background-color: rgba(0,0,0,0.5);">
                            <div @click.away="if (step !== 'processing') closeModal()" class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-800 w-full max-w-4xl max-h-[90vh] overflow-y-auto mx-4">
                                <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">Bulk Import Invoices (Excel / CSV)</h3>
                                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:text-gray-400 text-2xl leading-none">&times;</button>
                                </div>

                                <div class="p-6">
                                    <div x-show="step === 'upload'" class="space-y-4">
                                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                                            <p class="text-sm text-blue-700 dark:text-blue-300 mb-2">Download the Excel template, fill in your invoice data, then upload it here. Each row is one invoice line item — rows with the same buyer name + NTN + document type are grouped into a single draft invoice. Every row is checked against FBR rules (HS code, schedule, SRO, buyer fields) <span class="font-semibold">before</span> anything is created.</p>
                                            <div class="flex flex-wrap items-center gap-4">
                                                <a href="/invoices/import-template" class="inline-flex items-center text-sm font-semibold text-blue-600 hover:text-blue-800">
                                                    <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                    Download Excel Template (.xlsx)
                                                </a>
                                                <a href="/invoices/csv-template" class="inline-flex items-center text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 underline">CSV template (legacy)</a>
                                            </div>
                                            <p class="text-xs text-blue-600/80 dark:text-blue-400/80 mt-2">The Excel template keeps code columns (HS code, NTN, CNIC) as Text so Excel cannot mangle them. Limits: 10,000 rows / 10 MB per file. Large files are processed in the background with live progress.</p>
                                        </div>

                                        <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-8 text-center">
                                            <input type="file" accept=".xlsx,.xls,.csv,.txt" @change="handleFileUpload($event)" class="hidden" x-ref="importInput">
                                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Select an Excel (.xlsx) or CSV file to upload</p>
                                            <button @click="$refs.importInput.click()" class="mt-3 px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition" :disabled="uploading">
                                                <span x-show="!uploading">Choose File</span>
                                                <span x-show="uploading">Checking rows...</span>
                                            </button>
                                        </div>

                                        <div x-show="uploadError" class="bg-red-50 border border-red-200 rounded-lg p-3">
                                            <p class="text-sm text-red-700" x-text="uploadError"></p>
                                        </div>
                                    </div>

                                    {{-- Task 1230: DMS-export column mapping — file headers don't match the template --}}
                                    <div x-show="step === 'mapping'" class="space-y-4">
                                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-1">This file's columns don't match the TaxNest template — map them below (one time).</p>
                                            <p class="text-xs text-amber-700/80 dark:text-amber-400/80">Works with day-end exports from your company's distribution software (Voyage, TMX, Salesflo, etc.). Pick which file column feeds each TaxNest field. Fields your export doesn't have can take a fixed value instead. Save the mapping as a preset and the next upload of this export imports in one click.</p>
                                            <p class="text-xs text-amber-700 dark:text-amber-400 mt-1 font-medium">File: <span x-text="mappingFilename"></span></p>
                                        </div>

                                        <div x-show="presets.length > 0" class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                                            <p class="text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-wider mb-2">Saved Mapping Presets</p>
                                            <div class="space-y-1.5">
                                                <template x-for="p in presets" :key="p.id">
                                                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                                        <div class="min-w-0 flex items-center gap-2">
                                                            <span class="font-medium text-gray-800 dark:text-gray-200 truncate" x-text="p.name"></span>
                                                            <span x-show="p.matches" class="px-1.5 py-0.5 rounded text-xs font-bold bg-green-100 text-green-700">MATCHES THIS FILE</span>
                                                            <span x-show="!p.matches" class="px-1.5 py-0.5 rounded text-xs font-bold bg-gray-100 text-gray-500" :title="'Columns not in this file: ' + (p.missing_columns || []).join(', ')">DIFFERENT COLUMNS</span>
                                                        </div>
                                                        <div class="flex items-center gap-1.5">
                                                            <button x-show="p.matches" @click="applyMapping(p.id)" :disabled="applyingMapping" class="px-3 py-1 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700 transition disabled:opacity-50">Apply</button>
                                                            <button @click="renamePreset(p)" class="px-2 py-1 text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 underline">Rename</button>
                                                            <button @click="deletePreset(p)" class="px-2 py-1 text-xs text-red-500 hover:text-red-700 underline">Delete</button>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>

                                        @if(!empty($aiReaderAllowed))
                                        {{-- Task 1238: AI mapping suggestions (opt-in; fills only unresolved fields; user can change everything) --}}
                                        <div class="flex flex-wrap items-center gap-2">
                                            <button @click="suggestAiMapping()" :disabled="aiMapLoading || !aiConfigured" class="inline-flex items-center px-3 py-1.5 bg-violet-600 text-white rounded-lg text-xs font-bold hover:bg-violet-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                                <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                                                <span x-show="!aiMapLoading">AI Suggest Mapping</span>
                                                <span x-show="aiMapLoading" x-cloak>Asking AI...</span>
                                            </button>
                                            <span x-show="!aiConfigured" class="text-xs text-gray-400 dark:text-gray-500">AI suggestions unavailable — the AI service is not configured yet. Manual mapping works as usual.</span>
                                            <span x-show="aiMapDone && !aiMapError" x-cloak class="text-xs font-medium text-violet-600 dark:text-violet-400">
                                                <template x-if="aiMapCount > 0"><span>AI filled <span x-text="aiMapCount"></span> suggestion(s) — marked <span class="px-1 rounded bg-violet-100 text-violet-700 font-bold">AI</span>. Review and change anything before continuing.</span></template>
                                                <template x-if="aiMapCount === 0"><span>AI had no further suggestions for the remaining fields.</span></template>
                                            </span>
                                        </div>
                                        <p x-show="aiMapError" x-cloak class="text-xs text-red-600 dark:text-red-400" x-text="aiMapError"></p>
                                        @endif

                                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                            <div class="grid grid-cols-12 gap-2 px-3 py-2 bg-gray-50 dark:bg-gray-800 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                <div class="col-span-4">TaxNest Field</div>
                                                <div class="col-span-4">Column in Your File</div>
                                                <div class="col-span-4">Fixed Value (when no column)</div>
                                            </div>
                                            <div class="divide-y divide-gray-100 dark:divide-gray-800" style="max-height: 45vh; overflow-y: auto;">
                                                <template x-for="f in mappingFields" :key="f.key">
                                                    <div class="grid grid-cols-12 gap-2 items-center px-3 py-2">
                                                        <div class="col-span-4">
                                                            <p class="text-xs font-semibold text-gray-800 dark:text-gray-200"><span x-text="f.key"></span><span x-show="f.value_required" class="text-red-500">*</span><span x-show="aiPicked[f.key]" x-cloak class="ml-1.5 px-1 py-0.5 rounded text-xs font-bold bg-violet-100 text-violet-700" title="Suggested by AI — change it if it's wrong">AI</span></p>
                                                            <p class="text-xs text-gray-400 dark:text-gray-500" x-text="f.hint"></p>
                                                        </div>
                                                        <div class="col-span-4">
                                                            <select x-model="mappingSel[f.key]" @change="aiPicked[f.key] = false" class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 py-1.5">
                                                                <option value="">— no column —</option>
                                                                <template x-for="h in fileHeaders" :key="h">
                                                                    <option :value="h" x-text="h"></option>
                                                                </template>
                                                            </select>
                                                        </div>
                                                        <div class="col-span-4">
                                                            <template x-if="!mappingSel[f.key] && f.options">
                                                                <select x-model="defaultsSel[f.key]" @change="aiPicked[f.key] = false" class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 py-1.5">
                                                                    <option value="">— none —</option>
                                                                    <template x-for="o in f.options" :key="o">
                                                                        <option :value="o" x-text="o"></option>
                                                                    </template>
                                                                </select>
                                                            </template>
                                                            <template x-if="!mappingSel[f.key] && !f.options">
                                                                <input type="text" x-model="defaultsSel[f.key]" @input="aiPicked[f.key] = false" placeholder="Fixed value for every row" class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 py-1.5">
                                                            </template>
                                                            <p x-show="mappingSel[f.key]" class="text-xs text-gray-400 dark:text-gray-500">Using column &ldquo;<span x-text="mappingSel[f.key]"></span>&rdquo;</p>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-3">
                                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                                <input type="checkbox" x-model="savePreset" class="rounded border-gray-300 text-emerald-600">
                                                <span>Save this mapping as a preset</span>
                                            </label>
                                            <input x-show="savePreset" type="text" x-model="presetName" maxlength="100" placeholder="Preset name, e.g. Coca-Cola Voyage export" class="flex-1 text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 py-1.5" style="min-width: 220px;">
                                        </div>

                                        <div x-show="mappingError" class="bg-red-50 border border-red-200 rounded-lg p-3">
                                            <p class="text-sm text-red-700" x-text="mappingError"></p>
                                        </div>

                                        <div class="flex items-center justify-end gap-2">
                                            <button @click="resetUpload()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-xs font-medium hover:bg-gray-300 transition">Upload Different File</button>
                                            <button @click="applyMapping()" :disabled="applyingMapping" class="px-5 py-2 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                                <span x-show="!applyingMapping">Continue to Preview</span>
                                                <span x-show="applyingMapping">Mapping rows...</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div x-show="step === 'preview'" class="space-y-4">
                                        <p x-show="presetSavedName" class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Mapping preset &ldquo;<span x-text="presetSavedName"></span>&rdquo; saved — next time this export imports in one click.</p>
                                        <p x-show="presetCapWarning" class="text-xs font-medium text-amber-600 dark:text-amber-400">The mapping preset was NOT saved — you already have the maximum number of saved presets. Delete an old preset first. (The import itself continues normally.)</p>
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex flex-wrap gap-2">
                                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-700 dark:text-gray-300">Total: <span x-text="totalRows"></span></span>
                                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700">Valid: <span x-text="validCount"></span></span>
                                                <span x-show="errorCount > 0" class="px-3 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700">Errors: <span x-text="errorCount"></span></span>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <a x-show="errorReportUrl" :href="errorReportUrl" class="inline-flex items-center px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-xs font-bold hover:bg-red-200 transition">
                                                    <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                    Error Report (.xlsx)
                                                </a>
                                                <button @click="resetUpload()" class="px-3 py-1.5 bg-gray-200 text-gray-700 dark:text-gray-300 rounded-lg text-xs font-medium hover:bg-gray-300 transition">Upload Different File</button>
                                                <button @click="startProcessing()" :disabled="processing || validCount === 0" class="px-4 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                                    <span x-show="!processing">Create <span x-text="validCount"></span> Draft Row(s)</span>
                                                    <span x-show="processing">Starting...</span>
                                                </button>
                                            </div>
                                        </div>

                                        <div x-show="errorCount > 0" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                                            <p class="text-sm font-semibold text-red-700 dark:text-red-300 mb-1">Rows with errors (will be skipped) — download the error report for the full fix-and-reupload list:</p>
                                            @if(!empty($aiReaderAllowed))
                                            {{-- Task 1238: AI fix suggestions for failing rows (opt-in; nothing applies without confirmation) --}}
                                            <div class="mt-1 mb-2 flex flex-wrap items-center gap-2">
                                                <button @click="getAiFixes()" :disabled="aiFixLoading || !aiConfigured" class="inline-flex items-center px-3 py-1.5 bg-violet-600 text-white rounded-lg text-xs font-bold hover:bg-violet-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                                    <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                                                    <span x-show="!aiFixLoading">Get AI Fix Suggestions</span>
                                                    <span x-show="aiFixLoading" x-cloak>Asking AI...</span>
                                                </button>
                                                <span x-show="!aiConfigured" class="text-xs text-gray-400 dark:text-gray-500">AI suggestions unavailable — the AI service is not configured yet.</span>
                                                <span x-show="aiFixNote" x-cloak class="text-xs text-amber-700 dark:text-amber-400" x-text="aiFixNote"></span>
                                            </div>
                                            <p x-show="aiFixError" x-cloak class="mb-2 text-xs text-red-700 dark:text-red-300 font-medium" x-text="aiFixError"></p>
                                            @endif
                                            <ul class="text-xs text-red-600 dark:text-red-400 space-y-0.5 max-h-32 overflow-y-auto">
                                                <template x-for="row in previewRows.filter(r => !r.valid)" :key="row.row">
                                                    <li>
                                                        <span class="font-medium">Row <span x-text="row.row"></span>:</span>
                                                        <span x-text="row.errors.join('; ')"></span>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>

                                        @if(!empty($aiReaderAllowed))
                                        <p x-show="aiFixApplied > 0" x-cloak class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Applied <span x-text="aiFixApplied"></span> fix(es) — all rows re-checked with the standard validation; the counts above are updated.</p>

                                        {{-- Task 1238: suggestion panel — tick what to apply; everything re-runs the normal validation --}}
                                        <div x-show="aiFixes.length > 0" x-cloak class="bg-violet-50 dark:bg-violet-900/20 border border-violet-200 dark:border-violet-800 rounded-lg p-3 space-y-2">
                                            <p class="text-sm font-semibold text-violet-800 dark:text-violet-300">AI fix suggestions — tick the ones to apply. Nothing changes without your confirmation, and applied rows go through the exact same validation again.</p>
                                            <div class="max-h-48 overflow-y-auto space-y-2">
                                                <template x-for="s in aiFixes" :key="s.row">
                                                    <div class="text-xs">
                                                        <p class="font-bold text-violet-900 dark:text-violet-200">Row <span x-text="s.row"></span><span x-show="s.note" class="font-normal text-violet-700 dark:text-violet-300" x-text="' — ' + s.note"></span></p>
                                                        <div class="mt-0.5 space-y-0.5">
                                                            <template x-for="fx in s.fixes" :key="s.row + '-' + fx.field">
                                                                <label class="flex items-start gap-2 cursor-pointer">
                                                                    <input type="checkbox" x-model="fx.apply" class="mt-0.5 rounded border-gray-300 text-violet-600">
                                                                    <span class="text-gray-700 dark:text-gray-300"><span class="font-mono font-semibold" x-text="fx.field"></span> &rarr; <span class="font-mono" x-text="fx.value === '' ? '(blank)' : fx.value"></span> <span class="text-gray-400" x-text="'(was: ' + (fx.old === '' ? 'blank' : fx.old) + ')'"></span></span>
                                                                </label>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <button @click="applySelectedFixes()" :disabled="applyingFixes || selectedFixCount === 0" class="px-4 py-1.5 bg-violet-600 text-white rounded-lg text-xs font-bold hover:bg-violet-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                                    <span x-show="!applyingFixes">Apply <span x-text="selectedFixCount"></span> Fix(es) &amp; Re-check</span>
                                                    <span x-show="applyingFixes" x-cloak>Re-checking rows...</span>
                                                </button>
                                                <button @click="aiFixes = []" class="text-xs text-gray-500 dark:text-gray-400 underline hover:text-gray-700">Dismiss suggestions</button>
                                            </div>
                                        </div>
                                        @endif

                                        <p x-show="hasMore" class="text-xs text-gray-500 dark:text-gray-400">Showing the first <span x-text="previewRows.length"></span> of <span x-text="totalRows"></span> rows below — counts above cover the whole file.</p>

                                        <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                                            <table class="min-w-full text-xs table-cards">
                                                <thead class="bg-gray-50 dark:bg-gray-800">
                                                    <tr>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Row</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Status</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Buyer</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">NTN</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Province</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Doc Type</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">HS Code</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Description</th>
                                                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Schedule</th>
                                                        <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Qty</th>
                                                        <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Price</th>
                                                        <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Tax</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                                    <template x-for="row in previewRows" :key="row.row">
                                                        <tr :class="row.valid ? 'bg-green-50/50 dark:bg-green-900/10' : 'bg-red-50/50 dark:bg-red-900/10'">
                                                            <td class="px-3 py-2" x-text="row.row"></td>
                                                            <td class="px-3 py-2">
                                                                <span x-show="row.valid" class="inline-flex px-1.5 py-0.5 rounded text-xs font-bold bg-green-100 text-green-700">OK</span>
                                                                <span x-show="!row.valid" class="inline-flex px-1.5 py-0.5 rounded text-xs font-bold bg-red-100 text-red-700" :title="row.errors.join('; ')">Error</span>
                                                            </td>
                                                            <td class="px-3 py-2" x-text="row.data.buyer_name || '—'"></td>
                                                            <td class="px-3 py-2" x-text="row.data.buyer_ntn || '—'"></td>
                                                            <td class="px-3 py-2" x-text="row.data.destination_province || '—'"></td>
                                                            <td class="px-3 py-2" x-text="row.data.document_type || '—'"></td>
                                                            <td class="px-3 py-2 font-mono" x-text="row.data.hs_code || '—'"></td>
                                                            <td class="px-3 py-2 max-w-[150px] truncate" x-text="row.data.description || '—'"></td>
                                                            <td class="px-3 py-2" x-text="row.data.schedule_type || '—'"></td>
                                                            <td class="px-3 py-2 text-right" x-text="row.data.quantity || '—'"></td>
                                                            <td class="px-3 py-2 text-right" x-text="row.data.price || '—'"></td>
                                                            <td class="px-3 py-2 text-right" x-text="row.data.tax || '—'"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <div x-show="step === 'processing'" class="py-10 text-center space-y-4">
                                        <svg class="mx-auto h-12 w-12 text-emerald-500 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                                        <p class="text-lg font-bold text-gray-800 dark:text-gray-100">Creating draft invoices...</p>
                                        <div class="max-w-md mx-auto">
                                            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                                <div class="h-full bg-emerald-500 rounded-full transition-all duration-500" :style="'width:' + progressPercent + '%'"></div>
                                            </div>
                                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                                <span x-text="processedRows"></span> / <span x-text="validCount"></span> rows processed
                                                &middot; <span x-text="createdCount"></span> draft(s) created
                                            </p>
                                            <p x-show="queueWaiting" class="mt-1 text-xs text-amber-600 dark:text-amber-400">Waiting for the background worker to pick this up...</p>
                                        </div>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">This runs in the background — you can keep working in another tab; drafts will keep appearing on this page.</p>
                                    </div>

                                    <div x-show="step === 'done'" class="text-center py-8">
                                        <svg x-show="!batchFailed" class="mx-auto h-16 w-16 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <svg x-show="batchFailed" class="mx-auto h-16 w-16 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <p class="mt-3 text-lg font-bold text-gray-800 dark:text-gray-100" x-text="resultMessage"></p>
                                        <div x-show="reviewUrl" class="mt-4">
                                            <a :href="reviewUrl" class="inline-flex items-center px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-bold hover:bg-teal-700 transition">
                                                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                Review these drafts before submitting
                                            </a>
                                            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Check every draft against FBR's rules, fix mistakes in place, then submit.</p>
                                        </div>
                                        <div x-show="failedRowsCount > 0 && errorReportUrl" class="mt-3">
                                            <a :href="errorReportUrl" class="inline-flex items-center px-4 py-2 bg-red-100 text-red-700 rounded-lg text-xs font-bold hover:bg-red-200 transition">
                                                <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                Download Error Report (<span x-text="failedRowsCount"></span> failed rows)
                                            </a>
                                        </div>
                                        <div x-show="createdInvoices.length > 0" class="mt-4 max-h-48 overflow-y-auto">
                                            <table class="mx-auto text-sm text-left">
                                                <thead><tr><th class="px-3 py-1 text-gray-500 dark:text-gray-400">Invoice #</th><th class="px-3 py-1 text-gray-500 dark:text-gray-400">Buyer</th><th class="px-3 py-1 text-gray-500 dark:text-gray-400">Amount</th><th class="px-3 py-1 text-gray-500 dark:text-gray-400">Items</th></tr></thead>
                                                <tbody>
                                                    <template x-for="inv in createdInvoices" :key="inv.id">
                                                        <tr>
                                                            <td class="px-3 py-1 font-mono text-xs" x-text="inv.invoice_number"></td>
                                                            <td class="px-3 py-1" x-text="inv.buyer_name"></td>
                                                            <td class="px-3 py-1" x-text="'PKR ' + Number(inv.total_amount).toLocaleString()"></td>
                                                            <td class="px-3 py-1 text-center" x-text="inv.items_count"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                            <p x-show="createdTotal > createdInvoices.length" class="mt-1 text-xs text-gray-500 dark:text-gray-400">...and <span x-text="createdTotal - createdInvoices.length"></span> more (visible in the Draft tab).</p>
                                        </div>
                                        <button @click="closeModal(); window.location.reload();" class="mt-6 px-6 py-2 bg-emerald-600 text-white rounded-lg text-sm font-bold hover:bg-emerald-700 transition">Close & Refresh</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <a href="/invoice/create" class="btn-premium inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 border border-transparent rounded-xl font-bold text-xs text-white uppercase tracking-widest hover:from-emerald-700 hover:to-teal-700 transition shadow-lg shadow-emerald-500/20">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        New Invoice
                    </a>
                </div>
            </div>
            <div class="flex flex-wrap gap-2.5 mb-6">
                <a href="/invoices?tab=draft" class="btn-premium inline-flex items-center px-5 py-2.5 rounded-xl text-sm font-bold transition {{ $tab === 'draft' ? 'bg-gradient-to-r from-emerald-600 to-teal-600 text-white shadow-lg shadow-emerald-500/20' : 'bg-white dark:bg-gray-800/80 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-emerald-300 dark:hover:border-emerald-700' }}">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Draft
                    <span class="ml-2 px-2 py-0.5 rounded-full text-xs font-extrabold {{ $tab === 'draft' ? 'bg-white/20 text-white' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' }}">{{ $draftCount }}</span>
                </a>
                @if($failedCount > 0)
                <a href="/invoices?tab=failed" class="btn-premium inline-flex items-center px-5 py-2.5 rounded-xl text-sm font-bold transition {{ $tab === 'failed' ? 'bg-gradient-to-r from-red-600 to-rose-600 text-white shadow-lg shadow-red-500/20' : 'bg-white dark:bg-gray-800/80 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-red-300 dark:hover:border-red-700' }}">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Failed
                    <span class="ml-2 px-2 py-0.5 rounded-full text-xs font-extrabold {{ $tab === 'failed' ? 'bg-white/20 text-white' : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' }}">{{ $failedCount }}</span>
                </a>
                @endif
                <a href="/invoices?tab=completed" class="btn-premium inline-flex items-center px-5 py-2.5 rounded-xl text-sm font-bold transition {{ $tab === 'completed' ? 'bg-gradient-to-r from-emerald-600 to-teal-600 text-white shadow-lg shadow-emerald-500/20' : 'bg-white dark:bg-gray-800/80 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-emerald-300 dark:hover:border-emerald-700' }}">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Completed
                    <span class="ml-2 px-2 py-0.5 rounded-full text-xs font-extrabold {{ $tab === 'completed' ? 'bg-white/20 text-white' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' }}">{{ $completedCount }}</span>
                </a>
            </div>

            @if($tab === 'completed' && $completedStats)
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3 mb-6">
                <div class="stat-card bg-gradient-to-br from-emerald-50 via-emerald-50 to-emerald-100 dark:from-emerald-900/30 dark:via-emerald-900/20 dark:to-emerald-800/20 border border-emerald-200/80 dark:border-emerald-800/60 text-emerald-700">
                    <div class="flex items-center gap-1.5 mb-2">
                        <div class="w-7 h-7 rounded-lg bg-emerald-600 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                        <p class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">Total Amount</p>
                    </div>
                    <p class="text-base font-extrabold text-emerald-900 dark:text-emerald-100 tracking-tight">PKR {{ number_format($completedStats['total_amount'], 0) }}</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-blue-50 via-blue-50 to-blue-100 dark:from-blue-900/30 dark:via-blue-900/20 dark:to-blue-800/20 border border-blue-200/80 dark:border-blue-800/60 text-blue-700">
                    <div class="flex items-center gap-1.5 mb-2">
                        <div class="w-7 h-7 rounded-lg bg-blue-600 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg></div>
                        <p class="text-[10px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider">Total Tax</p>
                    </div>
                    <p class="text-base font-extrabold text-blue-900 dark:text-blue-100 tracking-tight">PKR {{ number_format($completedStats['total_tax'], 0) }}</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-green-50 via-green-50 to-green-100 dark:from-green-900/30 dark:via-green-900/20 dark:to-green-800/20 border border-green-200/80 dark:border-green-800/60 text-green-700">
                    <div class="flex items-center gap-1.5 mb-2">
                        <div class="w-7 h-7 rounded-lg bg-green-600 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                        <p class="text-[10px] font-bold text-green-600 dark:text-green-400 uppercase tracking-wider">FBR Prod</p>
                    </div>
                    <p class="text-base font-extrabold text-green-900 dark:text-green-100 tracking-tight">{{ $completedStats['production_count'] }}</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-amber-50 via-amber-50 to-amber-100 dark:from-amber-900/30 dark:via-amber-900/20 dark:to-amber-800/20 border border-amber-200/80 dark:border-amber-800/60 text-amber-700">
                    <div class="flex items-center gap-1.5 mb-2">
                        <div class="w-7 h-7 rounded-lg bg-amber-600 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                        <p class="text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider">Pending</p>
                    </div>
                    <p class="text-base font-extrabold text-amber-900 dark:text-amber-100 tracking-tight">{{ $completedStats['pending_count'] }}</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-violet-50 via-violet-50 to-violet-100 dark:from-violet-900/30 dark:via-violet-900/20 dark:to-violet-800/20 border border-violet-200/80 dark:border-violet-800/60 text-violet-700">
                    <div class="flex items-center gap-1.5 mb-2">
                        <div class="w-7 h-7 rounded-lg bg-violet-600 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>
                        <p class="text-[10px] font-bold text-violet-600 dark:text-violet-400 uppercase tracking-wider">This Month</p>
                    </div>
                    <p class="text-base font-extrabold text-violet-900 dark:text-violet-100 tracking-tight">{{ $completedStats['this_month_count'] }}</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-indigo-50 via-indigo-50 to-indigo-100 dark:from-indigo-900/30 dark:via-indigo-900/20 dark:to-indigo-800/20 border border-indigo-200/80 dark:border-indigo-800/60 text-indigo-700">
                    <div class="flex items-center gap-1.5 mb-2">
                        <div class="w-7 h-7 rounded-lg bg-indigo-600 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg></div>
                        <p class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider">Month Amt</p>
                    </div>
                    <p class="text-base font-extrabold text-indigo-900 dark:text-indigo-100 tracking-tight">PKR {{ number_format($completedStats['this_month_amount'], 0) }}</p>
                </div>
                <div class="stat-card bg-gradient-to-br from-pink-50 via-pink-50 to-pink-100 dark:from-pink-900/30 dark:via-pink-900/20 dark:to-pink-800/20 border border-pink-200/80 dark:border-pink-800/60 text-pink-700 cursor-pointer hover:ring-2 hover:ring-pink-400/50" onclick="openUniqueBuyersModal()">
                    <div class="flex items-center gap-1.5 mb-2">
                        <div class="w-7 h-7 rounded-lg bg-pink-600 flex items-center justify-center"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div>
                        <p class="text-[10px] font-bold text-pink-600 dark:text-pink-400 uppercase tracking-wider">Buyers</p>
                    </div>
                    <p class="text-base font-extrabold text-pink-900 dark:text-pink-100 tracking-tight">{{ $completedStats['unique_buyers'] }} <svg class="w-3.5 h-3.5 inline-block ml-1 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg></p>
                </div>
            </div>
            @endif

            <div class="mb-4">
                <form method="GET" action="/invoices" class="flex flex-col sm:flex-row gap-3" id="invoiceSearchForm">
                    <input type="text" name="search" id="invoiceSearchInput" value="{{ request('search') }}" placeholder="Search invoice #, FBR #, customer, NTN, HS code...  (Press /)" class="premium-input flex-1 px-4 py-2.5 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    @foreach(['per_page','fbr_status','date_from','date_to','month','doc_type','sort','dir'] as $p)
                        @if(request($p))
                        <input type="hidden" name="{{ $p }}" value="{{ request($p) }}">
                        @endif
                    @endforeach
                    <button type="submit" class="btn-premium px-5 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 text-white rounded-xl text-sm font-bold hover:from-emerald-700 hover:to-teal-700 transition">Search</button>
                    @if(request('search'))
                    <a href="/invoices?tab={{ $tab }}{{ request('per_page') ? '&per_page='.request('per_page') : '' }}" class="btn-premium px-5 py-2.5 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 rounded-xl text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">Clear</a>
                    @endif
                </form>
            </div>

            @if($tab === 'completed')
            <div x-data="{ showFilters: {{ request('fbr_status') || request('date_from') || request('date_to') || request('month') || request('doc_type') ? 'true' : 'false' }} }" class="mb-4">
                <button @click="showFilters = !showFilters" class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-emerald-600 transition mb-2">
                    <svg class="w-4 h-4 transition-transform" :class="showFilters ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    Advanced Filters
                    @if(request('fbr_status') || request('date_from') || request('date_to') || request('month') || request('doc_type'))
                    <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-700 rounded text-xs font-bold">Active</span>
                    @endif
                </button>
                <div x-show="showFilters" x-cloak x-transition class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
                    <form method="GET" action="/invoices" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                        <input type="hidden" name="tab" value="completed">
                        @foreach(['search','per_page','sort','dir'] as $p)
                            @if(request($p))
                            <input type="hidden" name="{{ $p }}" value="{{ request($p) }}">
                            @endif
                        @endforeach
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">FBR Status</label>
                            <select name="fbr_status" class="w-full rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:border-emerald-400 focus:ring-emerald-400">
                                <option value="">All</option>
                                <option value="production" {{ request('fbr_status') === 'production' ? 'selected' : '' }}>Production</option>
                                <option value="sandbox" {{ request('fbr_status') === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                                <option value="validated" {{ request('fbr_status') === 'validated' ? 'selected' : '' }}>Validated</option>
                                <option value="pending" {{ request('fbr_status') === 'pending' ? 'selected' : '' }}>Pending</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Doc Type</label>
                            <select name="doc_type" class="w-full rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:border-emerald-400 focus:ring-emerald-400">
                                <option value="">All</option>
                                <option value="Sale Invoice" {{ request('doc_type') === 'Sale Invoice' ? 'selected' : '' }}>Invoice</option>
                                <option value="Credit Note" {{ request('doc_type') === 'Credit Note' ? 'selected' : '' }}>Credit Note</option>
                                <option value="Debit Note" {{ request('doc_type') === 'Debit Note' ? 'selected' : '' }}>Debit Note</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tax Period</label>
                            <input type="month" name="month" value="{{ request('month') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:border-emerald-400 focus:ring-emerald-400">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From Date</label>
                            <input type="date" name="date_from" value="{{ request('date_from') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:border-emerald-400 focus:ring-emerald-400">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To Date</label>
                            <input type="date" name="date_to" value="{{ request('date_to') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:border-emerald-400 focus:ring-emerald-400">
                        </div>
                        <div class="flex items-end gap-2">
                            <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Apply</button>
                            <a href="/invoices?tab=completed" class="px-4 py-2 bg-gray-200 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-300 transition">Reset</a>
                        </div>
                    </form>

                    {{-- Bulk PDF / ZIP download — date range OR all invoices --}}
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-800">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div class="text-xs text-gray-600 dark:text-gray-400">
                                <span class="font-semibold text-gray-800 dark:text-gray-200">Bulk PDF Download:</span>
                                Pick a month or From/To range above (max 500), or click "Download ALL" to grab every completed invoice (latest 500).
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                {{-- Date-range based ZIP --}}
                                <form method="GET" action="{{ route('invoices.bulk-pdf') }}" class="inline-flex items-center">
                                    {{-- all=1 fallback so empty filter still works (date filter applied if present) --}}
                                    <input type="hidden" name="all" value="1">
                                    @if(request('month'))<input type="hidden" name="month" value="{{ request('month') }}">@endif
                                    @if(request('date_from'))<input type="hidden" name="from" value="{{ request('date_from') }}">@endif
                                    @if(request('date_to'))<input type="hidden" name="to" value="{{ request('date_to') }}">@endif
                                    @if(request('fbr_status'))<input type="hidden" name="fbr_status" value="{{ request('fbr_status') }}">@endif
                                    @if(request('doc_type'))<input type="hidden" name="doc_type" value="{{ request('doc_type') }}">@endif
                                    <button type="submit"
                                            onclick="this.querySelector('span').textContent='Preparing...'; this.disabled=true; setTimeout(()=>{this.disabled=false; this.querySelector('span').textContent='ZIP (Filtered)';}, 8000);"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg text-xs font-bold hover:from-indigo-700 hover:to-purple-700 transition shadow-md disabled:opacity-60 disabled:cursor-wait">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        <span>ZIP (Filtered)</span>
                                    </button>
                                </form>
                                {{-- All invoices (no date filter) --}}
                                <a href="{{ route('invoices.bulk-pdf', ['all' => 1]) }}"
                                   onclick="if(!confirm('Download ALL completed invoices as ZIP (max 500 latest)?')){event.preventDefault();}"
                                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white rounded-lg text-xs font-bold hover:from-emerald-700 hover:to-teal-700 transition shadow-md">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    Download ALL
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- Flash error messages (bulk PDF, etc.) --}}
            @if(session('error'))
                <div class="mb-4 px-4 py-3 rounded-xl bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 text-sm text-red-800 dark:text-red-300 font-medium">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Bulk PDF batch links (when range > 500 invoices) --}}
            @if(session('bulk_pdf_batches'))
                @php $b = session('bulk_pdf_batches'); @endphp
                <div class="mb-4 px-4 py-4 rounded-xl bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-300 dark:border-indigo-700">
                    <div class="flex items-center gap-2 mb-3">
                        <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        <p class="text-sm font-bold text-indigo-900 dark:text-indigo-200">
                            {{ $b['total'] }} invoices found — split into {{ count($b['batches']) }} ZIP files ({{ $b['per_zip'] }} per ZIP)
                        </p>
                    </div>
                    <p class="text-xs text-indigo-700 dark:text-indigo-300 mb-3">Click each batch button below to download. Newest invoices come first.</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($b['batches'] as $bt)
                            <a href="{{ $bt['url'] }}"
                               class="inline-flex items-center gap-1.5 px-3 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg text-xs font-bold hover:from-indigo-700 hover:to-purple-700 transition shadow-md">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                Batch {{ $bt['n'] }} <span class="opacity-75">({{ $bt['from'] }}–{{ $bt['to'] }})</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div x-data="invoiceKeyboardNav()" @keydown.window="handleKeydown($event)" class="premium-card overflow-hidden">
                @if(in_array($tab, ['draft', 'failed']) && in_array(auth()->user()->role, ['company_admin', 'employee']))
                {{-- Task 1245: bulk submit selected drafts to FBR. Task 1250: same UI bulk-retries failed invoices. --}}
                @php
                    $bulkNoun = $tab === 'failed' ? 'failed invoice' : 'draft';
                    $bulkTabCount = $tab === 'failed' ? $failedCount : $draftCount;
                @endphp
                <div x-show="bulkSelected.length > 0 && !bulkBatchKey" x-cloak class="flex flex-wrap items-center gap-3 px-5 py-3 border-b border-emerald-200 dark:border-emerald-800 bg-emerald-50/80 dark:bg-emerald-900/20">
                    <p class="text-sm font-bold text-emerald-800 dark:text-emerald-300"><span x-text="bulkSelected.length"></span> {{ $bulkNoun }}(s) selected</p>
                    <button type="button" @click="startBulkSubmit(false)" :disabled="bulkStarting"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white rounded-lg text-xs font-bold hover:from-emerald-700 hover:to-teal-700 transition disabled:opacity-50">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        <span x-show="!bulkStarting">{{ $tab === 'failed' ? 'Retry selected' : 'Submit selected to FBR' }}</span>
                        <span x-show="bulkStarting" x-cloak>Starting...</span>
                    </button>
                    @if($bulkTabCount > 1)
                    <button type="button" @click="if(confirm('{{ $tab === 'failed' ? "Retry ALL {$bulkTabCount} failed invoices?" : "Submit ALL {$bulkTabCount} draft invoices to FBR?" }}')) startBulkSubmit(true)" :disabled="bulkStarting"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-white dark:bg-gray-800 text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700 rounded-lg text-xs font-bold hover:bg-emerald-50 dark:hover:bg-emerald-900/40 transition disabled:opacity-50">
                        {{ $tab === 'failed' ? "Retry all {$bulkTabCount} failed" : "Submit all {$bulkTabCount} drafts" }}
                    </button>
                    @endif
                    <button type="button" @click="bulkSelected = []" class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 underline">Clear selection</button>
                </div>
                <div x-show="bulkBatchKey" x-cloak class="px-5 py-3.5 border-b border-indigo-200 dark:border-indigo-800 bg-indigo-50/80 dark:bg-indigo-900/20">
                    <div class="flex flex-wrap items-center gap-3 mb-2">
                        <svg x-show="!bulkProgress || !bulkProgress.finished" class="w-4 h-4 text-indigo-600 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        <p class="text-sm font-bold text-indigo-900 dark:text-indigo-200">
                            <span x-show="!bulkProgress || !bulkProgress.finished">Submitting to FBR — <span x-text="bulkProgress ? bulkProgress.done : 0"></span> / <span x-text="bulkProgress ? bulkProgress.total : '…'"></span></span>
                            <span x-show="bulkProgress && bulkProgress.finished" x-cloak>Bulk submit finished</span>
                        </p>
                        <template x-if="bulkProgress">
                            <p class="text-xs font-semibold">
                                <span class="text-emerald-700 dark:text-emerald-400" x-text="bulkProgress.success + ' submitted'"></span>
                                <span class="text-red-600 dark:text-red-400 ml-2" x-show="bulkProgress.failed > 0" x-text="bulkProgress.failed + ' failed'"></span>
                                <span class="text-amber-600 dark:text-amber-400 ml-2" x-show="bulkProgress.pending > 0" x-text="bulkProgress.pending + ' pending verification'"></span>
                                <span class="text-gray-500 dark:text-gray-400 ml-2" x-show="bulkProgress.skipped > 0" x-text="bulkProgress.skipped + ' skipped'"></span>
                            </p>
                        </template>
                    </div>
                    <div class="w-full h-2 bg-indigo-100 dark:bg-indigo-900/50 rounded-full overflow-hidden mb-2">
                        <div class="h-full bg-gradient-to-r from-indigo-500 to-emerald-500 transition-all duration-500" :style="'width: ' + (bulkProgress && bulkProgress.total ? Math.round(bulkProgress.done / bulkProgress.total * 100) : 0) + '%'"></div>
                    </div>
                    <template x-if="bulkProgress && bulkProgress.finished">
                        <div>
                            <div x-show="bulkFailures().length" class="mb-2 max-h-40 overflow-y-auto rounded-lg bg-white/70 dark:bg-gray-900/40 border border-red-200 dark:border-red-800 p-2">
                                <template x-for="r in bulkFailures()" :key="r.invoice_id">
                                    <p class="text-[11px] text-red-700 dark:text-red-400 py-0.5">
                                        <a :href="'/invoice/' + r.invoice_id" class="font-bold underline" x-text="r.invoice_number || ('#' + r.invoice_id)"></a>
                                        — <span x-text="r.message"></span>
                                    </p>
                                </template>
                            </div>
                            <button type="button" @click="window.location.reload()" class="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-bold hover:bg-indigo-700 transition">Refresh list</button>
                        </div>
                    </template>
                    <p x-show="bulkError" x-cloak class="text-xs text-red-600 dark:text-red-400 font-semibold" x-text="bulkError"></p>
                </div>
                @endif
                <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 dark:border-gray-800 bg-gradient-to-r from-gray-50 to-white dark:from-gray-800/60 dark:to-gray-900">
                    <div class="flex items-center gap-3">
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Showing <span class="font-bold text-gray-700 dark:text-gray-300">{{ $invoices->firstItem() ?? 0 }}-{{ $invoices->lastItem() ?? 0 }}</span> of <span class="font-bold text-gray-700 dark:text-gray-300">{{ $invoices->total() }}</span>
                        </p>
                        @if($tab === 'completed')
                        <div class="hidden sm:flex items-center gap-1 text-xs text-gray-400 dark:text-gray-500 border-l border-gray-200 dark:border-gray-700 pl-3">
                            <kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono border border-gray-200 dark:border-gray-600">J</kbd><span>/</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono border border-gray-200 dark:border-gray-600">K</kbd> Navigate
                            <kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono border border-gray-200 dark:border-gray-600 ml-2">Enter</kbd> View
                            <kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono border border-gray-200 dark:border-gray-600 ml-2">D</kbd> Download
                            <kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono border border-gray-200 dark:border-gray-600 ml-2">/</kbd> Search
                            <kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono border border-gray-200 dark:border-gray-600 ml-2">&larr;</kbd><span>/</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] font-mono border border-gray-200 dark:border-gray-600">&rarr;</kbd> Pages
                        </div>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-xs text-gray-500 dark:text-gray-400">Per page:</label>
                        <select onchange="updatePerPage(this.value)" class="rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs py-1 pl-2 pr-7 focus:border-emerald-400 focus:ring-emerald-400">
                            @foreach([15, 25, 50, 100] as $pp)
                            <option value="{{ $pp }}" {{ (request('per_page', $tab === 'completed' ? 25 : 15) == $pp) ? 'selected' : '' }}>{{ $pp }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full premium-table table-cards" id="invoiceTable">
                        <thead class="bg-gradient-to-r from-gray-50 to-gray-100/80 dark:from-gray-800 dark:to-gray-800/80 sticky top-0 z-10">
                            <tr>
                                @if(in_array($tab, ['draft', 'failed']))
                                <th class="px-2 py-2 text-center w-8" @click.stop>
                                    <input type="checkbox" @change="toggleAllDrafts($event.target.checked)"
                                        :checked="bulkSelected.length > 0 && bulkSelected.length === pageDraftIds.length"
                                        class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                                        title="Select all {{ $tab === 'failed' ? 'failed invoices' : 'drafts' }} on this page">
                                </th>
                                @endif
                                @if($tab === 'completed')
                                <th class="px-1 py-2 text-center w-6 text-[10px]">#</th>
                                @endif
                                <th class="text-left px-2 py-2">
                                    @if($tab === 'completed')
                                    <a href="/invoices?tab=completed&sort=invoice_number&dir={{ request('sort') === 'invoice_number' && request('dir', 'desc') === 'asc' ? 'desc' : 'asc' }}&{{ http_build_query(request()->except(['sort','dir','page'])) }}" class="hover:text-emerald-600 inline-flex items-center gap-1">
                                        Invoice #
                                        @if(request('sort') === 'invoice_number')
                                        <svg class="w-3 h-3 {{ request('dir', 'desc') === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                                        @endif
                                    </a>
                                    @else
                                    Invoice #
                                    @endif
                                </th>
                                <th class="text-left px-2 py-2">Type</th>
                                <th class="text-left px-2 py-2">
                                    @if($tab === 'completed')
                                    <a href="/invoices?tab=completed&sort=buyer_name&dir={{ request('sort') === 'buyer_name' && request('dir', 'desc') === 'asc' ? 'desc' : 'asc' }}&{{ http_build_query(request()->except(['sort','dir','page'])) }}" class="hover:text-emerald-600 inline-flex items-center gap-1">
                                        Buyer
                                        @if(request('sort') === 'buyer_name')
                                        <svg class="w-3 h-3 {{ request('dir') === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                                        @endif
                                    </a>
                                    @else
                                    Buyer
                                    @endif
                                </th>
                                <th class="text-left px-2 py-2">NTN</th>
                                <th class="text-right px-2 py-2">
                                    @if($tab === 'completed')
                                    <a href="/invoices?tab=completed&sort=total_amount&dir={{ request('sort') === 'total_amount' && request('dir', 'desc') === 'desc' ? 'asc' : 'desc' }}&{{ http_build_query(request()->except(['sort','dir','page'])) }}" class="hover:text-emerald-600 inline-flex items-center gap-1">
                                        Amount
                                        @if(request('sort') === 'total_amount')
                                        <svg class="w-3 h-3 {{ request('dir', 'desc') === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                                        @endif
                                    </a>
                                    @else
                                    Amount
                                    @endif
                                </th>
                                <th class="text-center px-1 py-2">Itm</th>
                                <th class="text-left px-2 py-2">Status</th>
                                <th class="text-left px-2 py-2">
                                    @if($tab === 'completed')
                                    <a href="/invoices?tab=completed&sort=invoice_date&dir={{ request('sort') === 'invoice_date' && request('dir', 'desc') === 'desc' ? 'asc' : 'desc' }}&{{ http_build_query(request()->except(['sort','dir','page'])) }}" class="hover:text-emerald-600 inline-flex items-center gap-1">
                                        Date
                                        @if(request('sort', 'invoice_date') === 'invoice_date')
                                        <svg class="w-3 h-3 {{ request('dir', 'desc') === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                                        @endif
                                    </a>
                                    @else
                                    Date
                                    @endif
                                </th>
                                <th class="text-center px-2 py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse($invoices as $index => $invoice)
                            <tr class="invoice-row transition-all duration-150 cursor-pointer"
                                :class="selectedRow === {{ $index }} ? 'bg-emerald-50 dark:bg-emerald-900/20 ring-1 ring-inset ring-emerald-400 dark:ring-emerald-700' : '{{ $index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50/60 dark:bg-gray-800/40' }}'"
                                @click="selectedRow = {{ $index }}"
                                @dblclick="window.location.href='/invoice/{{ $invoice->id }}'"
                                data-invoice-id="{{ $invoice->id }}"
                                data-invoice-url="/invoice/{{ $invoice->id }}"
                                data-download-url="/invoice/{{ $invoice->id }}/download"
                                data-wht-locked="{{ $invoice->wht_locked ? '1' : '0' }}">
                                @if(in_array($tab, ['draft', 'failed']))
                                <td class="px-2 py-2 text-center" @click.stop>
                                    @if(in_array($invoice->status, ['draft', 'failed']) && !$invoice->fbr_invoice_number && !$invoice->is_fbr_processing)
                                    <input type="checkbox" value="{{ $invoice->id }}" x-model="bulkSelected"
                                        class="bulk-draft-cb rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                                    @elseif(in_array($invoice->status, ['draft', 'failed']) && $invoice->fbr_invoice_number)
                                    <span class="text-gray-400 cursor-help" title="Already has FBR number {{ $invoice->fbr_invoice_number }} — cannot be resubmitted">&#8212;</span>
                                    @endif
                                </td>
                                @endif
                                @if($tab === 'completed')
                                <td class="px-1 py-2 text-center text-[10px] text-gray-400 font-mono">{{ ($invoices->currentPage() - 1) * $invoices->perPage() + $index + 1 }}</td>
                                @endif
                                <td class="px-2 py-2 whitespace-nowrap">
                                    @if($invoice->fbr_invoice_number)
                                        <div class="text-xs font-semibold text-emerald-700">{{ $invoice->fbr_invoice_number }}</div>
                                        <div class="text-[10px] text-gray-400">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number }}</div>
                                    @else
                                        <div class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ $invoice->internal_invoice_number ?? $invoice->invoice_number ?? 'INV-'.$invoice->id }}</div>
                                    @endif
                                </td>
                                <td class="px-2 py-2 whitespace-nowrap text-[10px]">
                                    @if($invoice->document_type === 'Credit Note')
                                        <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-medium">CN</span>
                                    @elseif($invoice->document_type === 'Debit Note')
                                        <span class="px-1.5 py-0.5 rounded bg-purple-100 text-purple-800 font-medium">DN</span>
                                    @else
                                        <span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:text-gray-400 font-medium">INV</span>
                                    @endif
                                    @if(($invoice->source ?? null) === 'api')
                                        <span class="px-1.5 py-0.5 rounded bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300 font-semibold" title="Pushed via the DI invoice API">API</span>
                                    @endif
                                </td>
                                <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-700 dark:text-gray-300 max-w-[140px] truncate" title="{{ $invoice->buyer_name }}">{{ $invoice->buyer_name }}</td>
                                <td class="px-2 py-2 whitespace-nowrap text-[10px] text-gray-500 dark:text-gray-400 font-mono">{{ $invoice->buyer_ntn }}</td>
                                <td class="px-2 py-2 whitespace-nowrap text-xs font-semibold text-gray-900 dark:text-gray-100 text-right">PKR {{ number_format($invoice->total_amount, 2) }}</td>
                                <td class="px-1 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400 text-center">{{ $invoice->items->count() }}</td>
                                <td class="px-2 py-2 whitespace-nowrap">
                                    <span class="premium-badge text-[10px] px-1.5 py-0.5
                                        @if($invoice->status === 'draft') bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200
                                        @elseif($invoice->status === 'failed') bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300
                                        @elseif($invoice->status === 'locked') bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300
                                        @elseif($invoice->status === 'pending_verification') bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300
                                        @endif">
                                        {{ $invoice->status === 'pending_verification' ? 'Pending' : ucfirst($invoice->status) }}
                                    </span>
                                    @if($invoice->fbr_status && $invoice->fbr_status !== 'pending')
                                    <span class="premium-badge text-[10px] px-1.5 py-0.5 ml-0.5
                                        @if($invoice->fbr_status === 'production') bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300
                                        @elseif($invoice->fbr_status === 'validated') bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300
                                        @elseif($invoice->fbr_status === 'failed' || $invoice->fbr_status === 'validation_failed') bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300
                                        @elseif($invoice->fbr_status === 'sandbox') bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300
                                        @else bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300
                                        @endif">
                                        {{ $invoice->fbr_status === 'production' ? 'Prod' : ($invoice->fbr_status === 'validation_failed' ? 'Val.Fail' : ucfirst($invoice->fbr_status)) }}
                                    </span>
                                    @endif
                                    @if($invoice->status === 'draft' && $invoice->fbr_status === 'failed')
                                    @php
                                        $lastLog = $invoice->fbrLogs->first();
                                        $failReason = '';
                                        if ($lastLog && $lastLog->response_payload) {
                                            $resp = json_decode($lastLog->response_payload, true);
                                            if (!empty($resp['errors'])) {
                                                $errs = is_array($resp['errors']) ? $resp['errors'] : [$resp['errors']];
                                                $failReason = implode(', ', array_slice($errs, 0, 2));
                                            } elseif (!empty($resp['error'])) {
                                                $failReason = $resp['error'];
                                            }
                                        }
                                    @endphp
                                    @if($failReason)
                                    <p class="text-[10px] text-red-600 mt-0.5 max-w-[150px] truncate" title="{{ $failReason }}">{{ $failReason }}</p>
                                    @endif
                                    @endif
                                    @if($invoice->status === 'locked' && $invoice->fbr_invoice_number)
                                    <p class="text-[10px] text-emerald-600 mt-0.5 max-w-[150px] truncate" title="{{ $invoice->fbr_invoice_number }}">FBR: {{ $invoice->fbr_invoice_number }}</p>
                                    @elseif(in_array($invoice->status, ['locked', 'pending_verification']) && !$invoice->fbr_invoice_number && in_array(auth()->user()->role, ['company_admin', 'super_admin']))
                                    <div x-data="{ showInput: false }" class="mt-1">
                                        <button x-show="!showInput" @click.stop="showInput = true" class="text-xs text-blue-600 hover:text-blue-800 underline">+ FBR #</button>
                                        <form x-show="showInput" x-cloak method="POST" action="/invoice/{{ $invoice->id }}/update-fbr-number" class="flex items-center gap-1 mt-1" @click.stop>
                                            @csrf
                                            <input type="text" name="fbr_invoice_number" placeholder="FBR #" class="px-1.5 py-0.5 text-xs border border-gray-300 rounded w-36">
                                            <button type="submit" class="px-1.5 py-0.5 bg-emerald-600 text-white rounded text-xs">Save</button>
                                        </form>
                                    </div>
                                    @endif
                                </td>
                                <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $invoice->invoice_date ? \Carbon\Carbon::parse($invoice->invoice_date)->format('d M y') : $invoice->created_at->format('d M y') }}</td>
                                <td class="px-2 py-2 whitespace-nowrap text-xs" @click.stop>
                                    <div class="flex items-center gap-1.5">
                                        <a href="/invoice/{{ $invoice->id }}" class="inline-flex items-center gap-0.5 px-2 py-1 rounded-lg text-[10px] font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-400 dark:hover:bg-emerald-900/50 transition" title="View Invoice (Enter)">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            View
                                        </a>
                                        {{-- Buyer ko bhejein — Email / WhatsApp (roles match the send routes; failed invoices excluded) --}}
                                        @if($invoice->status !== 'failed' && in_array(auth()->user()->role, ['company_admin', 'employee']))
                                        <button type="button" onclick="openSendModal({{ $invoice->id }}, 'email')" class="inline-flex items-center gap-0.5 px-2 py-1 rounded-lg text-[10px] font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 transition" title="Email karein">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                            Email
                                        </button>
                                        <button type="button" onclick="openSendModal({{ $invoice->id }}, 'whatsapp')" class="inline-flex items-center gap-0.5 px-2 py-1 rounded-lg text-[10px] font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-400 dark:hover:bg-emerald-900/50 transition" title="WhatsApp karein">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492a.75.75 0 00.917.918l4.462-1.494A11.945 11.945 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-2.4 0-4.637-.734-6.482-1.988l-.452-.305-2.971.993.994-2.969-.316-.461A9.955 9.955 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/></svg>
                                            WA
                                        </button>
                                        @endif
                                    @if($tab === 'draft')
                                        @if($invoice->status === 'draft')
                                        <a href="/invoice/{{ $invoice->id }}/edit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            Edit
                                        </a>
                                        <a href="/invoice/{{ $invoice->id }}#submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium text-purple-700 bg-purple-50 hover:bg-purple-100 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                            FBR
                                        </a>
                                        @endif
                                    @else
                                        @if($invoice->status === 'draft' && $invoice->fbr_status === 'failed')
                                        <a href="/invoice/{{ $invoice->id }}/edit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium text-amber-700 bg-amber-50 hover:bg-amber-100 transition">Edit</a>
                                        <form method="POST" action="/invoice/{{ $invoice->id }}/retry" class="inline">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 transition">Retry</button>
                                        </form>
                                        @endif
                                        {{-- WHT modal removed per user request — PDF downloads directly --}}
                                        <div class="inline-block">
                                            <a href="/invoice/{{ $invoice->id }}/download" target="_blank" class="inline-flex items-center gap-0.5 px-2 py-1 rounded-lg text-[10px] font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 dark:text-gray-300 dark:bg-gray-800 dark:hover:bg-gray-700 transition" title="Download PDF (D)">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                PDF
                                            </a>
                                            @if(false)
                                            <div x-show="showWhtModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background-color: rgba(0,0,0,0.4);">
                                                <div @click.away="showWhtModal = false" class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-800 p-6 w-80 max-w-[90vw]">
                                                    <div class="flex items-center justify-between mb-4">
                                                        <p class="text-base font-bold text-gray-800 dark:text-gray-100">Withholding Tax Rate</p>
                                                        <button @click="showWhtModal = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-400 text-xl leading-none">&times;</button>
                                                    </div>
                                                    <div class="space-y-2 mb-4">
                                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                            :class="pdfWhtRate == 0 ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                            <input type="radio" value="0" x-model.number="pdfWhtRate" class="text-emerald-500">
                                                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">No WHT (0%)</span>
                                                        </label>
                                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                            :class="pdfWhtRate == 0.5 ? 'border-amber-400 bg-amber-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                            <input type="radio" value="0.5" x-model.number="pdfWhtRate" class="text-amber-500">
                                                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 0.5%</span>
                                                        </label>
                                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                            :class="pdfWhtRate == 1 ? 'border-blue-400 bg-blue-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                            <input type="radio" value="1" x-model.number="pdfWhtRate" class="text-blue-500">
                                                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 1%</span>
                                                        </label>
                                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                            :class="pdfWhtRate == 2 ? 'border-orange-400 bg-orange-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                            <input type="radio" value="2" x-model.number="pdfWhtRate" class="text-orange-500">
                                                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 2%</span>
                                                        </label>
                                                        <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition"
                                                            :class="pdfWhtRate == 2.5 ? 'border-red-400 bg-red-50' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50'">
                                                            <input type="radio" value="2.5" x-model.number="pdfWhtRate" class="text-red-500">
                                                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">WHT 2.5%</span>
                                                        </label>
                                                    </div>
                                                    <button @click="
                                                        saving = true;
                                                        let fd = new FormData();
                                                        fd.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');
                                                        fd.append('wht_rate', pdfWhtRate);
                                                        fetch('/invoice/{{ $invoice->id }}/update-wht-ajax', { method: 'POST', headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, body: fd })
                                                        .then(r => r.json())
                                                        .then(d => {
                                                            if(d.status === 'ok') {
                                                                whtLocked = true;
                                                                showWhtModal = false;
                                                                window.open('/invoice/{{ $invoice->id }}/download', '_blank');
                                                            } else { alert(d.message || 'Failed to save WHT'); }
                                                            saving = false;
                                                        })
                                                        .catch(() => { alert('Network error'); saving = false; });
                                                    " :disabled="saving" class="w-full px-4 py-2.5 bg-emerald-600 text-white rounded-lg text-sm font-bold hover:bg-emerald-700 transition disabled:opacity-50">
                                                        <span x-show="!saving">Lock WHT & Download PDF</span>
                                                        <span x-show="saving" x-cloak>Saving...</span>
                                                    </button>
                                                </div>
                                            </div>
                                            @endif
                                        </div>
                                    @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="11" class="px-6 py-12 text-center">
                                    <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                    <p class="mt-2 text-gray-500 dark:text-gray-400">No invoices found</p>
                                    @if(request('search') || request('fbr_status') || request('month') || request('date_from'))
                                    <a href="/invoices?tab={{ $tab }}" class="mt-3 inline-block text-emerald-600 hover:text-emerald-700 font-medium">Clear filters</a>
                                    @else
                                    <a href="/invoice/create" class="mt-3 inline-block text-emerald-600 hover:text-emerald-700 font-medium">Create your first invoice</a>
                                    @endif
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($invoices->hasPages())
                <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/30 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        @if($invoices->previousPageUrl())
                        <a href="{{ $invoices->previousPageUrl() }}" id="prevPageLink" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Prev
                        </a>
                        @else
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-400 cursor-not-allowed">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Prev
                        </span>
                        @endif

                        <div class="flex items-center gap-1">
                            @for($p = max(1, $invoices->currentPage() - 2); $p <= min($invoices->lastPage(), $invoices->currentPage() + 2); $p++)
                            <a href="{{ $invoices->url($p) }}" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-bold transition {{ $p === $invoices->currentPage() ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50' }}">{{ $p }}</a>
                            @endfor
                            @if($invoices->currentPage() + 2 < $invoices->lastPage())
                            <span class="text-gray-400 px-1">...</span>
                            <a href="{{ $invoices->url($invoices->lastPage()) }}" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-bold bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 transition">{{ $invoices->lastPage() }}</a>
                            @endif
                        </div>

                        @if($invoices->nextPageUrl())
                        <a href="{{ $invoices->nextPageUrl() }}" id="nextPageLink" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                            Next
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                        @else
                        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-400 cursor-not-allowed">
                            Next
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </span>
                        @endif
                    </div>

                    @if($invoices->lastPage() > 5)
                    <div class="flex items-center gap-2">
                        <label class="text-xs text-gray-500 dark:text-gray-400">Go to page:</label>
                        <input type="number" min="1" max="{{ $invoices->lastPage() }}" value="{{ $invoices->currentPage() }}"
                            onkeydown="if(event.key==='Enter'){let p=Math.max(1,Math.min({{ $invoices->lastPage() }},parseInt(this.value)||1));let url=new URL(window.location);url.searchParams.set('page',p);window.location=url.toString();}"
                            class="w-16 rounded-lg border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs text-center py-1 focus:border-emerald-400 focus:ring-emerald-400">
                        <span class="text-xs text-gray-400">/ {{ $invoices->lastPage() }}</span>
                    </div>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </div>
<script>
function updatePerPage(val) {
    let url = new URL(window.location);
    url.searchParams.set('per_page', val);
    url.searchParams.delete('page');
    window.location = url.toString();
}

function invoiceKeyboardNav() {
    return {
        selectedRow: -1,
        totalRows: {{ $invoices->count() }},

        // ── Task 1245: bulk submit drafts to FBR ──
        bulkSelected: [],
        @php
            $pageDraftIds = [];
            if (in_array($tab, ['draft', 'failed'])) {
                foreach ($invoices as $pdInv) {
                    if (in_array($pdInv->status, ['draft', 'failed']) && !$pdInv->fbr_invoice_number && !$pdInv->is_fbr_processing) {
                        $pageDraftIds[] = (string) $pdInv->id;
                    }
                }
            }
        @endphp
        pageDraftIds: {{ json_encode($pageDraftIds) }},
        bulkBatchKey: null,
        bulkProgress: null,
        bulkStarting: false,
        bulkError: '',
        bulkPollTimer: null,

        init() {
            @if($tab === 'draft')
            // Task 1249: re-attach to a still-running bulk submit after a reload.
            fetch('/invoices/bulk-submit-status', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(d => {
                if (d.batch_key && d.batch && !this.bulkBatchKey) {
                    this.bulkBatchKey = d.batch_key;
                    this.bulkProgress = d.batch;
                    if (!d.batch.finished) {
                        this.bulkPollTimer = setTimeout(() => this.pollBulk(), 2500);
                    }
                }
            })
            .catch(() => {});
            @endif
        },

        toggleAllDrafts(checked) {
            this.bulkSelected = checked ? [...this.pageDraftIds] : [];
        },

        bulkFailures() {
            if (!this.bulkProgress || !this.bulkProgress.results) return [];
            return this.bulkProgress.results.filter(r => r.status === 'failed' || r.status === 'skipped' || r.status === 'pending');
        },

        startBulkSubmit(selectAll) {
            if (this.bulkStarting || this.bulkBatchKey) return;
            this.bulkStarting = true;
            this.bulkError = '';
            const body = selectAll
                ? { select_all_drafts: true, status: '{{ $tab === 'failed' ? 'failed' : 'draft' }}' }
                : { invoice_ids: this.bulkSelected.map(Number), status: '{{ $tab === 'failed' ? 'failed' : 'draft' }}' };
            fetch('/invoices/bulk-submit', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                },
                body: JSON.stringify(body)
            })
            .then(async r => ({ ok: r.ok, status: r.status, data: await r.json().catch(() => ({})) }))
            .then(({ ok, status, data }) => {
                this.bulkStarting = false;
                if (data.batch_key && (ok || status === 409)) {
                    // 409 = a batch is already running; re-attach to it.
                    this.bulkBatchKey = data.batch_key;
                    this.pollBulk();
                } else {
                    alert(data.message || 'Could not start bulk submit.');
                }
            })
            .catch(() => { this.bulkStarting = false; alert('Network error — please try again.'); });
        },

        pollBulk() {
            if (!this.bulkBatchKey) return;
            fetch('/invoices/bulk-submit-status?batch_key=' + encodeURIComponent(this.bulkBatchKey), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(d => {
                if (d.batch) this.bulkProgress = d.batch;
                if (this.bulkProgress && this.bulkProgress.finished) return;
                this.bulkPollTimer = setTimeout(() => this.pollBulk(), 2500);
            })
            .catch(() => { this.bulkPollTimer = setTimeout(() => this.pollBulk(), 5000); });
        },

        handleKeydown(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT' || e.target.isContentEditable) return;

            const rows = document.querySelectorAll('.invoice-row');
            if (!rows.length) return;

            switch(e.key) {
                case 'j':
                case 'J':
                case 'ArrowDown':
                    e.preventDefault();
                    this.selectedRow = Math.min(this.selectedRow + 1, this.totalRows - 1);
                    rows[this.selectedRow]?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    break;

                case 'k':
                case 'K':
                case 'ArrowUp':
                    e.preventDefault();
                    this.selectedRow = Math.max(this.selectedRow - 1, 0);
                    rows[this.selectedRow]?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    break;

                case 'Enter':
                    if (this.selectedRow >= 0 && rows[this.selectedRow]) {
                        e.preventDefault();
                        const url = rows[this.selectedRow].dataset.invoiceUrl;
                        if (url) window.location.href = url;
                    }
                    break;

                case 'd':
                case 'D':
                    if (this.selectedRow >= 0 && rows[this.selectedRow]) {
                        e.preventDefault();
                        const row = rows[this.selectedRow];
                        const dlUrl = row.dataset.downloadUrl;
                        const whtLocked = row.dataset.whtLocked === '1';
                        if (dlUrl && whtLocked) {
                            window.open(dlUrl, '_blank');
                        } else if (dlUrl) {
                            const viewUrl = row.dataset.invoiceUrl;
                            if (viewUrl) window.location.href = viewUrl;
                        }
                    }
                    break;

                case '/':
                    e.preventDefault();
                    const searchInput = document.getElementById('invoiceSearchInput');
                    if (searchInput) searchInput.focus();
                    break;

                case 'ArrowLeft':
                    e.preventDefault();
                    const prevLink = document.getElementById('prevPageLink');
                    if (prevLink) window.location.href = prevLink.href;
                    break;

                case 'ArrowRight':
                    e.preventDefault();
                    const nextLink = document.getElementById('nextPageLink');
                    if (nextLink) window.location.href = nextLink.href;
                    break;

                case 'Escape':
                    this.selectedRow = -1;
                    document.activeElement?.blur();
                    break;
            }
        }
    };
}

function bulkImport() {
    return {
        showModal: false,
        step: 'upload',
        uploading: false,
        processing: false,
        uploadError: '',
        batchId: null,
        previewRows: [],
        hasMore: false,
        totalRows: 0,
        validCount: 0,
        errorCount: 0,
        errorReportUrl: null,
        reviewUrl: null,
        processedRows: 0,
        createdCount: 0,
        failedRowsCount: 0,
        queueWaiting: false,
        pollTimer: null,
        pollCount: 0,
        batchFailed: false,
        resultMessage: '',
        createdInvoices: [],
        createdTotal: 0,

        // Task 1230: DMS-export column mapping step
        mappingToken: null,
        mappingFilename: '',
        fileHeaders: [],
        mappingFields: [],
        mappingSel: {},
        defaultsSel: {},
        presets: [],
        savePreset: false,
        presetName: '',
        mappingError: '',
        applyingMapping: false,
        presetSavedName: '',
        presetCapWarning: false,

        // Task 1238: AI assist (suggestions only; plan-gated server-side too)
        aiAllowed: {{ !empty($aiReaderAllowed) ? 'true' : 'false' }},
        aiConfigured: {{ \App\Services\AiInvoiceReaderService::enabled() ? 'true' : 'false' }},
        aiMapLoading: false,
        aiMapError: '',
        aiMapDone: false,
        aiMapCount: 0,
        aiPicked: {},
        aiFixLoading: false,
        aiFixError: '',
        aiFixNote: '',
        aiFixes: [],
        applyingFixes: false,
        aiFixApplied: 0,

        openModal() {
            this.showModal = true;
            this.resetUpload();
        },

        closeModal() {
            this.showModal = false;
            this.stopPolling();
        },

        resetUpload() {
            this.stopPolling();
            this.step = 'upload';
            this.uploading = false;
            this.processing = false;
            this.uploadError = '';
            this.batchId = null;
            this.previewRows = [];
            this.hasMore = false;
            this.totalRows = 0;
            this.validCount = 0;
            this.errorCount = 0;
            this.errorReportUrl = null;
            this.reviewUrl = null;
            this.processedRows = 0;
            this.createdCount = 0;
            this.failedRowsCount = 0;
            this.queueWaiting = false;
            this.batchFailed = false;
            this.resultMessage = '';
            this.createdInvoices = [];
            this.createdTotal = 0;
            this.mappingToken = null;
            this.mappingFilename = '';
            this.fileHeaders = [];
            this.mappingFields = [];
            this.mappingSel = {};
            this.defaultsSel = {};
            this.presets = [];
            this.savePreset = false;
            this.presetName = '';
            this.mappingError = '';
            this.applyingMapping = false;
            this.presetSavedName = '';
            this.presetCapWarning = false;
            this.aiMapLoading = false;
            this.aiMapError = '';
            this.aiMapDone = false;
            this.aiMapCount = 0;
            this.aiPicked = {};
            this.aiFixLoading = false;
            this.aiFixError = '';
            this.aiFixNote = '';
            this.aiFixes = [];
            this.applyingFixes = false;
            this.aiFixApplied = 0;
        },

        get progressPercent() {
            if (!this.validCount) return 0;
            return Math.min(100, Math.round((this.processedRows / this.validCount) * 100));
        },

        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        async handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            this.uploading = true;
            this.uploadError = '';

            const formData = new FormData();
            formData.append('import_file', file);

            try {
                const response = await fetch('/invoices/import-upload', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                const data = await response.json();

                if (!response.ok) {
                    this.uploadError = data.error || data.message || 'Upload failed.';
                    this.uploading = false;
                    return;
                }

                if (data.needs_mapping) {
                    this.setupMapping(data);
                } else {
                    this.showPreview(data);
                }
            } catch (e) {
                this.uploadError = 'Network error. Please try again.';
            }

            this.uploading = false;
            event.target.value = '';
        },

        showPreview(data) {
            this.batchId = data.batch_id;
            this.previewRows = data.preview || [];
            this.hasMore = !!data.has_more;
            this.totalRows = data.total;
            this.validCount = data.valid_count;
            this.errorCount = data.error_count;
            this.errorReportUrl = data.error_report_url;
            this.presetSavedName = data.preset_saved || '';
            this.step = 'preview';
        },

        setupMapping(data) {
            this.mappingToken = data.mapping_token;
            this.mappingFilename = data.original_filename || '';
            this.fileHeaders = data.headers || [];
            this.mappingFields = data.fields || [];
            this.presets = data.presets || [];
            this.mappingError = '';
            this.savePreset = false;
            this.presetName = '';
            const sel = {}, defs = {};
            for (const f of this.mappingFields) {
                sel[f.key] = (data.suggestions && data.suggestions[f.key]) || '';
                defs[f.key] = '';
            }
            // Sensible fixed-value prefills for fields DMS exports rarely carry.
            if (!sel['document_type']) defs['document_type'] = 'Sale Invoice';
            if (!sel['destination_province'] && data.company_province) defs['destination_province'] = data.company_province;
            this.mappingSel = sel;
            this.defaultsSel = defs;
            this.step = 'mapping';
        },

        async applyMapping(presetId = null) {
            this.mappingError = '';

            if (!presetId) {
                const missing = this.mappingFields
                    .filter(f => f.value_required && !this.mappingSel[f.key] && !(this.defaultsSel[f.key] || '').trim())
                    .map(f => f.key);
                if (missing.length) {
                    this.mappingError = 'Give these required fields a column or a fixed value: ' + missing.join(', ');
                    return;
                }
                if (this.savePreset && !this.presetName.trim()) {
                    this.mappingError = 'Enter a name for the preset (or untick "Save this mapping as a preset").';
                    return;
                }
            }

            const payload = { mapping_token: this.mappingToken };
            if (presetId) {
                payload.preset_id = presetId;
            } else {
                const mapping = {}, defaults = {};
                for (const f of this.mappingFields) {
                    if (this.mappingSel[f.key]) {
                        mapping[f.key] = this.mappingSel[f.key];
                    } else if ((this.defaultsSel[f.key] || '').trim()) {
                        defaults[f.key] = this.defaultsSel[f.key].trim();
                    }
                }
                payload.mapping = mapping;
                payload.defaults = defaults;
                if (this.savePreset && this.presetName.trim()) payload.save_preset_name = this.presetName.trim();
            }

            this.applyingMapping = true;
            try {
                const response = await fetch('/invoices/import-apply-mapping', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.mappingError = data.error || data.message || 'Could not apply the mapping.';
                } else {
                    // Preset cap hit — import continues, just tell the user.
                    this.presetCapWarning = !!(payload.save_preset_name && !data.preset_saved);
                    this.showPreview(data);
                }
            } catch (e) {
                this.mappingError = 'Network error. Please try again.';
            }
            this.applyingMapping = false;
        },

        // Task 1238: AI mapping suggestions — fills ONLY still-unresolved
        // fields, marks them with an AI badge, user can change everything.
        async suggestAiMapping() {
            if (!this.mappingToken || this.aiMapLoading) return;
            this.aiMapLoading = true;
            this.aiMapError = '';
            this.aiMapDone = false;

            const mapping = {}, defaults = {};
            for (const f of this.mappingFields) {
                if (this.mappingSel[f.key]) mapping[f.key] = this.mappingSel[f.key];
                else if ((this.defaultsSel[f.key] || '').trim()) defaults[f.key] = this.defaultsSel[f.key].trim();
            }

            try {
                const response = await fetch('/invoices/import-ai-map', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ mapping_token: this.mappingToken, mapping: mapping, defaults: defaults }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.aiMapError = data.error || data.message || 'Could not get AI suggestions.';
                } else {
                    let filled = 0;
                    const sugMap = data.mapping || {}, sugDefs = data.defaults || {};
                    for (const f of this.mappingFields) {
                        const k = f.key;
                        if (this.mappingSel[k] || (this.defaultsSel[k] || '').trim()) continue; // never overwrite the user
                        if (sugMap[k] && this.fileHeaders.includes(sugMap[k])) {
                            this.mappingSel[k] = sugMap[k];
                            this.aiPicked[k] = true;
                            filled++;
                        } else if (sugDefs[k]) {
                            this.defaultsSel[k] = sugDefs[k];
                            this.aiPicked[k] = true;
                            filled++;
                        }
                    }
                    this.aiMapCount = filled;
                    this.aiMapDone = true;
                    if (data.note) this.aiMapError = data.note;
                }
            } catch (e) {
                this.aiMapError = 'Network error. Please try again.';
            }
            this.aiMapLoading = false;
        },

        // Task 1238: AI fix suggestions for failing rows (capped server-side).
        async getAiFixes() {
            if (!this.batchId || this.aiFixLoading) return;
            this.aiFixLoading = true;
            this.aiFixError = '';
            this.aiFixApplied = 0;
            try {
                const response = await fetch('/invoices/import/' + this.batchId + '/ai-fixes', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (!response.ok) {
                    this.aiFixError = data.error || data.message || 'Could not get AI suggestions.';
                } else {
                    this.aiFixes = (data.suggestions || []).map(s => ({
                        row: s.row,
                        note: s.note || '',
                        fixes: (s.fixes || []).map(fx => ({ field: fx.field, value: fx.value, old: fx.old || '', apply: true })),
                    }));
                    this.aiFixNote = data.truncated
                        ? ('Suggestions cover the first ' + data.covered + ' of ' + data.invalid_total + ' failing rows — fix and re-upload for the rest, or ask again after applying.')
                        : '';
                    if (!this.aiFixes.length) {
                        this.aiFixError = 'The AI had no confident suggestions for these rows — please fix them in the file and re-upload.';
                    }
                }
            } catch (e) {
                this.aiFixError = 'Network error. Please try again.';
            }
            this.aiFixLoading = false;
        },

        get selectedFixCount() {
            return this.aiFixes.reduce((n, s) => n + s.fixes.filter(fx => fx.apply).length, 0);
        },

        // User-confirmed fixes -> server patches rows and re-runs the SAME
        // validation over the whole batch, then we refresh the preview.
        async applySelectedFixes() {
            if (!this.batchId || this.applyingFixes) return;
            const fixes = [];
            for (const s of this.aiFixes) {
                const fields = {};
                for (const fx of s.fixes) {
                    if (fx.apply) fields[fx.field] = fx.value;
                }
                if (Object.keys(fields).length) fixes.push({ row: s.row, fields: fields });
            }
            if (!fixes.length) return;

            this.applyingFixes = true;
            this.aiFixError = '';
            try {
                const response = await fetch('/invoices/import/' + this.batchId + '/apply-fixes', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ fixes: fixes }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.aiFixError = data.error || data.message || 'Could not apply the fixes.';
                } else {
                    // Keep only unapplied suggestions; drop everything once clean.
                    this.aiFixes = this.aiFixes
                        .map(s => ({ row: s.row, note: s.note, fixes: s.fixes.filter(fx => !fx.apply) }))
                        .filter(s => s.fixes.length > 0);
                    const keepNote = this.aiFixNote;
                    this.showPreview(data);
                    this.aiFixNote = keepNote;
                    this.aiFixApplied = data.applied || 0;
                    if (!data.error_count) this.aiFixes = [];
                }
            } catch (e) {
                this.aiFixError = 'Network error. Please try again.';
            }
            this.applyingFixes = false;
        },

        async renamePreset(p) {
            const name = prompt('New preset name:', p.name);
            if (!name || !name.trim() || name.trim() === p.name) return;
            try {
                const response = await fetch('/invoices/import-mappings/' + p.id + '/rename', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ name: name.trim() }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.mappingError = data.error || 'Could not rename the preset.';
                } else {
                    p.name = data.name;
                    this.mappingError = '';
                }
            } catch (e) {
                this.mappingError = 'Network error. Please try again.';
            }
        },

        async deletePreset(p) {
            if (!confirm('Delete preset "' + p.name + '"?')) return;
            try {
                const response = await fetch('/invoices/import-mappings/' + p.id, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (!response.ok) {
                    this.mappingError = data.error || 'Could not delete the preset.';
                } else {
                    this.presets = this.presets.filter(x => x.id !== p.id);
                    this.mappingError = '';
                }
            } catch (e) {
                this.mappingError = 'Network error. Please try again.';
            }
        },

        async startProcessing() {
            if (!this.batchId) return;
            this.processing = true;
            this.uploadError = '';

            try {
                const response = await fetch('/invoices/import/' + this.batchId + '/process', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const data = await response.json();

                if (!response.ok) {
                    this.uploadError = data.error || data.message || 'Could not start the import.';
                    this.processing = false;
                    return;
                }

                this.step = 'processing';
                this.pollCount = 0;
                this.pollStatus();
                this.pollTimer = setInterval(() => this.pollStatus(), 1500);
            } catch (e) {
                this.uploadError = 'Network error. Please try again.';
                this.processing = false;
            }
        },

        async pollStatus() {
            if (!this.batchId) return;
            this.pollCount++;

            try {
                const response = await fetch('/invoices/import/' + this.batchId + '/status', {
                    headers: { 'Accept': 'application/json' },
                });
                if (!response.ok) return;
                const data = await response.json();

                this.processedRows = data.processed_rows || 0;
                this.createdCount = data.created_invoices || 0;
                this.failedRowsCount = (data.failed_rows || 0) + (data.invalid_rows || 0);
                this.queueWaiting = data.status === 'queued' && this.pollCount > 8;
                if (data.error_report_url) this.errorReportUrl = data.error_report_url;
                if (data.review_url) this.reviewUrl = data.review_url;

                if (data.status === 'completed') {
                    this.stopPolling();
                    this.batchFailed = false;
                    this.resultMessage = data.message || (this.createdCount + ' draft invoice(s) created.');
                    this.createdInvoices = data.created || [];
                    this.createdTotal = data.created_total || this.createdInvoices.length;
                    this.step = 'done';
                    this.processing = false;
                } else if (data.status === 'failed') {
                    this.stopPolling();
                    this.batchFailed = true;
                    this.resultMessage = data.error_message || 'Import failed.';
                    this.createdInvoices = data.created || [];
                    this.createdTotal = data.created_total || this.createdInvoices.length;
                    this.step = 'done';
                    this.processing = false;
                }
            } catch (e) {
                // transient network hiccup — keep polling
            }
        }
    };
}
</script>
<div id="uniqueBuyersModal" class="fixed inset-0 z-50 hidden" role="dialog">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeUniqueBuyersModal()"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[80vh] flex flex-col border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Unique Buyers</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">All buyers with completed invoices, sorted by total amount</p>
                </div>
                <button onclick="closeUniqueBuyersModal()" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                    <svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="overflow-auto flex-1 px-6 py-4" id="uniqueBuyersContent">
                <div class="flex items-center justify-center py-12">
                    <svg class="animate-spin h-8 w-8 text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span class="ml-3 text-gray-500">Loading buyers...</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openUniqueBuyersModal() {
    document.getElementById('uniqueBuyersModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    fetch('/invoices/unique-buyers', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(buyers => {
            if (!buyers.length) {
                document.getElementById('uniqueBuyersContent').innerHTML = '<p class="text-center text-gray-500 py-8">No buyers found.</p>';
                return;
            }
            let html = '<table class="w-full text-sm table-cards"><thead><tr class="border-b border-gray-200 dark:border-gray-700">';
            html += '<th class="text-left py-2 px-2 text-xs font-medium text-gray-500 uppercase">#</th>';
            html += '<th class="text-left py-2 px-2 text-xs font-medium text-gray-500 uppercase">Buyer Name</th>';
            html += '<th class="text-left py-2 px-2 text-xs font-medium text-gray-500 uppercase">NTN</th>';
            html += '<th class="text-center py-2 px-2 text-xs font-medium text-gray-500 uppercase">Invoices</th>';
            html += '<th class="text-right py-2 px-2 text-xs font-medium text-gray-500 uppercase">Total Amount</th>';
            html += '<th class="text-right py-2 px-2 text-xs font-medium text-gray-500 uppercase">Last Invoice</th>';
            html += '</tr></thead><tbody>';
            let grandTotal = 0;
            buyers.forEach((b, i) => {
                grandTotal += parseFloat(b.total_amount);
                const lastDate = b.last_invoice_date ? new Date(b.last_invoice_date).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '-';
                html += '<tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">';
                html += '<td class="py-2.5 px-2 text-gray-400">' + (i+1) + '</td>';
                html += '<td class="py-2.5 px-2 font-medium text-gray-900 dark:text-white">' + (b.buyer_name || 'N/A') + '</td>';
                html += '<td class="py-2.5 px-2 text-gray-600 dark:text-gray-400 font-mono text-xs">' + (b.buyer_ntn || '-') + '</td>';
                html += '<td class="py-2.5 px-2 text-center"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">' + b.total_invoices + '</span></td>';
                html += '<td class="py-2.5 px-2 text-right font-semibold text-gray-900 dark:text-white">PKR ' + Number(b.total_amount).toLocaleString() + '</td>';
                html += '<td class="py-2.5 px-2 text-right text-gray-500 dark:text-gray-400 text-xs">' + lastDate + '</td>';
                html += '</tr>';
            });
            html += '</tbody><tfoot><tr class="border-t-2 border-gray-300 dark:border-gray-600">';
            html += '<td colspan="3" class="py-3 px-2 font-bold text-gray-700 dark:text-gray-300">Total: ' + buyers.length + ' buyers</td>';
            html += '<td class="py-3 px-2 text-center font-bold text-gray-700 dark:text-gray-300">' + buyers.reduce((s,b) => s + parseInt(b.total_invoices), 0) + '</td>';
            html += '<td class="py-3 px-2 text-right font-bold text-emerald-700 dark:text-emerald-400">PKR ' + grandTotal.toLocaleString() + '</td>';
            html += '<td></td></tr></tfoot></table>';
            document.getElementById('uniqueBuyersContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('uniqueBuyersContent').innerHTML = '<p class="text-center text-red-500 py-8">Failed to load buyers. Please try again.</p>';
        });
}
function closeUniqueBuyersModal() {
    document.getElementById('uniqueBuyersModal').classList.add('hidden');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeUniqueBuyersModal(); });
</script>
@include('invoice.partials.send-modal')
</x-app-layout>
