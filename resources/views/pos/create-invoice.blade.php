<x-pos-layout>
    <style>
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
        @keyframes priceGlow{0%{box-shadow:0 0 0 2px rgba(168,85,247,0.3)}50%{box-shadow:0 0 0 4px rgba(168,85,247,0.15)}100%{box-shadow:0 0 0 2px rgba(168,85,247,0.3)}}

        /* ═══ Cart row — active indicator (emerald theme) ═══ */
        .pos-cart-row { position: relative; transition: box-shadow 0.22s ease, transform 0.22s ease, border-color 0.22s ease, background 0.22s ease; border: 1px solid transparent; }
        .pos-cart-row:hover:not(.is-active) { border-color: rgb(167 243 208); background: rgba(236,253,245,0.5); }
        .dark .pos-cart-row:hover:not(.is-active) { border-color: rgb(6 95 70 / 0.5); background: rgba(6,78,59,0.18); }
        .pos-cart-row.is-active {
            background: linear-gradient(180deg, rgba(236,253,245,0.95), rgba(209,250,229,0.6)) !important;
            border-color: transparent !important;
            box-shadow: 0 0 0 2px rgba(16,185,129,0.55), 0 12px 28px -10px rgba(16,185,129,0.45);
            transform: translateY(-1px);
            animation: posActiveGlow 2.4s ease-in-out infinite;
        }
        .dark .pos-cart-row.is-active {
            background: linear-gradient(180deg, rgba(6,78,59,0.55), rgba(20,83,45,0.45)) !important;
        }
        .pos-cart-row.is-active::before {
            content: ''; position: absolute; left: -1px; top: 6px; bottom: 6px;
            width: 4px; border-radius: 4px;
            background: linear-gradient(180deg, #10b981, #059669, #047857);
            box-shadow: 0 0 12px rgba(16,185,129,0.6);
        }
        @keyframes posActiveGlow {
            0%,100% { box-shadow: 0 0 0 2px rgba(16,185,129,0.55), 0 12px 28px -10px rgba(16,185,129,0.45); }
            50%     { box-shadow: 0 0 0 2px rgba(5,150,105,0.7), 0 14px 32px -10px rgba(5,150,105,0.55); }
        }

        /* ═══ Row number badge ═══ */
        .pos-row-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 9999px;
            font-size: 11px; font-weight: 900;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 12px -2px rgba(16,185,129,0.5), inset 0 1px 0 rgba(255,255,255,0.25);
            font-variant-numeric: tabular-nums;
            position: relative;
            transition: transform 0.2s ease;
        }
        .pos-cart-row.is-active .pos-row-badge { transform: scale(1.1); background: linear-gradient(135deg, #10b981, #059669, #047857); }
        .pos-cart-row.is-active .pos-row-badge::after {
            content: ''; position: absolute; inset: -3px; border-radius: 9999px;
            background: linear-gradient(135deg,#10b981,#059669);
            z-index: -1; filter: blur(8px); opacity: 0.7;
            animation: posBadgeRing 2s ease-in-out infinite;
        }
        @keyframes posBadgeRing {
            0%,100% { opacity: 0.5; filter: blur(8px); }
            50%     { opacity: 0.9; filter: blur(12px); }
        }

        /* ═══ Premium focus ring (emerald) ═══ */
        .pos-cart-row input:focus-visible, .pos-cart-row select:focus-visible {
            outline: none !important;
            box-shadow: 0 0 0 3px rgba(16,185,129,0.22), 0 1px 2px rgba(0,0,0,0.04) !important;
            border-color: rgb(16 185 129) !important;
        }

        /* ═══ kbd chips ═══ */
        .pos-kbd { background: linear-gradient(180deg,#334155,#1e293b); color:#fff; padding:2px 7px; border-radius:5px; font-size:10px; font-family:ui-monospace,Menlo,monospace; font-weight:700; box-shadow: 0 1px 0 rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.08); border: 1px solid #475569; }

        @media (prefers-reduced-motion: reduce) {
            .pos-cart-row.is-active { animation: none; }
            .pos-cart-row.is-active .pos-row-badge::after { animation: none; }
        }
    </style>
    <div class="pb-36" x-data="posInvoice()"
         @keydown.window="handleGlobalKey($event)">

        {{-- ENTERPRISE CUSTOMER MODAL (auto-opens on POS load) --}}
        <div x-show="showCustomerModal" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background: rgba(0,0,0,0.45);"
             @keydown.escape.prevent.stop="confirmWalkIn()">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md p-6 border border-gray-200 dark:border-gray-700"
                 @click.stop>
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center shadow-lg">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-white">Customer Selection</h2>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Walk-in selected by default · Press W or Enter to continue</p>
                    </div>
                </div>

                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Mobile Number <span class="text-gray-400 font-normal">(optional)</span></label>
                <input type="text" x-ref="cmMobile" x-model="cmMobileInput"
                       @input="cmFilterCustomers()"
                       @keydown.arrow-down.prevent="cmHl = Math.min(cmHl + 1, cmFiltered.length - 1)"
                       @keydown.arrow-up.prevent="cmHl = Math.max(cmHl - 1, -1)"
                       @keydown.enter.prevent="cmConfirmSelection()"
                       placeholder="03XX-XXXXXXX or leave empty for Walk-in"
                       class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition mb-2">

                <div x-show="cmMobileInput.length > 0 && cmFiltered.length > 0" class="max-h-40 overflow-y-auto border border-gray-100 dark:border-gray-800 rounded-lg mb-3">
                    <template x-for="(c, idx) in cmFiltered" :key="c.id">
                        <button type="button"
                                @click="cmSelectExisting(c)"
                                @mouseenter="cmHl = idx"
                                class="w-full text-left px-3 py-2 text-sm flex justify-between items-center transition"
                                :class="cmHl === idx ? 'bg-purple-50 dark:bg-purple-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-800'">
                            <span class="font-medium text-gray-800 dark:text-gray-200" x-text="c.name || 'Unnamed'"></span>
                            <span class="text-xs text-gray-500" x-text="c.phone || ''"></span>
                        </button>
                    </template>
                </div>

                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-wrap gap-2">
                    <button type="button" @click="confirmWalkIn()"
                            class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white text-sm font-bold rounded-lg shadow transition">
                        <kbd class="px-1.5 py-0.5 bg-white/20 rounded text-[10px]">W</kbd>
                        Walk-in Customer
                    </button>
                    <button type="button" @click="cmConfirmSelection()"
                            class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-purple-500 to-violet-600 hover:from-purple-600 hover:to-violet-700 text-white text-sm font-bold rounded-lg shadow transition">
                        <kbd class="px-1.5 py-0.5 bg-white/20 rounded text-[10px]">⏎</kbd>
                        Continue
                    </button>
                </div>

                <div class="mt-3 text-center">
                    <button type="button" @click="showHelpModal = true; showCustomerModal = false"
                            class="text-[11px] text-gray-400 hover:text-purple-600 transition">
                        Press <kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px]">?</kbd> to view all keyboard shortcuts
                    </button>
                </div>
            </div>
        </div>

        {{-- EXIT CONFIRMATION MODAL --}}
        <div x-show="showExitModal" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background: rgba(0,0,0,0.5);"
             @keydown.escape.prevent.stop="showExitModal = false; pendingExitUrl = null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md p-6 border border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    </div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-white">Exit POS?</h2>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    You have <span class="font-bold text-purple-600" x-text="items.filter(i => i.name && i.name.trim()).length"></span> item(s) in your current cart. Unsaved changes will be lost.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="showExitModal = false; pendingExitUrl = null"
                            class="flex-1 px-4 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-lg hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                        Stay (Esc)
                    </button>
                    <button type="button" @click="parkAndExit()"
                            class="flex-1 px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-lg transition">
                        Park & Exit
                    </button>
                    <button type="button" @click="forceExit()"
                            class="flex-1 px-4 py-2.5 bg-red-500 hover:bg-red-600 text-white text-sm font-semibold rounded-lg transition">
                        Exit
                    </button>
                </div>
            </div>
        </div>

        {{-- KEYBOARD CHEAT SHEET --}}
        <div x-show="showHelpModal" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background: rgba(0,0,0,0.5);"
             @click="showHelpModal = false"
             @keydown.escape.prevent.stop="showHelpModal = false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl p-6 border border-gray-200 dark:border-gray-700" @click.stop>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white">⌨️ Keyboard Shortcuts</h2>
                    <button @click="showHelpModal = false" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">✕</button>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div class="space-y-2">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-purple-600 mb-1">Customer & Items</p>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-400">Walk-in customer</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px] font-mono">W</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-400">Focus search</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px] font-mono">F2</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-400">Focus cart</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px] font-mono">F6</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-400">Quantity prefix</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px] font-mono">5 burger ⏎</kbd></div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-purple-600 mb-1">Actions</p>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-400">Parked orders</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px] font-mono">F4</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-400">Repeat last order</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px] font-mono">F7</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-400">Go to payment</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px] font-mono">F8</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-400">Park (save draft)</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px] font-mono">F9</kbd></div>
                        <div class="flex justify-between"><span class="text-gray-600 dark:text-gray-400">New sale</span><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-[10px] font-mono">F10</kbd></div>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="soundEnabled" @change="saveSoundPref()" class="rounded text-purple-600 focus:ring-purple-500">
                        <span class="text-xs text-gray-600 dark:text-gray-400">🔊 Sound feedback</span>
                    </label>
                    <span class="text-[10px] text-gray-400">Press <kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-800 rounded">?</kbd> anytime</span>
                </div>
            </div>
        </div>

        {{-- TOAST FEEDBACK --}}
        <div x-show="toast.visible" x-cloak x-transition
             class="fixed top-4 right-4 z-[60] px-4 py-2.5 rounded-lg shadow-lg text-sm font-medium"
             :class="toast.type === 'error' ? 'bg-red-500 text-white' : (toast.type === 'success' ? 'bg-emerald-500 text-white' : 'bg-gray-800 text-white')"
             x-text="toast.message"></div>

        @php
            $__agentEnabled = $company->agent_enabled ?? false;
            $__agentLastSeen = $company->agent_last_seen ?? null;
            $__agentOnline = $__agentEnabled && $__agentLastSeen && \Carbon\Carbon::parse($__agentLastSeen)->gt(now()->subMinutes(3));
        @endphp

        @if($__agentEnabled)
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 mb-3">
            <div class="flex items-center gap-2 text-xs px-3 py-1.5 rounded-md border
                        {{ $__agentOnline ? 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-900/20 dark:border-emerald-800 dark:text-emerald-300'
                                          : 'bg-red-50 border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300' }}">
                <span class="relative flex h-2 w-2">
                    @if($__agentOnline)
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    @else
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                    @endif
                </span>
                <span class="font-semibold">{{ $__agentOnline ? '🟢 Agent Online — Auto-syncing to PRA' : '🔴 Agent Offline' }}</span>
                @if($__agentLastSeen)
                    <span class="text-[11px] opacity-70">· Last seen {{ \Carbon\Carbon::parse($__agentLastSeen)->diffForHumans() }}</span>
                @endif
            </div>
        </div>
        @endif

        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            <template x-if="showDraftRecovery">
                <div class="mb-6 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl p-4">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-amber-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        <div class="flex-1">
                            <h4 class="text-sm font-semibold text-amber-800 dark:text-amber-300">Recovered Draft Invoice</h4>
                            <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">An unfinished invoice was found. Continue editing?</p>
                            <div class="flex gap-2 mt-3">
                                <button type="button" @click="restoreDraft()" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold rounded-lg transition">
                                    Continue Editing
                                </button>
                                <button type="button" @click="discardDraft()" class="px-3 py-1.5 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 text-xs font-semibold rounded-lg transition">
                                    Discard Draft
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            @if(isset($pendingDrafts) && $pendingDrafts->count() > 0 && !request('draft_id'))
            <div class="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-blue-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <div class="flex-1">
                        <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300">Pending Draft Invoices</h4>
                        <div class="mt-2 space-y-1">
                            @foreach($pendingDrafts as $draft)
                            <div class="flex items-center justify-between text-xs">
                                <a href="{{ route('pos.invoice.create', ['draft_id' => $draft->id]) }}" class="text-blue-600 hover:underline font-medium">
                                    {{ $draft->invoice_number }} - {{ $draft->customer_name ?? 'Walk-in' }} - Rs {{ number_format($draft->total_amount, 2) }}
                                </a>
                                <span class="text-gray-400">{{ $draft->updated_at->diffForHumans() }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
                <div class="flex items-center space-x-3">
                    <a href="{{ route('pos.dashboard') }}" class="inline-flex items-center text-gray-500 hover:text-emerald-600 transition text-sm">
                        <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back
                    </a>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100">Create POS Invoice</h2>
                </div>
                <div class="mt-2 sm:mt-0 flex items-center space-x-2">
                    <template x-if="draftId">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                            Draft Auto-Saved
                        </span>
                    </template>
                    <template x-if="autoSaveStatus">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs text-gray-400" x-text="autoSaveStatus"></span>
                    </template>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold"
                        :class="praEnabled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'">
                        <span class="w-2 h-2 rounded-full mr-1.5" :class="praEnabled ? 'bg-emerald-500' : 'bg-gray-400'"></span>
                        PRA Reporting: <span x-text="praEnabled ? 'Active' : 'Inactive'" class="ml-1"></span>
                    </span>
                </div>
            </div>

            <form method="POST" action="{{ route('pos.invoice.store') }}" @submit.prevent="submitForm($event)" class="space-y-6">
                @csrf
                <input type="hidden" name="draft_id" :value="draftId">

                @if($errors->any())
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-4">
                    <ul class="text-sm text-red-700 dark:text-red-400 list-disc pl-4">
                        @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Customer & Terminal</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Customer Name <span class="text-gray-400">(Optional)</span></label>
                            <input type="text" name="customer_name" x-model="customerName" placeholder="Walk-in Customer" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Phone <span class="text-gray-400">(Optional)</span></label>
                            <input type="text" name="customer_phone" x-model="customerPhone" placeholder="03XX-XXXXXXX" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Terminal</label>
                            <select name="terminal_id" x-model="terminalId" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                                <option value="">Select Terminal</option>
                                @foreach($terminals as $terminal)
                                <option value="{{ $terminal->id }}">{{ $terminal->terminal_name }} ({{ $terminal->terminal_code }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Invoice Items</h3>
                        <button type="button" @click="addItem()" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg transition btn-premium">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Item
                        </button>
                    </div>

                    <div class="hidden sm:grid sm:grid-cols-12 gap-2 text-xs font-medium text-gray-500 dark:text-gray-400 mb-2 px-1">
                        <div class="col-span-2">Type</div>
                        <div class="col-span-4">Item Name</div>
                        <div class="col-span-2">Qty</div>
                        <div class="col-span-2">Unit Price</div>
                        <div class="col-span-1">Subtotal</div>
                        <div class="col-span-1"></div>
                    </div>

                    <template x-for="(item, index) in items" :key="index">
                        <div class="pos-cart-row relative grid grid-cols-1 sm:grid-cols-12 gap-2 mb-3 p-3 sm:py-3 sm:pl-12 sm:pr-3 rounded-xl"
                            :class="activeItemIndex === index ? 'is-active' : ''"
                            :data-row-index="index"
                            @focusin="activeItemIndex = index"
                            @click="activeItemIndex = index">
                            {{-- Desktop row number badge (absolute, doesn't break grid) --}}
                            <span class="pos-row-badge hidden sm:flex absolute left-2 top-1/2 -translate-y-1/2" x-text="index + 1"></span>
                            {{-- Mobile header — badge + name + remove --}}
                            <div class="sm:hidden flex items-center justify-between -mt-1 mb-1">
                                <div class="flex items-center gap-2">
                                    <span class="pos-row-badge" x-text="index + 1"></span>
                                    <span class="text-xs font-semibold text-gray-700 dark:text-gray-300" x-text="item.name || 'New Item'"></span>
                                </div>
                                <button type="button" @click.stop="removeItem(index)" x-show="items.length > 1" class="text-red-500 hover:text-red-700 text-xs font-bold">✕</button>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block sm:hidden text-xs text-gray-500 mb-1">Type</label>
                                <select x-model="item.type" @change="onTypeChange(index)" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-2 py-2 focus:ring-2 focus:ring-emerald-500 transition">
                                    <option value="product">Product</option>
                                    <option value="service">Service</option>
                                </select>
                            </div>
                            <div class="sm:col-span-4 relative" x-init="ddSearch[index] = ddSearch[index] || item.name || ''; ddOpen[index] = ddOpen[index] || false; ddHlIdx[index] = ddHlIdx[index] ?? -1">
                                <label class="block sm:hidden text-xs text-gray-500 mb-1">Item Name</label>
                                <div class="relative">
                                    <input type="text"
                                        :value="ddSearch[index]"
                                        @input="ddSearch[index] = $event.target.value; ddOpen[index] = true; ddHlIdx[index] = 0; item.name = ddSearch[index]; item.item_id = ''; item._isNew = true; recalculate()"
                                        @focus="ddOpen[index] = true; ddHlIdx[index] = -1"
                                        @click.away="ddOpen[index] = false; ddHlIdx[index] = -1"
                                        @keydown.escape="ddOpen[index] = false; ddHlIdx[index] = -1"
                                        @keydown.arrow-down.prevent="ddKeyDown(index)"
                                        @keydown.arrow-up.prevent="ddKeyUp(index)"
                                        @keydown.enter.prevent="ddKeyEnter(index)"
                                        @keydown.tab="if(item._isNew && (ddSearch[index]||'').length>0){ ddOpen[index]=false; ddHlIdx[index]=-1; $nextTick(()=>{ const el=document.getElementById('price-'+index); if(el) el.focus(); }); }"
                                        placeholder="Type or search product..."
                                        class="w-full rounded-lg border text-sm px-3 py-2 pr-16 transition"
                                        :style="item._isNew && (ddSearch[index]||'').length > 0 ? 'border-color: #a855f7; box-shadow: 0 0 0 2px rgba(168,85,247,0.15);' : 'border-color: #d1d5db;'"
                                        :class="'bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-emerald-500'">
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-2 gap-1">
                                        <template x-if="item.item_id && !item._isNew">
                                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[9px] font-bold" style="background:#d1fae5;color:#047857;">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                Saved
                                            </span>
                                        </template>
                                        <template x-if="item._isNew && (ddSearch[index] || '').length > 0 && item.type === 'product'">
                                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[9px] font-bold" style="background:#f3e8ff;color:#7c3aed;animation:pulse 2s infinite;">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                                NEW
                                            </span>
                                        </template>
                                    </div>
                                </div>
                                <div x-show="ddOpen[index]"
                                    x-transition
                                    :id="'pos-dd-' + index"
                                    class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl max-h-52 overflow-y-auto">
                                    <template x-if="item.type === 'product'">
                                        <div>
                                            <template x-for="(p, pIdx) in ddGetFiltered(index)" :key="p.id">
                                                <button type="button"
                                                    role="option"
                                                    :data-hl="ddHlIdx[index] === pIdx ? 'true' : 'false'"
                                                    @click="ddSelect(index, p)"
                                                    @mouseenter="ddHlIdx[index] = pIdx"
                                                    class="w-full text-left px-3 py-2.5 text-sm flex justify-between items-center"
                                                    :style="ddHlIdx[index] === pIdx ? 'background: linear-gradient(90deg,#d1fae5,#ecfdf5); outline: 2px solid #34d399; outline-offset:-2px; border-radius:6px;' : ''">
                                                    <span class="flex items-center gap-1.5">
                                                        <svg class="w-3.5 h-3.5 flex-shrink-0" :style="ddHlIdx[index] === pIdx ? 'color:#059669;' : 'color:#9ca3af;'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                        <span class="font-medium" :style="ddHlIdx[index] === pIdx ? 'color: #064e3b;' : ''" x-text="p.name"></span>
                                                        <span x-show="p.is_tax_exempt" class="inline-flex px-1 py-0.5 rounded text-[8px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">EXEMPT</span>
                                                        <span x-show="hasRecipe(p)" class="inline-flex px-1 py-0.5 rounded text-[9px]" title="Has recipe">📋</span>
                                                        <template x-if="stockBadge(p)">
                                                            <span class="inline-flex px-1.5 py-0.5 rounded text-[9px] font-bold"
                                                                  :class="stockBadge(p).cls"
                                                                  x-text="stockBadge(p).label"></span>
                                                        </template>
                                                    </span>
                                                    <span class="text-xs font-semibold" :style="ddHlIdx[index] === pIdx ? 'color: #047857;' : 'color: #6b7280;'" x-text="'Rs ' + Number(p.price || p.unit_price || 0).toLocaleString()"></span>
                                                </button>
                                            </template>
                                            <template x-if="(ddSearch[index] || '').length > 0 && ddIsNewProduct(index)">
                                                <button type="button"
                                                    @click="ddConfirmNew(index)"
                                                    class="w-full text-left px-3 py-3 flex items-center gap-2 border-t border-gray-100 dark:border-gray-700 transition-all"
                                                    style="background: linear-gradient(90deg,#faf5ff,#f3e8ff); cursor:pointer;"
                                                    @mouseenter="this.style.background='linear-gradient(90deg,#f3e8ff,#ede9fe)'"
                                                    @mouseleave="this.style.background='linear-gradient(90deg,#faf5ff,#f3e8ff)'">
                                                    <span class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center" style="background:#7c3aed;color:white;">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                                    </span>
                                                    <span class="flex flex-col">
                                                        <span class="text-sm font-semibold" style="color:#7c3aed;">Add "<span x-text="ddSearch[index]" class="font-bold"></span>" as new product</span>
                                                        <span class="text-[10px]" style="color:#9ca3af;">Set price & quantity below — details can be added later</span>
                                                    </span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="item.type === 'service'">
                                        <div>
                                            <template x-for="(s, sIdx) in ddGetFiltered(index)" :key="s.id">
                                                <button type="button"
                                                    role="option"
                                                    :data-hl="ddHlIdx[index] === sIdx ? 'true' : 'false'"
                                                    @click="ddSelect(index, s)"
                                                    @mouseenter="ddHlIdx[index] = sIdx"
                                                    class="w-full text-left px-3 py-2.5 text-sm flex justify-between items-center"
                                                    :style="ddHlIdx[index] === sIdx ? 'background: linear-gradient(90deg,#d1fae5,#ecfdf5); outline: 2px solid #34d399; outline-offset:-2px; border-radius:6px;' : ''">
                                                    <span class="flex items-center gap-1.5">
                                                        <svg class="w-3.5 h-3.5 flex-shrink-0" :style="ddHlIdx[index] === sIdx ? 'color:#059669;' : 'color:#9ca3af;'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 13.255A23.193 23.193 0 0112 15c-3.183 0-6.22-.64-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                                        <span class="font-medium" :style="ddHlIdx[index] === sIdx ? 'color: #064e3b;' : ''" x-text="s.name"></span>
                                                        <span x-show="s.is_tax_exempt" class="inline-flex px-1 py-0.5 rounded text-[8px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">EXEMPT</span>
                                                    </span>
                                                    <span class="text-xs font-semibold" :style="ddHlIdx[index] === sIdx ? 'color: #047857;' : 'color: #6b7280;'" x-text="'Rs ' + Number(s.price || 0).toLocaleString()"></span>
                                                </button>
                                            </template>
                                            <template x-if="ddGetFiltered(index).length === 0">
                                                <div class="px-3 py-2 text-xs text-gray-400">No matching services found</div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block sm:hidden text-xs text-gray-500 mb-1">Qty</label>
                                {{-- ═══ PHASE 1 — Cart qty: keyboard-first, +/- buttons, select-on-focus ═══ --}}
                                <div class="flex items-stretch rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 overflow-hidden focus-within:ring-2 focus-within:ring-emerald-500 focus-within:border-emerald-500 transition">
                                    <button type="button"
                                        @click.stop="qtyDec(index)"
                                        tabindex="-1"
                                        class="px-2.5 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 active:bg-emerald-100 dark:active:bg-emerald-900/50 font-bold text-lg leading-none transition select-none"
                                        aria-label="Decrease quantity">−</button>
                                    <input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="6"
                                        :data-qty-row="index"
                                        x-init="$el.value = item.quantity; $watch('item.quantity', v => { if(document.activeElement !== $el) $el.value = (v === '' || v === null) ? '' : (parseInt(v) || ''); })"
                                        @input="(() => { let v = $event.target.value.replace(/[^0-9]/g,''); $event.target.value = v; if (v !== '') { item.quantity = parseInt(v, 10) || 1; recalculate(); } })()"
                                        @focus="$nextTick(() => { try { $event.target.select(); } catch(e){} })"
                                        @click.stop="$nextTick(() => { try { $event.target.select(); } catch(e){} })"
                                        @blur="(() => { const raw = ($event.target.value || '').replace(/[^0-9]/g,''); let n = raw === '' ? 1 : (parseInt(raw, 10) || 1); if (!isFinite(n) || n < 1) n = 1; item.quantity = n; $event.target.value = n; recalculate(); })()"
                                        @keydown.arrow-up.prevent="cartNav(-1, index)"
                                        @keydown.arrow-down.prevent="cartNav(1, index)"
                                        @keydown.enter.prevent="cartNav(1, index)"
                                        @keydown.escape.prevent="$event.target.blur()"
                                        class="flex-1 min-w-0 bg-transparent text-center text-base font-bold tabular-nums text-gray-900 dark:text-gray-100 px-1 py-2 border-0 focus:outline-none focus:ring-0">
                                    <button type="button"
                                        @click.stop="qtyInc(index)"
                                        tabindex="-1"
                                        class="px-2.5 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 active:bg-emerald-100 dark:active:bg-emerald-900/50 font-bold text-lg leading-none transition select-none"
                                        aria-label="Increase quantity">+</button>
                                </div>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block sm:hidden text-xs text-gray-500 mb-1">Unit Price</label>
                                <input type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" autocomplete="off" :id="'price-'+index"
                                    :value="item.unit_price"
                                    @input="item.unit_price = ($event.target.value === '' ? '' : (parseFloat($event.target.value.replace(/[^0-9.]/g,'')) || 0)); recalculate()"
                                    @focus="$nextTick(() => $event.target.select())"
                                    @mousedown="if(document.activeElement !== $event.target){ $event.preventDefault(); $event.target.focus(); $event.target.select(); }"
                                    @blur="if(item.unit_price === '' || item.unit_price < 0){ item.unit_price = 0; recalculate(); }"
                                    class="w-full rounded-lg border bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-2 py-2 transition"
                                    :style="item._isNew && item.unit_price === 0 && (ddSearch[index]||'').length > 0 ? 'border-color:#a855f7; box-shadow: 0 0 0 2px rgba(168,85,247,0.2); animation: pulse 1.5s ease-in-out 3;' : 'border-color:#d1d5db;'"
                                    :class="'focus:ring-2 focus:ring-emerald-500'">
                            </div>
                            <div class="sm:col-span-1 flex flex-col items-start gap-1">
                                <div class="flex items-center gap-1">
                                    <label class="block sm:hidden text-xs text-gray-500 mr-2">Subtotal</label>
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200" x-text="'Rs ' + (item.quantity * item.unit_price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                                </div>
                                <button type="button" @click="item.is_tax_exempt = !item.is_tax_exempt; recalculate()" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold cursor-pointer transition-all"
                                    :class="item.is_tax_exempt ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 ring-1 ring-amber-300 dark:ring-amber-600' : 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500 hover:bg-amber-50 hover:text-amber-600 dark:hover:bg-amber-900/20 dark:hover:text-amber-400'">
                                    <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" x-show="item.is_tax_exempt" d="M5 13l4 4L19 7"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" x-show="!item.is_tax_exempt" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                    <span x-text="item.is_tax_exempt ? 'EXEMPT' : 'Taxable'"></span>
                                </button>
                            </div>
                            <div class="sm:col-span-1 flex items-center justify-end">
                                <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                    </template>

                    <div x-show="items.length === 0" class="text-center py-8 text-gray-400 dark:text-gray-500">
                        <svg class="w-10 h-10 mx-auto mb-2 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        <p class="text-sm">No items added yet</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Discount</h3>
                        <div class="flex items-center space-x-2 mb-3">
                            <button type="button" @click="discountType = 'percentage'; recalculate()"
                                :class="discountType === 'percentage' ? 'bg-emerald-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                class="px-4 py-2 rounded-lg text-sm font-medium transition">
                                % Percentage
                            </button>
                            <button type="button" @click="discountType = 'amount'; recalculate()"
                                :class="discountType === 'amount' ? 'bg-emerald-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                class="px-4 py-2 rounded-lg text-sm font-medium transition">
                                Rs Amount
                            </button>
                        </div>
                        <div class="flex items-center space-x-3">
                            <input type="number" x-model.number="discountValue" min="0" step="0.01" @input="recalculate()" :max="discountType === 'percentage' ? 100 : subtotal" placeholder="0" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-emerald-500 transition">
                            <span class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                = Rs <span x-text="discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                            </span>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Payment Method</h3>
                        <div class="grid grid-cols-2 gap-2">
                            <template x-for="pm in paymentMethods" :key="pm.value">
                                <button type="button" @click="selectPaymentMethod(pm.value)"
                                    :class="paymentMethod === pm.value ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 ring-2 ring-emerald-500/30' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'"
                                    class="flex flex-col items-center p-3 rounded-xl border-2 transition cursor-pointer">
                                    <span class="text-xl mb-1" x-text="pm.icon"></span>
                                    <span class="text-xs font-semibold" :class="paymentMethod === pm.value ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'" x-text="pm.label"></span>
                                </button>
                            </template>
                        </div>

                        {{-- ⚡ Fast Cash Tender (only for cash payment) --}}
                        <div x-show="paymentMethod === 'cash'" x-transition class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <label class="flex items-center justify-between text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                <span>💵 Cash Received</span>
                                <button type="button" @click="cashReceived = total; $nextTick(() => $refs.posCashInput && $refs.posCashInput.focus())" class="text-emerald-600 hover:text-emerald-800 text-xs font-bold underline">EXACT</button>
                            </label>
                            <input type="number" x-ref="posCashInput" name="cash_received" x-model.number="cashReceived"
                                @keydown.enter.prevent="$el.closest('form').requestSubmit()"
                                step="0.01" min="0" placeholder="Tendered (Enter = pay)"
                                class="w-full rounded-xl border-2 border-emerald-400 dark:border-emerald-600 dark:bg-gray-800 dark:text-white text-xl font-bold py-3 px-3 shadow-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            <div class="grid grid-cols-4 gap-1 mt-2">
                                <button type="button" @click="cashReceived = (parseFloat(cashReceived||0) + 100)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 dark:text-white rounded font-bold text-xs">+100</button>
                                <button type="button" @click="cashReceived = (parseFloat(cashReceived||0) + 500)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 dark:text-white rounded font-bold text-xs">+500</button>
                                <button type="button" @click="cashReceived = (parseFloat(cashReceived||0) + 1000)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 dark:text-white rounded font-bold text-xs">+1K</button>
                                <button type="button" @click="cashReceived = (parseFloat(cashReceived||0) + 5000)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 dark:text-white rounded font-bold text-xs">+5K</button>
                            </div>
                            <div class="grid grid-cols-3 gap-1 mt-1">
                                <button type="button" @click="cashReceived = Math.ceil(total/500)*500" class="py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 rounded font-bold text-xs">500 note</button>
                                <button type="button" @click="cashReceived = Math.ceil(total/1000)*1000" class="py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 rounded font-bold text-xs">1000 note</button>
                                <button type="button" @click="cashReceived = Math.ceil(total/5000)*5000" class="py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 rounded font-bold text-xs">5000 note</button>
                            </div>
                            {{-- HUGE Change Due Display --}}
                            <div class="mt-3 p-3 rounded-xl text-center transition"
                                :class="(parseFloat(cashReceived||0) <= 0) ? 'bg-gray-100 dark:bg-gray-800' : ((parseFloat(cashReceived||0) - total) >= 0 ? 'bg-emerald-100 dark:bg-emerald-900/40 ring-2 ring-emerald-400' : 'bg-red-100 dark:bg-red-900/40 ring-2 ring-red-400')">
                                <div class="text-[10px] font-bold uppercase tracking-wider"
                                    :class="(parseFloat(cashReceived||0) <= 0) ? 'text-gray-500' : ((parseFloat(cashReceived||0) - total) >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300')"
                                    x-text="(parseFloat(cashReceived||0) <= 0) ? 'CHANGE DUE' : ((parseFloat(cashReceived||0) - total) >= 0 ? 'CHANGE TO RETURN' : 'STILL OWED')"></div>
                                <div class="text-3xl font-black tabular-nums tracking-tight"
                                    :class="(parseFloat(cashReceived||0) <= 0) ? 'text-gray-400' : ((parseFloat(cashReceived||0) - total) >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300')"
                                    x-text="'Rs ' + Math.abs(parseFloat(cashReceived||0) - total).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2})"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Tax Calculation Summary</h3>
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                            <span>Subtotal</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200" x-text="'Rs ' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                            <span>Discount <span x-show="discountValue > 0" class="text-xs">(<span x-text="discountType === 'percentage' ? discountValue + '%' : 'Fixed'"></span>)</span></span>
                            <span class="font-medium text-red-500" x-text="'- Rs ' + discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 border-t border-gray-100 dark:border-gray-800 pt-2">
                            <span>After Discount</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200" x-text="'Rs ' + afterDiscount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                            <span>Tax (<span x-text="taxRate + '%'"></span>)</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200" x-text="'Rs ' + taxAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                        <div x-show="exemptAmount > 0" class="flex justify-between text-xs text-amber-600 dark:text-amber-400">
                            <span>Tax Exempt Amount</span>
                            <span class="font-medium" x-text="'Rs ' + exemptAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                        <div class="flex justify-between text-base font-bold text-gray-900 dark:text-white border-t-2 border-gray-200 dark:border-gray-700 pt-3 mt-2">
                            <span>Total</span>
                            <span class="text-emerald-600" x-text="'Rs ' + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                    </div>
                </div>

                <template x-for="(item, index) in items" :key="'hidden-'+index">
                    <div>
                        <input type="hidden" :name="'items['+index+'][type]'" :value="item.type">
                        <input type="hidden" :name="'items['+index+'][item_id]'" :value="item.item_id">
                        <input type="hidden" :name="'items['+index+'][name]'" :value="item.name">
                        <input type="hidden" :name="'items['+index+'][quantity]'" :value="item.quantity">
                        <input type="hidden" :name="'items['+index+'][unit_price]'" :value="item.unit_price">
                        <input type="hidden" :name="'items['+index+'][is_tax_exempt]'" :value="item.is_tax_exempt ? '1' : '0'">
                    </div>
                </template>
                <input type="hidden" name="discount_type" :value="discountType">
                <input type="hidden" name="discount_value" :value="discountValue">
                <input type="hidden" name="payment_method" :value="paymentMethod">
                <input type="hidden" name="terminal_id" :value="terminalId">

                <div class="flex justify-end">
                    <button type="submit" :disabled="items.length === 0 || submitting" class="inline-flex items-center px-6 py-3 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-xl shadow-sm transition btn-premium">
                        <svg x-show="!submitting" class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <svg x-show="submitting" class="w-5 h-5 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span x-text="submitting ? 'Processing...' : 'Create Invoice'"></span>
                    </button>
                </div>
            </form>
        </div>

        <div class="fixed bottom-0 left-0 right-0 lg:left-64 z-20 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 shadow-lg">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
                <div class="flex items-center space-x-6 text-sm">
                    <div>
                        <span class="text-gray-400 text-xs">Items</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200" x-text="items.length"></p>
                    </div>
                    <div>
                        <span class="text-gray-400 text-xs">Subtotal</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200" x-text="'Rs ' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                    </div>
                    <div class="hidden sm:block">
                        <span class="text-gray-400 text-xs">Discount</span>
                        <p class="font-semibold text-red-500" x-text="'- Rs ' + discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                    </div>
                    <div class="hidden sm:block">
                        <span class="text-gray-400 text-xs">Tax</span>
                        <p class="font-semibold text-gray-800 dark:text-gray-200" x-text="'Rs ' + taxAmount.toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-gray-400 text-xs">Total</span>
                    <p class="font-bold text-lg text-emerald-600" x-text="'Rs ' + total.toLocaleString(undefined, {minimumFractionDigits: 2})"></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function posInvoice() {
            return {
                products: @json($products),
                services: @json($services),
                taxRules: @json($taxRules),
                posCustomers: @json($posCustomers ?? []),
                praEnabled: {{ $company->pra_reporting_enabled ? 'true' : 'false' }},

                customerName: '',
                customerPhone: '',
                terminalId: '',

                showCustomerModal: true,
                showExitModal: false,
                showHelpModal: false,
                pendingExitUrl: null,
                cmMobileInput: '',
                cmFiltered: [],
                cmHl: -1,

                soundEnabled: false,
                toast: { visible: false, message: '', type: 'info', timer: null },

                items: [
                    { type: 'product', item_id: '', name: '', quantity: 1, unit_price: 0, _isNew: false, is_tax_exempt: false }
                ],

                activeItemIndex: 0,

                // ═══ PHASE 1 — Cart keyboard engine ═══
                qtyInc(idx) {
                    if (idx < 0 || idx >= this.items.length) return;
                    this.activeItemIndex = idx;
                    const cur = parseInt(this.items[idx].quantity, 10) || 0;
                    this.items[idx].quantity = cur + 1;
                    this.recalculate();
                },
                qtyDec(idx) {
                    if (idx < 0 || idx >= this.items.length) return;
                    this.activeItemIndex = idx;
                    const cur = parseInt(this.items[idx].quantity, 10) || 1;
                    this.items[idx].quantity = Math.max(1, cur - 1);
                    this.recalculate();
                },
                cartNav(direction, fromIdx) {
                    const len = this.items.length;
                    if (len === 0) return;
                    const baseIdx = (fromIdx === undefined || fromIdx === null) ? this.activeItemIndex : fromIdx;
                    const nextIdx = Math.min(len - 1, Math.max(0, baseIdx + direction));
                    if (nextIdx === baseIdx) return;
                    this.activeItemIndex = nextIdx;
                    this.cartFocusActiveQty();
                },
                cartFocusActiveQty() {
                    this.$nextTick(() => {
                        const el = document.querySelector('[data-qty-row="' + this.activeItemIndex + '"]');
                        if (el) {
                            el.focus();
                            try { el.select(); } catch (e) {}
                        }
                    });
                },
                cartTypeDigit(digit) {
                    if (this.items.length === 0) return;
                    const idx = this.activeItemIndex;
                    if (idx < 0 || idx >= this.items.length) return;
                    const n = parseInt(digit, 10);
                    if (isNaN(n) || n < 0 || n > 9) return;
                    this.items[idx].quantity = n === 0 ? 1 : n;
                    this.recalculate();
                    this.$nextTick(() => {
                        const el = document.querySelector('[data-qty-row="' + idx + '"]');
                        if (el) {
                            el.value = String(this.items[idx].quantity);
                            el.focus();
                            try {
                                const len = el.value.length;
                                el.setSelectionRange(len, len);
                            } catch (e) {}
                        }
                    });
                },
                // ═══ END PHASE 1 cart keyboard engine ═══

                ddOpen: {},
                ddSearch: {},
                ddHlIdx: {},

                ddGetFiltered(idx) {
                    let s = (this.ddSearch[idx] || '').toLowerCase();
                    if (this.items[idx].type === 'product') {
                        return this.products.filter(pr => !s || pr.name.toLowerCase().includes(s));
                    }
                    return this.services.filter(sv => !s || sv.name.toLowerCase().includes(s));
                },

                ddSelect(idx, p) {
                    this.ddSearch[idx] = p.name;
                    this.items[idx].name = p.name;
                    this.items[idx].item_id = p.id;
                    this.items[idx].unit_price = parseFloat(p.price || p.unit_price || 0);
                    this.items[idx].is_tax_exempt = !!p.is_tax_exempt;
                    this.items[idx]._isNew = false;
                    this.ddOpen[idx] = false;
                    this.ddHlIdx[idx] = -1;
                    this.recalculate();
                },

                ddScrollTo(idx) {
                    this.$nextTick(() => {
                        const container = document.getElementById('pos-dd-' + idx);
                        if (container) {
                            const active = container.querySelector('[data-hl="true"]');
                            if (active) active.scrollIntoView({ block: 'nearest' });
                        }
                    });
                },

                ddKeyDown(idx) {
                    if (!this.ddOpen[idx]) { this.ddOpen[idx] = true; this.ddHlIdx[idx] = 0; return; }
                    let f = this.ddGetFiltered(idx);
                    if ((this.ddHlIdx[idx] || 0) < f.length - 1) { this.ddHlIdx[idx] = (this.ddHlIdx[idx] || 0) + 1; this.ddScrollTo(idx); }
                },

                ddKeyUp(idx) {
                    if ((this.ddHlIdx[idx] || 0) > 0) { this.ddHlIdx[idx]--; this.ddScrollTo(idx); }
                },

                ddKeyEnter(idx) {
                    // 🚀 Smart Enter: pick highlighted dropdown item, OR accept typed text as new, OR just close + jump to qty
                    const hl = this.ddHlIdx[idx];
                    if (this.ddOpen[idx] && hl !== undefined && hl >= 0) {
                        let f = this.ddGetFiltered(idx);
                        if (f[hl]) { this.ddSelect(idx, f[hl]); this.focusQtyRow(idx); return; }
                    }
                    const typed = (this.ddSearch[idx] || '').trim();
                    if (typed.length > 0) {
                        this.items[idx]._isNew = true;
                        this.items[idx].name = typed;
                        this.items[idx].item_id = '';
                        this.ddOpen[idx] = false;
                        this.ddHlIdx[idx] = -1;
                        this.focusQtyRow(idx);
                        return;
                    }
                    this.ddOpen[idx] = false;
                    this.focusQtyRow(idx);
                },

                focusQtyRow(idx) {
                    this.$nextTick(() => {
                        const el = document.querySelector('[data-qty-row="' + idx + '"]');
                        if (el) { el.focus(); }
                    });
                },

                ddIsNewProduct(idx) {
                    let s = (this.ddSearch[idx] || '').toLowerCase();
                    if (!s) return false;
                    return this.items[idx].type === 'product'
                        ? !this.products.some(pr => pr.name.toLowerCase() === s)
                        : false;
                },

                ddConfirmNew(idx) {
                    this.items[idx]._isNew = true;
                    this.items[idx].name = this.ddSearch[idx];
                    this.items[idx].item_id = '';
                    this.ddOpen[idx] = false;
                    this.ddHlIdx[idx] = -1;
                    this.$nextTick(() => {
                        const el = document.getElementById('price-' + idx);
                        if (el) { el.focus(); el.select(); }
                    });
                },

                discountType: 'percentage',
                discountValue: 0,
                paymentMethod: 'cash',
                cashReceived: 0,

                subtotal: 0,
                discountAmount: 0,
                afterDiscount: 0,
                taxRate: 0,
                taxAmount: 0,
                exemptAmount: 0,
                total: 0,

                submitting: false,
                draftId: {!! isset($draftInvoice) && $draftInvoice ? $draftInvoice->id : 'null' !!},
                autoSaveTimer: null,
                autoSaveStatus: '',
                showDraftRecovery: false,
                recoveredDraft: null,

                paymentMethods: [
                    { value: 'cash', label: 'Cash', icon: '💵' },
                    { value: 'debit_card', label: 'Debit Card', icon: '💳' },
                    { value: 'credit_card', label: 'Credit Card', icon: '🏦' },
                    { value: 'qr_payment', label: 'QR / Raast', icon: '📱' }
                ],

                init() {
                    @if(isset($draftInvoice) && $draftInvoice)
                        this.loadServerDraft(@json($draftInvoice));
                    @else
                        this.checkLocalDraft();
                    @endif

                    this.fetchTaxRate(this.paymentMethod);
                    this.recalculate();
                    this.startAutoSave();

                    try { this.soundEnabled = localStorage.getItem('pos_sound_enabled') === '1'; } catch(e) {}

                    this.$nextTick(() => {
                        if (this.showCustomerModal && this.$refs.cmMobile) {
                            this.$refs.cmMobile.focus();
                        }
                    });

                    document.querySelectorAll('a[href]:not([href^="#"]):not([target="_blank"])').forEach(link => {
                        if (link.dataset.exitGuarded) return;
                        link.dataset.exitGuarded = '1';
                        link.addEventListener('click', (e) => {
                            const href = link.getAttribute('href');
                            if (!href || href.startsWith('javascript:')) return;
                            const cartHasItems = this.items.some(i => i.name && i.name.trim());
                            if (cartHasItems && !this.submitting) {
                                e.preventDefault();
                                this.pendingExitUrl = href;
                                this.showExitModal = true;
                            }
                        });
                    });

                    window.addEventListener('beforeunload', (e) => {
                        if (!this.submitting) {
                            this.saveToLocalStorage();
                            const cartHasItems = this.items.some(i => i.name && i.name.trim());
                            if (cartHasItems) {
                                e.preventDefault();
                                e.returnValue = '';
                                return '';
                            }
                        }
                    });
                },

                // ============ ENTERPRISE KEYBOARD / CUSTOMER / SOUND SYSTEM ============
                playSound(kind) {
                    if (!this.soundEnabled) return;
                    try {
                        const ctx = new (window.AudioContext || window.webkitAudioContext)();
                        const o = ctx.createOscillator();
                        const g = ctx.createGain();
                        o.connect(g); g.connect(ctx.destination);
                        const f = kind === 'error' ? 220 : (kind === 'success' ? 880 : 660);
                        o.frequency.value = f;
                        o.type = 'sine';
                        g.gain.setValueAtTime(0.08, ctx.currentTime);
                        g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.15);
                        o.start(); o.stop(ctx.currentTime + 0.15);
                    } catch(e) {}
                },
                saveSoundPref() {
                    try { localStorage.setItem('pos_sound_enabled', this.soundEnabled ? '1' : '0'); } catch(e) {}
                    this.showToast(this.soundEnabled ? '🔊 Sound enabled' : '🔇 Sound off', 'info');
                },
                showToast(msg, type = 'info') {
                    if (this.toast.timer) clearTimeout(this.toast.timer);
                    this.toast.message = msg;
                    this.toast.type = type;
                    this.toast.visible = true;
                    this.toast.timer = setTimeout(() => { this.toast.visible = false; }, 2200);
                },
                cmFilterCustomers() {
                    const q = (this.cmMobileInput || '').replace(/\D/g, '');
                    if (!q || q.length < 3) { this.cmFiltered = []; this.cmHl = -1; return; }
                    this.cmFiltered = (this.posCustomers || []).filter(c => {
                        const ph = (c.phone || '').replace(/\D/g, '');
                        return ph.includes(q);
                    }).slice(0, 6);
                    this.cmHl = this.cmFiltered.length > 0 ? 0 : -1;
                },
                cmSelectExisting(c) {
                    this.customerName = c.name || '';
                    this.customerPhone = c.phone || '';
                    this.showCustomerModal = false;
                    this.playSound('success');
                    this.showToast('Customer: ' + (c.name || c.phone), 'success');
                    this.$nextTick(() => this.focusFirstSearch());
                },
                cmConfirmSelection() {
                    if (this.cmHl >= 0 && this.cmFiltered[this.cmHl]) {
                        this.cmSelectExisting(this.cmFiltered[this.cmHl]);
                        return;
                    }
                    if (this.cmMobileInput && this.cmMobileInput.replace(/\D/g, '').length >= 7) {
                        this.customerPhone = this.cmMobileInput.trim();
                        this.customerName = '';
                        this.showCustomerModal = false;
                        this.playSound('success');
                        this.$nextTick(() => this.focusFirstSearch());
                        return;
                    }
                    this.confirmWalkIn();
                },
                confirmWalkIn() {
                    this.customerName = 'Walk-in Customer';
                    this.customerPhone = '';
                    this.showCustomerModal = false;
                    this.playSound('info');
                    this.$nextTick(() => this.focusFirstSearch());
                },

                handleGlobalKey(e) {
                    const tag = document.activeElement?.tagName;
                    const isInput = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';

                    // 'w' shortcut for Walk-in modal
                    if ((e.key === 'w' || e.key === 'W') && this.showCustomerModal && document.activeElement !== this.$refs.cmMobile) {
                        e.preventDefault();
                        this.confirmWalkIn();
                        return;
                    }

                    // 🔒 ISOLATION: User typing in input/textarea/select → ignore everything else
                    if (isInput) return;

                    // ═══ PHASE 1 — Cart-level keyboard (only when no modal open + items present) ═══
                    const noModalOpen = !this.showCustomerModal && !this.showExitModal && !this.showHelpModal;
                    if (noModalOpen && this.items && this.items.length > 0) {
                        // ↑/↓ navigate active cart row
                        if (e.key === 'ArrowUp')   { e.preventDefault(); this.cartNav(-1); return; }
                        if (e.key === 'ArrowDown') { e.preventDefault(); this.cartNav(1);  return; }
                        // Direct digit typing → focus active row qty, set value
                        if (/^[0-9]$/.test(e.key)) { e.preventDefault(); this.cartTypeDigit(e.key); return; }
                        // +/- to inc/dec active row qty (numpad and main row)
                        if (e.key === '+' || e.key === '=') { e.preventDefault(); this.qtyInc(this.activeItemIndex); return; }
                        if (e.key === '-' || e.key === '_') { e.preventDefault(); this.qtyDec(this.activeItemIndex); return; }
                        // Delete → remove active row (with confirm if row has meaningful data; never delete last row)
                        if (e.key === 'Delete') {
                            e.preventDefault();
                            if (this.items.length <= 1) return;
                            const it = this.items[this.activeItemIndex];
                            const hasData = it && ((it.name && String(it.name).trim().length > 0) || it.item_id || (parseFloat(it.unit_price) > 0) || (parseInt(it.quantity, 10) > 1));
                            if (hasData && !confirm('Remove this item from cart?')) return;
                            this.removeItem(this.activeItemIndex);
                            return;
                        }
                    }

                    // '?' help shortcut
                    if (e.key === '?') { e.preventDefault(); this.showHelpModal = true; return; }

                    // Function keys (single source — no @keydown.window.f* duplicates)
                    if (e.key === 'F2')  { e.preventDefault(); this.focusFirstSearch(); return; }
                    if (e.key === 'F4')  { e.preventDefault(); this.openParkedOrders(); return; }
                    if (e.key === 'F6')  { e.preventDefault(); this.focusCart(); return; }
                    if (e.key === 'F7')  { e.preventDefault(); this.repeatLastOrder(); return; }
                    if (e.key === 'F8')  { e.preventDefault(); this.focusPayment(); return; }
                    if (e.key === 'F9')  { e.preventDefault(); this.parkOrder(); return; }
                    if (e.key === 'F10') { e.preventDefault(); this.newSale(); return; }
                },
                focusFirstSearch() {
                    this.$nextTick(() => {
                        const inputs = document.querySelectorAll('input[x-ref^="ddInput"], [x-ref="ddInput0"]');
                        const firstSearch = document.querySelector('.relative input[type="text"]');
                        const target = (this.$refs.ddInput0) || firstSearch;
                        if (target) { target.focus(); target.select && target.select(); }
                    });
                },
                focusCart() {
                    this.$nextTick(() => {
                        const cart = document.querySelector('[x-ref="cartSection"]') || document.querySelector('table');
                        if (cart) cart.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                },
                focusPayment() {
                    this.$nextTick(() => {
                        const pay = document.querySelector('[x-ref="paymentSection"]') || document.querySelector('select');
                        if (pay) {
                            pay.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            setTimeout(() => pay.focus && pay.focus(), 300);
                        }
                    });
                },
                openParkedOrders() {
                    if (typeof this.openDraftsModal === 'function') { this.openDraftsModal(); return; }
                    window.location.href = "{{ route('pos.invoice.create') }}?drafts=1";
                },
                parkOrder() {
                    if (typeof this.saveDraftNow === 'function') { this.saveDraftNow(); return; }
                    if (typeof this.saveToLocalStorage === 'function') {
                        this.saveToLocalStorage();
                        this.showToast('🅿️ Order parked locally', 'success');
                        this.playSound('success');
                    }
                },
                newSale() {
                    const cartHasItems = this.items.some(i => i.name && i.name.trim());
                    if (cartHasItems) {
                        if (!confirm('Start a new sale? Current cart will be cleared.')) return;
                    }
                    window.location.href = "{{ route('pos.invoice.create') }}";
                },
                async repeatLastOrder() {
                    try {
                        const res = await fetch("{{ route('pos.api.last-order') }}", {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const data = await res.json();
                        if (!data.success) {
                            this.showToast(data.message || 'No previous order', 'error');
                            this.playSound('error');
                            return;
                        }
                        if (data.customer_name) this.customerName = data.customer_name;
                        if (data.customer_phone) this.customerPhone = data.customer_phone;
                        if (data.payment_method) this.paymentMethod = data.payment_method;
                        if (data.items && data.items.length) {
                            this.items = data.items.map(it => ({
                                type: it.type || 'product',
                                item_id: it.item_id || '',
                                name: it.name || '',
                                quantity: Math.max(1, parseInt(it.quantity, 10) || 1),
                                unit_price: parseFloat(it.unit_price) || 0,
                                is_tax_exempt: !!it.is_tax_exempt,
                                _isNew: false,
                            }));
                            if (this.ddSearch) {
                                this.ddSearch = {};
                                this.items.forEach((it, idx) => { this.ddSearch[idx] = it.name; });
                            }
                            this.recalculate();
                            this.showToast('🔁 Last order loaded (' + data.items.length + ' items)', 'success');
                            this.playSound('success');
                        }
                    } catch (err) {
                        this.showToast('Failed to load last order', 'error');
                        this.playSound('error');
                    }
                },

                forceExit() {
                    this.submitting = true;
                    const url = this.pendingExitUrl || "{{ url('/pos') }}";
                    this.pendingExitUrl = null;
                    window.location.href = url;
                },
                parkAndExit() {
                    this.parkOrder();
                    setTimeout(() => this.forceExit(), 400);
                },

                hasRecipe(p) {
                    return !!(p && (p.has_recipe || p.recipe_id || (p.recipes && p.recipes.length)));
                },
                stockBadge(p) {
                    if (!p) return null;
                    const s = p.stock_quantity ?? p.stock ?? p.current_stock;
                    if (s === undefined || s === null || s === '') return null;
                    const n = parseFloat(s);
                    if (isNaN(n)) return null;
                    if (n <= 0) return { label: 'OUT', cls: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' };
                    if (n < 5) return { label: n + ' LOW', cls: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' };
                    if (n < 20) return { label: String(n), cls: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' };
                    return { label: String(n), cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' };
                },
                // ============ END ENTERPRISE EXTENSIONS ============

                loadServerDraft(draft) {
                    this.draftId = draft.id;
                    this.customerName = draft.customer_name || '';
                    this.customerPhone = draft.customer_phone || '';
                    this.terminalId = draft.terminal_id || '';
                    this.discountType = draft.discount_type || 'percentage';
                    this.discountValue = parseFloat(draft.discount_value) || 0;
                    this.paymentMethod = draft.payment_method || 'cash';

                    if (draft.items && draft.items.length > 0) {
                        this.items = draft.items.map(item => {
                            let isExempt = !!item.is_tax_exempt;
                            if (item.item_id && item.item_type === 'product') {
                                const p = this.products.find(pr => pr.id == item.item_id);
                                if (p) isExempt = !!p.is_tax_exempt;
                            } else if (item.item_id && item.item_type === 'service') {
                                const s = this.services.find(sv => sv.id == item.item_id);
                                if (s) isExempt = !!s.is_tax_exempt;
                            }
                            return {
                                type: item.item_type || 'product',
                                item_id: item.item_id || '',
                                name: item.item_name || '',
                                quantity: Math.max(1, parseInt(item.quantity, 10) || 1),
                                unit_price: parseFloat(item.unit_price) || 0,
                                is_tax_exempt: isExempt,
                                _isNew: false
                            };
                        });
                    }
                },

                checkLocalDraft() {
                    try {
                        const saved = localStorage.getItem('pos_draft_invoice');
                        if (saved) {
                            const data = JSON.parse(saved);
                            const hasItems = data && data.items && data.items.length > 0 && data.items.some(i => i.name);
                            const hasCustomer = data && (data.customer_name || data.customer_phone);
                            const hasDraftId = data && data.draft_id;
                            if (hasItems || hasCustomer || hasDraftId) {
                                this.recoveredDraft = data;
                                this.showDraftRecovery = true;
                            }
                        }
                    } catch (e) {}
                },

                restoreDraft() {
                    if (!this.recoveredDraft) return;
                    const d = this.recoveredDraft;
                    this.customerName = d.customer_name || '';
                    this.customerPhone = d.customer_phone || '';
                    this.terminalId = d.terminal_id || '';
                    this.discountType = d.discount_type || 'percentage';
                    this.discountValue = d.discount_value || 0;
                    this.paymentMethod = d.payment_method || 'cash';
                    this.draftId = d.draft_id || null;

                    if (d.items && d.items.length > 0) {
                        this.items = d.items;
                    }

                    this.showDraftRecovery = false;
                    this.recoveredDraft = null;
                    this.fetchTaxRate(this.paymentMethod);
                    this.recalculate();
                },

                discardDraft() {
                    this.showDraftRecovery = false;
                    this.recoveredDraft = null;
                    localStorage.removeItem('pos_draft_invoice');
                },

                saveToLocalStorage() {
                    if (this.submitting) return;
                    try {
                        const data = {
                            customer_name: this.customerName,
                            customer_phone: this.customerPhone,
                            terminal_id: this.terminalId,
                            items: this.items,
                            discount_type: this.discountType,
                            discount_value: this.discountValue,
                            payment_method: this.paymentMethod,
                            draft_id: this.draftId,
                            saved_at: new Date().toISOString()
                        };
                        localStorage.setItem('pos_draft_invoice', JSON.stringify(data));
                    } catch (e) {}
                },

                startAutoSave() {
                    this.autoSaveTimer = setInterval(() => {
                        this.autoSaveDraft();
                    }, 10000);
                },

                async autoSaveDraft() {
                    if (this.submitting) return;
                    const hasContent = this.items.some(i => i.name && i.name.trim() !== '');
                    if (!hasContent) return;

                    this.saveToLocalStorage();

                    this.recalculate();

                    try {
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ||
                                           document.querySelector('input[name="_token"]')?.value;

                        const response = await fetch('/pos/api/draft/save', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                draft_id: this.draftId,
                                terminal_id: this.terminalId,
                                draft_data: {
                                    customer_name: this.customerName,
                                    customer_phone: this.customerPhone,
                                    terminal_id: this.terminalId || null,
                                    items: this.items,
                                    discount_type: this.discountType,
                                    discount_value: this.discountValue,
                                    discount_amount: this.discountAmount,
                                    payment_method: this.paymentMethod,
                                    subtotal: this.subtotal,
                                    tax_rate: this.taxRate,
                                    tax_amount: this.taxAmount,
                                    total_amount: this.total
                                }
                            })
                        });

                        if (response.status === 419) {
                            await this.refreshCsrfToken();
                            this.autoSaveStatus = 'Session refreshed';
                            return;
                        }

                        const result = await response.json();
                        if (result.success && result.draft_id) {
                            this.draftId = result.draft_id;
                            this.autoSaveStatus = 'Saved ' + new Date().toLocaleTimeString();
                        }
                    } catch (e) {
                        this.autoSaveStatus = 'Auto-save: offline';
                    }
                },

                addItem() {
                    let idx = this.items.length;
                    this.items.push({ type: 'product', item_id: '', name: '', quantity: 1, unit_price: 0, _isNew: false, is_tax_exempt: false });
                    this.ddSearch[idx] = '';
                    this.ddOpen[idx] = false;
                    this.ddHlIdx[idx] = -1;
                },

                removeItem(index) {
                    this.items.splice(index, 1);
                    let newSearch = {};
                    let newOpen = {};
                    let newHl = {};
                    this.items.forEach((it, i) => {
                        newSearch[i] = this.ddSearch[i >= index ? i + 1 : i] || '';
                        newOpen[i] = false;
                        newHl[i] = -1;
                    });
                    this.ddSearch = newSearch;
                    this.ddOpen = newOpen;
                    this.ddHlIdx = newHl;
                    // PHASE 1 — clamp activeItemIndex after deletion + refocus active qty for keyboard continuity
                    if (this.items.length === 0) {
                        this.activeItemIndex = 0;
                    } else {
                        if (this.activeItemIndex >= this.items.length) {
                            this.activeItemIndex = this.items.length - 1;
                        } else if (this.activeItemIndex > index) {
                            this.activeItemIndex -= 1;
                        }
                        this.cartFocusActiveQty();
                    }
                    this.recalculate();
                },

                onTypeChange(index) {
                    this.items[index].item_id = '';
                    this.items[index].name = '';
                    this.items[index].unit_price = 0;
                    this.items[index].is_tax_exempt = false;
                    this.items[index]._isNew = false;
                    this.ddSearch[index] = '';
                    this.ddOpen[index] = false;
                    this.ddHlIdx[index] = -1;
                    this.recalculate();
                },

                selectPaymentMethod(method) {
                    this.paymentMethod = method;
                    this.fetchTaxRate(method);
                },

                fetchTaxRate(method) {
                    if (this.taxRules && this.taxRules[method]) {
                        this.taxRate = parseFloat(this.taxRules[method].tax_rate || 0);
                        this.recalculate();
                        return;
                    }

                    fetch('/pos/api/tax-rate?payment_method=' + method, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(r => r.json())
                    .then(data => {
                        this.taxRate = parseFloat(data.tax_rate || 0);
                        this.recalculate();
                    })
                    .catch(() => {
                        this.taxRate = 0;
                        this.recalculate();
                    });
                },

                recalculate() {
                    this.subtotal = 0;
                    let taxableSub = 0;
                    let exemptSub = 0;
                    this.items.forEach(item => {
                        let lineTotal = (parseInt(item.quantity, 10) || 0) * (parseFloat(item.unit_price) || 0);
                        this.subtotal += lineTotal;
                        if (item.is_tax_exempt) {
                            exemptSub += lineTotal;
                        } else {
                            taxableSub += lineTotal;
                        }
                    });
                    this.subtotal = Math.round(this.subtotal * 100) / 100;

                    if (this.discountType === 'percentage') {
                        this.discountAmount = Math.round(this.subtotal * (parseFloat(this.discountValue) || 0) / 100 * 100) / 100;
                    } else {
                        this.discountAmount = Math.min(parseFloat(this.discountValue) || 0, this.subtotal);
                    }

                    this.afterDiscount = Math.round((this.subtotal - this.discountAmount) * 100) / 100;
                    let taxableAfterDiscount = this.subtotal > 0 ? Math.round(taxableSub / this.subtotal * this.afterDiscount * 100) / 100 : 0;
                    this.exemptAmount = Math.round((this.afterDiscount - taxableAfterDiscount) * 100) / 100;
                    this.taxAmount = Math.round(taxableAfterDiscount * this.taxRate / 100 * 100) / 100;
                    this.total = Math.round((this.afterDiscount + this.taxAmount) * 100) / 100;
                },

                async refreshCsrfToken() {
                    try {
                        const resp = await fetch('/pos/csrf-token', {
                            method: 'GET',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin'
                        });
                        if (resp.ok) {
                            const data = await resp.json();
                            if (data.token) {
                                document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', data.token);
                                const hiddenInput = document.querySelector('input[name="_token"]');
                                if (hiddenInput) hiddenInput.value = data.token;
                            }
                        }
                    } catch (e) {}
                },

                async submitForm(event) {
                    if (this.items.length === 0) return;
                    this.submitting = true;

                    if (this.autoSaveTimer) {
                        clearInterval(this.autoSaveTimer);
                    }

                    localStorage.removeItem('pos_draft_invoice');

                    await this.refreshCsrfToken();

                    this.$nextTick(() => {
                        event.target.submit();
                    });
                }
            };
        }
    </script>
</x-pos-layout>
