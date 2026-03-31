@if(!($isRestaurant ?? false) && isset($drafts))
<div class="mt-4" x-data="{ activeTab: 'dashboard' }">
    <div class="flex gap-1 mb-4">
        <button @click="activeTab = 'dashboard'" :class="activeTab === 'dashboard' ? 'bg-purple-600 text-white shadow-sm' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800'" class="px-4 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider transition-all">Dashboard</button>
        <button @click="activeTab = 'drafts'" :class="activeTab === 'drafts' ? 'bg-purple-600 text-white shadow-sm' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800'" class="px-4 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider transition-all flex items-center gap-1.5">Drafts <span class="text-[8px] px-1.5 py-0.5 rounded-full" :class="activeTab === 'drafts' ? 'bg-white/20' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400'" x-data x-text="document.querySelectorAll('[data-draft-count]').length > 0 ? '{{ count($drafts) }}' : '{{ count($drafts) }}'">{{ count($drafts) }}</span></button>
    </div>

    <div x-show="activeTab === 'drafts'" x-cloak x-data="draftsManager()" x-init="init()">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 overflow-hidden">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-5 border-b border-gray-100/50 dark:border-gray-800/50">
                <div>
                    <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wide">Saved Drafts</h3>
                    <p class="text-[10px] text-gray-400 mt-0.5">Auto-saved invoices not yet finalized</p>
                </div>
                <button @click="deleteAllDrafts()" x-show="drafts.length > 1" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold text-red-600 bg-red-50 dark:bg-red-900/20 dark:text-red-400 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/40 transition mt-2 sm:mt-0">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete All
                </button>
            </div>
            <div class="divide-y divide-gray-50 dark:divide-gray-800/50">
                <template x-for="draft in drafts" :key="draft.id">
                    <div class="p-4 sm:p-5 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[8px] font-extrabold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 uppercase">Draft</span>
                                    <span class="text-[11px] font-bold text-gray-900 dark:text-white" x-text="draft.invoice_number"></span>
                                </div>
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[9px] text-gray-400">
                                    <span x-text="draft.customer_name || 'Walk-in'"></span>
                                    <span x-text="(draft.items ? draft.items.length : 0) + ' items'"></span>
                                    <span x-text="timeAgo(draft.updated_at)"></span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-extrabold text-gray-900 dark:text-white" x-text="'Rs.' + Number(draft.total_amount || 0).toLocaleString()"></span>
                                <a :href="'/pos/invoice/create?draft_id=' + draft.id" class="inline-flex items-center gap-1 px-3 py-1.5 text-[10px] font-bold text-white bg-purple-600 rounded-lg hover:bg-purple-700 transition shadow-sm">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Continue
                                </a>
                                <button @click="deleteDraft(draft.id)" class="p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                        <template x-if="draft.items && draft.items.length > 0">
                            <div class="mt-2 flex flex-wrap gap-1">
                                <template x-for="item in draft.items.slice(0, 5)" :key="item.id">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[9px] bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                        <span x-text="item.item_name"></span>
                                        <span class="ml-1 text-gray-300 dark:text-gray-600" x-text="'x' + item.quantity"></span>
                                    </span>
                                </template>
                                <template x-if="draft.items.length > 5">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[9px] bg-gray-50 text-gray-400 dark:bg-gray-800" x-text="'+' + (draft.items.length - 5) + ' more'"></span>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <template x-if="drafts.length === 0">
                <div class="p-10 text-center">
                    <div class="w-12 h-12 mx-auto rounded-2xl bg-gray-50 dark:bg-gray-800 flex items-center justify-center mb-3">
                        <svg class="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <p class="text-[11px] text-gray-400">No drafts saved</p>
                </div>
            </template>
        </div>
    </div>
</div>
@endif
