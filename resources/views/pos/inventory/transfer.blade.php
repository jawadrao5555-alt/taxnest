<x-pos-layout>
{{--
    Branch stock transfer (Task 1354).
    Moves goods from one shop to another; the ledger keeps a paired
    TRANSFER_OUT / TRANSFER_IN so both branches tell the same story.
--}}
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.branch_transfer') }}</h1>
        <a href="{{ route('pos.inventory.stock') }}" class="inline-flex items-center text-gray-500 hover:text-purple-600 transition text-sm">
            <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            {{ __('pos.back_to_stock') }}
        </a>
    </div>

    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('pos.inventory.dashboard') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.dashboard') }}</a>
        <a href="{{ route('pos.inventory.stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.stock_levels') }}</a>
        <a href="{{ route('pos.inventory.movements') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.movements') }}</a>
        <a href="{{ route('pos.inventory.low-stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.low_stock_alerts') }}</a>
        <a href="{{ route('pos.inventory.adjust') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.adjust_stock') }}</a>
        <a href="{{ route('pos.inventory.transfers') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-purple-600 text-white shadow-sm">{{ __('pos.branch_transfer') }}</a>
    </div>

    @if(session('error'))
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-3 text-sm text-red-800 dark:text-red-300">{{ session('error') }}</div>
    @endif

    @if($errors->any())
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-3">
        <ul class="text-sm text-red-800 dark:text-red-300 list-disc pl-4">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @php
        // Baked so the picker can show "kitna maal is branch mein hai" the
        // moment a product + source branch are chosen, without a round trip.
        $stockJson = json_encode($stockMap, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($stockJson === false) { $stockJson = '{}'; }
    @endphp

    <div
        x-data="{
            product: '{{ old('product_id') }}',
            from: '{{ old('from_branch_id', $activeBranchId ?? '') }}',
            to: '{{ old('to_branch_id') }}',
            qty: '{{ old('quantity') }}',
            stock: {!! $stockJson !!},
            available(branchId) {
                if (!branchId || !this.product) return null;
                const b = this.stock[branchId];
                if (!b) return 0;
                const v = b[this.product];
                return v === undefined ? 0 : v;
            },
            get fromAvailable() { return this.available(this.from); },
            get toAvailable() { return this.available(this.to); },
            get tooMuch() {
                const a = this.fromAvailable;
                return a !== null && this.qty !== '' && parseFloat(this.qty) > a;
            }
        }"
        class="grid grid-cols-1 lg:grid-cols-5 gap-6">

        <form method="POST" action="{{ route('pos.inventory.transfer.store') }}" class="lg:col-span-2 space-y-4">
            @csrf
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.product_col') }}</label>
                    <select name="product_id" x-model="product" required class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition">
                        <option value="">{{ __('pos.select_product') }}</option>
                        @foreach($products as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}{{ $p->sku ? ' (' . $p->sku . ')' : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.transfer_from') }}</label>
                    <select name="from_branch_id" x-model="from" required class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition">
                        <option value="">{{ __('pos.branch_select') }}</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[11px] font-semibold text-gray-500 dark:text-gray-400" x-show="fromAvailable !== null" x-cloak>
                        {{ __('pos.transfer_available_label') }}: <span class="text-gray-900 dark:text-white" x-text="fromAvailable"></span>
                    </p>
                </div>

                <div class="flex justify-center">
                    <svg class="w-5 h-5 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.transfer_to') }}</label>
                    <select name="to_branch_id" x-model="to" required class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition">
                        <option value="">{{ __('pos.branch_select') }}</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[11px] font-semibold text-gray-500 dark:text-gray-400" x-show="toAvailable !== null" x-cloak>
                        {{ __('pos.transfer_available_label') }}: <span class="text-gray-900 dark:text-white" x-text="toAvailable"></span>
                    </p>
                    <p class="mt-1 text-[11px] font-semibold text-red-600" x-show="from !== '' && to !== '' && from === to" x-cloak>{{ __('pos.transfer_same_branch') }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.quantity_label') }}</label>
                    <input type="number" name="quantity" x-model="qty" step="0.01" min="0.01" required
                        autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                        class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition">
                    <p class="mt-1 text-[11px] font-semibold text-red-600" x-show="tooMuch" x-cloak>{{ __('pos.transfer_over_available') }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.notes_col') }}</label>
                    <input type="text" name="notes" maxlength="500" value="{{ old('notes') }}"
                        autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                        class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2.5 focus:ring-2 focus:ring-purple-500 transition">
                </div>

                <button type="submit" :disabled="tooMuch || (from !== '' && from === to)"
                    class="w-full px-4 py-3 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-bold rounded-xl transition shadow-sm">
                    {{ __('pos.transfer_submit') }}
                </button>
            </div>
        </form>

        <div class="lg:col-span-3 space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-1">{{ __('pos.transfer_recent') }}</h3>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.transfer_recent_hint') }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700">
                                <th class="py-2.5 font-semibold">{{ __('pos.receipt_date') }}</th>
                                <th class="py-2.5 font-semibold">{{ __('pos.product_col') }}</th>
                                <th class="py-2.5 font-semibold">{{ __('pos.transfer_from') }}</th>
                                <th class="py-2.5 text-right font-semibold">{{ __('pos.receipt_qty') }}</th>
                                <th class="py-2.5 font-semibold hidden sm:table-cell">{{ __('pos.by_col') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                            @forelse($recent as $m)
                            <tr>
                                <td class="py-3 text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $m->created_at->format('d M Y H:i') }}</td>
                                <td class="py-3 font-semibold text-gray-900 dark:text-white">{{ $m->posProduct->name ?? __('pos.unknown_word') }}</td>
                                <td class="py-3 text-xs text-gray-600 dark:text-gray-400">
                                    {{ $branchNames[$m->branch_id] ?? '—' }}
                                    <span class="mx-1 text-purple-400">&rarr;</span>
                                    {{ $branchNames[$m->reference_id] ?? '—' }}
                                </td>
                                <td class="py-3 text-right font-bold text-gray-900 dark:text-white">{{ number_format($m->quantity, 0) }}</td>
                                <td class="py-3 text-xs text-gray-600 dark:text-gray-400 hidden sm:table-cell">{{ $m->creator->name ?? '—' }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="py-12 text-center">
                                    <p class="text-sm text-gray-400">{{ __('pos.transfer_none_yet') }}</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</x-pos-layout>
