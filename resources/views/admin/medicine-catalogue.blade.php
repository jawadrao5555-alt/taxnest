<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Medicine Catalogue</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    Global list every pharmacy-mode FBR shop can add products from. Source: Drug Regulatory Authority of Pakistan (DRAP), Pharmaceutical Product Price Index —
                    <a href="https://e.dra.gov.pk/public/price" target="_blank" rel="noopener" class="underline text-blue-600 dark:text-blue-400">e.dra.gov.pk/public/price</a> (Government of Pakistan public data).
                </p>
            </div>
            @if($ready)
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('admin.medicine-catalogue.sync') }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                        <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Sync from DRAP
                    </button>
                </form>
                <a href="{{ route('admin.medicine-catalogue.export', request()->query()) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Export Excel
                </a>
            </div>
            @endif
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @unless($ready)
                <div class="p-4 bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 rounded-lg">Catalogue tables are not migrated yet on this server — run <code>php artisan migrate</code>.</div>
            @else

            {{-- Stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                @foreach([
                    ['Rows', number_format($stats['total']), 'text-emerald-600 dark:text-emerald-400'],
                    ['Active', number_format($stats['active']), 'text-gray-800 dark:text-gray-100'],
                    ['From DRAP', number_format($stats['drap']), 'text-blue-600 dark:text-blue-400'],
                    ['Supplementary', number_format($stats['supplementary']), 'text-purple-600 dark:text-purple-400'],
                    ['Shop products linked', number_format($stats['linked_products']), 'text-gray-800 dark:text-gray-100'],
                    ['Pending price notices', number_format($stats['pending_notices']), 'text-amber-600 dark:text-amber-400'],
                ] as [$label, $value, $cls])
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold {{ $cls }}">{{ $value }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $label }}</p>
                </div>
                @endforeach
            </div>

            {{-- Sync status --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5"
                 x-data="mcSyncStatus({{ \Illuminate\Support\Js::from($run?->toStatusArray($phaseCount)) }}, '{{ route('admin.medicine-catalogue.sync-status') }}')" x-init="init()">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-gray-100">DRAP sync</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Runs in the background on the queue (about an hour for ~1,070 pages, one page a second). Safe to press twice — a running sync is simply continued. Weekly re-sync is scheduled every Sunday 01:30.
                            @if(!empty($lastCompleted))
                                Last completed: {{ $lastCompleted->completed_at?->format('d M Y H:i') }} ({{ number_format($lastCompleted->rows_seen) }} rows, {{ number_format($lastCompleted->price_changes) }} price changes).
                            @endif
                        </p>
                    </div>
                    <template x-if="run && run.active">
                        <form method="POST" action="{{ route('admin.medicine-catalogue.sync-cancel') }}" onsubmit="return confirm('Stop the running sync after its current page?')">
                            @csrf
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-50 text-red-700 border border-red-200 hover:bg-red-100">Stop sync</button>
                        </form>
                    </template>
                </div>
                <template x-if="run">
                    <div class="mt-4">
                        <div class="flex items-center gap-3 text-sm">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold uppercase"
                                  :class="{
                                    'bg-emerald-100 text-emerald-800': run.state === 'completed',
                                    'bg-blue-100 text-blue-800': run.state === 'running' || run.state === 'queued',
                                    'bg-red-100 text-red-800': run.state === 'failed' || run.state === 'stalled',
                                    'bg-gray-200 text-gray-700': run.state === 'cancelled' }"
                                  x-text="run.state"></span>
                            <span class="text-gray-700 dark:text-gray-200">Run #<span x-text="run.id"></span> · page <span x-text="run.pages_done"></span><template x-if="run.total_pages"><span> / <span x-text="run.total_pages"></span></span></template></span>
                            <span class="text-gray-500 dark:text-gray-400 text-xs">rows seen <span x-text="run.rows_seen.toLocaleString()"></span> · new <span x-text="run.rows_created.toLocaleString()"></span> · updated <span x-text="run.rows_updated.toLocaleString()"></span> · price changes <span x-text="run.price_changes.toLocaleString()"></span><template x-if="run.errors_count"><span> · errors <span x-text="run.errors_count"></span></span></template></span>
                        </div>
                        <div class="mt-2 h-2.5 w-full bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                            <div class="h-full bg-emerald-500 transition-all duration-700" :style="'width:' + (run.percent === null ? 2 : run.percent) + '%'"></div>
                        </div>
                        <p class="mt-1.5 text-[11px] text-gray-500 dark:text-gray-400">
                            <span x-text="run.percent === null ? 'Working out page count…' : run.percent + '%'"></span>
                            · last progress <span x-text="run.last_progress_at ? new Date(run.last_progress_at).toLocaleString() : '—'"></span>
                            <template x-if="run.last_error"><span> · <span class="text-amber-700 dark:text-amber-300" x-text="run.last_error"></span></span></template>
                            <template x-if="run.state === 'stalled'"><span class="text-red-700 dark:text-red-300"> · the worker went quiet — press “Sync from DRAP” to resume from this page (check the queue worker on the server).</span></template>
                        </p>
                    </div>
                </template>
                <template x-if="!run">
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No sync has run yet. Press “Sync from DRAP” to seed the catalogue.</p>
                </template>
            </div>

            {{-- Import + manual add --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">Supplementary import (Excel / CSV)</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">For distributor price lists and medicines DRAP does not list. Headers: <code>Brand, Composition, Manufacturer, DRAP Reg No, Pack Size, MRP, Effective Date, Category</code>. Rows match on Reg No × Pack × Manufacturer (brand when no Reg No). A DRAP-sourced MRP is <strong>never</strong> overwritten unless you tick the box — every MRP change lands in price history and raises shop notices.</p>
                    <form method="POST" action="{{ route('admin.medicine-catalogue.import') }}" enctype="multipart/form-data" class="mt-3 space-y-2">
                        @csrf
                        <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="block w-full text-sm text-gray-700 dark:text-gray-200 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                        <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="overwrite_drap_mrp" value="1" class="rounded border-gray-300 text-red-600">
                            Also overwrite DRAP-sourced MRPs with this file's prices (explicit — off by default)
                        </label>
                        <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700">Import</button>
                    </form>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">Add one row by hand</h3>
                    <form method="POST" action="{{ route('admin.medicine-catalogue.store') }}" class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        @csrf
                        <input name="brand_name" required placeholder="Brand name *" class="col-span-2 rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                        <input name="composition" placeholder="Composition (e.g. Paracetamol 500mg)" class="col-span-2 rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                        <input name="manufacturer" placeholder="Manufacturer" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                        <input name="drap_reg_no" placeholder="DRAP Reg No" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                        <input name="pack_size" placeholder="Pack (e.g. 20's)" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                        <input name="mrp" type="number" step="0.01" min="0" placeholder="MRP (Rs)" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                        <select name="category" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                            <option value="normal">Normal</option>
                            <option value="essential">Essential</option>
                            <option value="low_price">Low Price</option>
                        </select>
                        <input name="effective_date" type="date" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                        <div class="col-span-2"><button type="submit" class="px-4 py-2 bg-gray-800 dark:bg-gray-100 dark:text-gray-900 text-white rounded-lg text-sm font-medium">Add row</button></div>
                    </form>
                </div>
            </div>

            {{-- Recent price changes --}}
            @if($recentPrices->isNotEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Recent MRP changes <span class="text-xs font-normal text-gray-500">({{ number_format($stats['price_changes_30d']) }} in the last 30 days)</span></h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400"><tr><th class="text-left py-1 pr-4">Medicine</th><th class="text-right py-1 pr-4">Old</th><th class="text-right py-1 pr-4">New</th><th class="text-left py-1 pr-4">Effective</th><th class="text-left py-1">Source · when</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($recentPrices as $pr)
                            <tr>
                                <td class="py-1.5 pr-4 text-gray-800 dark:text-gray-100">{{ $pr->entry?->brand_name ?? ('#' . $pr->catalogue_id) }} <span class="text-gray-400 text-xs">{{ $pr->entry?->pack_size }}</span></td>
                                <td class="py-1.5 pr-4 text-right text-gray-500">{{ $pr->old_mrp !== null ? number_format((float) $pr->old_mrp, 2) : '—' }}</td>
                                <td class="py-1.5 pr-4 text-right font-semibold {{ (float) $pr->new_mrp > (float) $pr->old_mrp ? 'text-red-600' : 'text-emerald-600' }}">{{ number_format((float) $pr->new_mrp, 2) }}</td>
                                <td class="py-1.5 pr-4 text-gray-600 dark:text-gray-300">{{ $pr->effective_date?->format('d M Y') ?? '—' }}</td>
                                <td class="py-1.5 text-gray-500 text-xs">{{ $pr->source }} · {{ $pr->created_at?->format('d M Y H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            {{-- Search + table --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <form method="GET" class="p-4 flex flex-wrap items-end gap-3 border-b border-gray-100 dark:border-gray-700">
                    <div class="flex-1 min-w-[220px]">
                        <label class="block text-xs text-gray-500 mb-1">Search brand / salt / manufacturer / reg no</label>
                        <input type="text" name="q" value="{{ $q }}" placeholder="panadol, amoxicillin, 019809…" class="w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">DRAP category</label>
                        <select name="category" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                            <option value="">All</option>
                            <option value="essential" @selected($category === 'essential')>Essential</option>
                            <option value="low_price" @selected($category === 'low_price')>Low Price</option>
                            <option value="normal" @selected($category === 'normal')>Normal</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Source</label>
                        <select name="source" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                            <option value="">All</option>
                            <option value="drap" @selected($source === 'drap')>DRAP</option>
                            <option value="import" @selected($source === 'import')>Import</option>
                            <option value="manual" @selected($source === 'manual')>Manual</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Status</label>
                        <select name="status" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                            <option value="active" @selected($status === 'active')>Active</option>
                            <option value="inactive" @selected($status === 'inactive')>Retired</option>
                            <option value="all" @selected($status === 'all')>All</option>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-gray-800 dark:bg-gray-100 dark:text-gray-900 text-white rounded-lg text-sm font-medium">Filter</button>
                    @if($q !== '' || $category !== '' || $source !== '' || $status !== 'active')
                        <a href="{{ route('admin.medicine-catalogue') }}" class="text-sm text-gray-500 underline">Clear</a>
                    @endif
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="text-left px-4 py-2">Brand / composition</th>
                                <th class="text-left px-3 py-2">Generic · strength · form</th>
                                <th class="text-left px-3 py-2">Manufacturer</th>
                                <th class="text-left px-3 py-2">Reg No</th>
                                <th class="text-left px-3 py-2">Category</th>
                                <th class="text-left px-3 py-2">Pack</th>
                                <th class="text-right px-3 py-2">MRP</th>
                                <th class="text-left px-3 py-2">Effective</th>
                                <th class="text-left px-3 py-2">Source</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($entries as $e)
                            <tr class="{{ $e->is_active ? '' : 'opacity-60' }}">
                                <td class="px-4 py-2 align-top max-w-xs">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $e->brand_name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $e->composition }}">{{ $e->composition }}</div>
                                </td>
                                <td class="px-3 py-2 align-top text-gray-700 dark:text-gray-200">
                                    <div>{{ $e->generic_name ?: '—' }} <span class="text-gray-400">{{ $e->strength }}</span> <span class="text-xs text-gray-400">{{ $e->dosage_form }}</span></div>
                                </td>
                                <td class="px-3 py-2 align-top text-gray-700 dark:text-gray-200 text-xs">{{ $e->manufacturer }}<div class="text-gray-400">{{ $e->manufacturer_licence }}</div></td>
                                <td class="px-3 py-2 align-top font-mono text-xs text-gray-700 dark:text-gray-200">{{ $e->drap_reg_no ?: '—' }}</td>
                                <td class="px-3 py-2 align-top">
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $e->category === 'essential' ? 'bg-emerald-100 text-emerald-800' : ($e->category === 'low_price' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700') }}">{{ \App\Models\MedicineCatalogueEntry::categoryLabel($e->category) }}</span>
                                </td>
                                <td class="px-3 py-2 align-top text-gray-700 dark:text-gray-200 text-xs">{{ $e->pack_size }}</td>
                                <td class="px-3 py-2 align-top text-right font-semibold text-gray-900 dark:text-gray-100">{{ $e->mrp !== null ? number_format((float) $e->mrp, 2) : '—' }}</td>
                                <td class="px-3 py-2 align-top text-xs text-gray-600 dark:text-gray-300">{{ $e->effective_date?->format('d M Y') ?? '—' }}</td>
                                <td class="px-3 py-2 align-top text-xs text-gray-500">{{ $e->source }}{{ $e->is_active ? '' : ' · retired' }}</td>
                                <td class="px-3 py-2 align-top text-right">
                                    <button type="button" data-mc-edit="{{ (int) $e->id }}" class="text-xs text-blue-600 hover:underline">Edit</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-4 py-8 text-center text-gray-500">No rows match. @if($stats['total'] === 0) Press “Sync from DRAP” to seed the catalogue. @endif</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4">{{ $entries->links() }}</div>
            </div>
            @endunless
        </div>
    </div>

    @if($ready)
    {{-- Edit modal: one form reused for every row (opened via the Edit buttons). --}}
    <div x-data="mcEditModal()" x-on:mc-edit.window="open($event.detail)" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl p-5" @click.outside="show = false">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">Edit catalogue row #<span x-text="row.id"></span></h3>
            <p class="text-xs text-gray-500 mt-0.5">Raw DRAP composition stays as-is; correct the parsed fields here. Changing the MRP writes a price-history row and notifies every shop that linked this medicine.</p>
            <form :action="baseUrl + '/' + row.id" method="POST" class="mt-3 grid grid-cols-2 gap-2 text-sm">
                @csrf
                @method('PUT')
                <label class="col-span-2 text-xs text-gray-500">Brand <input name="brand_name" x-model="row.brand_name" required class="mt-0.5 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm"></label>
                <label class="col-span-2 text-xs text-gray-500">Composition (raw, read-only) <textarea readonly x-text="row.composition" class="mt-0.5 w-full rounded-lg border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300 text-xs" rows="2"></textarea></label>
                <label class="text-xs text-gray-500">Generic / salt <input name="generic_name" x-model="row.generic_name" class="mt-0.5 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm"></label>
                <label class="text-xs text-gray-500">Strength <input name="strength" x-model="row.strength" class="mt-0.5 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm"></label>
                <label class="text-xs text-gray-500">Dosage form
                    <select name="dosage_form" x-model="row.dosage_form" class="mt-0.5 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                        <option value="">—</option>
                        @foreach(\App\Models\Product::DOSAGE_FORMS as $df)<option value="{{ $df }}">{{ ucfirst($df) }}</option>@endforeach
                    </select>
                </label>
                <label class="text-xs text-gray-500">Manufacturer <input name="manufacturer" x-model="row.manufacturer" class="mt-0.5 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm"></label>
                <label class="text-xs text-gray-500">Pack size <input name="pack_size" x-model="row.pack_size" class="mt-0.5 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm"></label>
                <label class="text-xs text-gray-500">Category
                    <select name="category" x-model="row.category" class="mt-0.5 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm">
                        <option value="normal">Normal</option><option value="essential">Essential</option><option value="low_price">Low Price</option>
                    </select>
                </label>
                <label class="text-xs text-gray-500">MRP (Rs) <input name="mrp" type="number" step="0.01" min="0" x-model="row.mrp" class="mt-0.5 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm"></label>
                <label class="text-xs text-gray-500">Effective date <input name="effective_date" type="date" x-model="row.effective_date" class="mt-0.5 w-full rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100 text-sm"></label>
                <label class="col-span-2 flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" x-model="row.is_active" class="rounded border-gray-300"> Active (visible to shops)</label>
                <div class="col-span-2 flex justify-end gap-2 mt-2">
                    <button type="button" @click="show = false" class="px-3 py-1.5 rounded-lg text-sm border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200">Cancel</button>
                    <button type="submit" class="px-4 py-1.5 rounded-lg text-sm bg-emerald-600 text-white font-medium">Save</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        window.mcRows = {!! \Illuminate\Support\Js::from($entries->getCollection()->map(fn ($e) => $e->toPickerArray() + ['is_active' => (bool) $e->is_active])->keyBy('id')) !!};
        function mcEditModal() {
            return {
                show: false,
                baseUrl: @json(route('admin.medicine-catalogue')),
                row: { id: null, brand_name: '', composition: '', generic_name: '', strength: '', dosage_form: '', manufacturer: '', pack_size: '', category: 'normal', mrp: '', effective_date: '', is_active: true },
                open(id) {
                    const r = window.mcRows[id];
                    if (!r) return;
                    this.row = Object.assign({}, r, { mrp: r.mrp === null ? '' : r.mrp, effective_date: r.effective_date || '' });
                    this.show = true;
                }
            };
        }
        function mcSyncStatus(initial, url) {
            return {
                run: initial,
                timer: null,
                init() {
                    if (this.run && this.run.active) this.poll();
                },
                poll() {
                    this.timer = setTimeout(async () => {
                        try {
                            const r = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                            if (r.ok) {
                                const j = await r.json();
                                if (j.run) this.run = j.run;
                            }
                        } catch (e) { /* keep polling */ }
                        if (this.run && this.run.active) this.poll();
                    }, 5000);
                }
            };
        }
        // Row "Edit" buttons open the shared modal.
        document.addEventListener('click', function (ev) {
            const btn = ev.target.closest('button[data-mc-edit]');
            if (!btn) return;
            window.dispatchEvent(new CustomEvent('mc-edit', { detail: parseInt(btn.getAttribute('data-mc-edit'), 10) }));
        });
    </script>
    @endif
</x-admin-layout>
