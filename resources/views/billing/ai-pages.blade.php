<x-app-layout>
    <div class="py-6">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-4">
                <a href="{{ route('billing.plans') }}" class="hover:text-emerald-600 dark:hover:text-emerald-400">Billing</a>
                <span>/</span>
                <span class="text-gray-700 dark:text-gray-300">AI Reader Pages</span>
            </div>

            {{-- Balance first: the question this page answers is "how many are
                 left and where did the rest go?" --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-400">Available now</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1">
                        {{ $summary['total_remaining'] === -1 ? 'Unlimited' : number_format($summary['total_remaining']) }}
                    </p>
                    <p class="text-xs text-gray-400 mt-0.5">pages</p>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-400">This month's allowance</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1">
                        @if($summary['unlimited'])
                            Unlimited
                        @else
                            {{ number_format($summary['allowance_used']) }} <span class="text-base font-medium text-gray-400">/ {{ number_format($summary['allowance']) }}</span>
                        @endif
                    </p>
                    <p class="text-xs text-gray-400 mt-0.5">used &middot; resets {{ $summary['resets_on'] }}</p>
                </div>
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-400">Purchased balance</p>
                    <p class="text-2xl font-bold text-fuchsia-600 dark:text-fuchsia-400 mt-1">{{ number_format($summary['purchased']) }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">never expires</p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100">Page history</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Monthly allowance is spent first; purchased pages are used only after it runs out.</p>
                    </div>
                    <a href="{{ route('billing.plans') }}#ai-pages" class="text-sm font-semibold text-fuchsia-600 dark:text-fuchsia-400 hover:underline">Buy more pages</a>
                </div>

                @if($entries->count() === 0)
                    <div class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                        No AI Reader pages have been used yet.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="px-5 py-3 text-left font-medium">When</th>
                                    <th class="px-5 py-3 text-left font-medium">What</th>
                                    <th class="px-5 py-3 text-left font-medium">By</th>
                                    <th class="px-5 py-3 text-right font-medium">Allowance</th>
                                    <th class="px-5 py-3 text-right font-medium">Purchased</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($entries as $entry)
                                    @php
                                        // Consume rows are the only ones that take pages away;
                                        // everything else puts them back or adds to the balance.
                                        $isSpend = $entry->kind === \App\Models\AiPageLedger::KIND_CONSUME;
                                        $label = match ($entry->kind) {
                                            \App\Models\AiPageLedger::KIND_CONSUME     => 'Used',
                                            \App\Models\AiPageLedger::KIND_REFUND      => 'Refunded',
                                            \App\Models\AiPageLedger::KIND_TOPUP       => 'Top-up purchased',
                                            \App\Models\AiPageLedger::KIND_ADMIN_GRANT => 'Added by support',
                                            default                                    => ucfirst(str_replace('_', ' ', (string) $entry->kind)),
                                        };
                                        $sign = $isSpend ? '-' : '+';
                                        $tone = $isSpend ? 'text-gray-700 dark:text-gray-300' : 'text-emerald-600 dark:text-emerald-400';
                                    @endphp
                                    <tr>
                                        <td class="px-5 py-3 whitespace-nowrap text-gray-600 dark:text-gray-400">
                                            {{ $entry->created_at?->format('d M Y, g:i a') ?? '—' }}
                                        </td>
                                        <td class="px-5 py-3">
                                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ $label }}</span>
                                            @if($entry->source)
                                                <span class="text-xs text-gray-400 block">{{ str_replace('_', ' ', $entry->source) }}</span>
                                            @endif
                                            @if($entry->note)
                                                <span class="text-xs text-gray-500 dark:text-gray-400 block">{{ $entry->note }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-gray-600 dark:text-gray-400">
                                            {{ $entry->user_id ? ($userNames[$entry->user_id] ?? 'User #' . $entry->user_id) : 'System' }}
                                        </td>
                                        <td class="px-5 py-3 text-right {{ $tone }}">
                                            {{ $entry->from_allowance ? $sign . number_format($entry->from_allowance) : '—' }}
                                        </td>
                                        <td class="px-5 py-3 text-right {{ $tone }}">
                                            {{ $entry->from_balance ? $sign . number_format($entry->from_balance) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($entries->hasPages())
                        <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800">
                            {{ $entries->links() }}
                        </div>
                    @endif
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
