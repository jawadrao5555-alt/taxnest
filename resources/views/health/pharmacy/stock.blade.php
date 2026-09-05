@php use App\Models\HealthMedicineBatch; @endphp
{{--
    Batch stock control.

    Every row is one lot, not one medicine — that is the whole point. A recall,
    an expiry sweep or a counted correction all happen at lot level, so the
    actions live on the row rather than in a separate screen.

    Quarantine is status-only: the goods are still physically on the shelf, so
    the quantity does not move. Only a write-off deducts.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{
            action: null, batch: null, opening: false,
            open(kind, payload) { this.action = kind; this.batch = payload; },
            close() { this.action = null; this.batch = null; }
         }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_stock_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ph_stock_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('health.pharmacy.movements') }}"
                   class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    {{ __('health.ph_quick_movements') }}
                </a>
                @if($canManage)
                    <button type="button" @click="opening = !opening"
                            class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.ph_opening_stock') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- Drift: batch remainders vs the branch stock truth. Silence here is
             the healthy state; a row means the two disagree and somebody must
             look. Never auto-corrected — a silent fix hides the cause. --}}
        @if(!empty($drift))
            <div class="rounded-2xl border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4">
                <p class="text-sm font-black text-red-800 dark:text-red-200">{{ __('health.ph_drift_title') }}</p>
                <p class="text-xs text-red-700 dark:text-red-300 mt-0.5">{{ __('health.ph_drift_help') }}</p>
                <ul class="mt-2 space-y-1">
                    @foreach($drift as $row)
                        <li class="text-xs text-red-800 dark:text-red-200">
                            {{ $row['medicine'] }} — {{ __('health.ph_drift_batches') }}: {{ rtrim(rtrim(number_format($row['batch_quantity'], 3, '.', ''), '0'), '.') }},
                            {{ __('health.ph_drift_branch') }}: {{ rtrim(rtrim(number_format($row['branch_quantity'], 3, '.', ''), '0'), '.') }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── Opening stock ── --}}
        @if($canManage)
            <div x-show="opening" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.pharmacy.stock.opening') }}" class="space-y-4">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.ph_opening_stock') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.ph_opening_help') }}</p>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-3">
                        <div class="lg:col-span-2">
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_medicine') }}</label>
                            <select name="medicine_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($medicines as $medicine)
                                    <option value="{{ $medicine->id }}">{{ trim($medicine->name . ' ' . ($medicine->strength ?? '')) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($isMultiBranch)
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_branch') }}</label>
                                <select name="branch_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    <option value="">—</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected($viewBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_batch_no') }}</label>
                            <input type="text" name="batch_no" maxlength="64" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_expiry') }}</label>
                            <input type="date" name="expiry_date" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_qty') }}</label>
                            <input type="number" step="0.001" min="0.001" name="quantity" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_cost') }}</label>
                            <input type="number" step="0.01" min="0" name="cost_price" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_f_sale_price') }}</label>
                            <input type="number" step="0.01" min="0" name="sale_price" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.ph_save') }}</button>
                        <button type="button" @click="opening = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── Filters ── --}}
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('health.ph_search_batch') }}"
                   class="flex-1 min-w-[180px] rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            <select name="filter" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                @foreach(['all', 'near_expiry', 'expired', 'quarantined', 'written_off', 'empty'] as $option)
                    <option value="{{ $option }}" @selected($filter === $option)>{{ __('health.ph_filter_' . $option) }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                {{ __('health.ph_apply') }}
            </button>
        </form>

        {{-- ── Batches ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($batches->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.ph_stock_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($batches as $batch)
                        @php
                            $expired = $batch->isExpired();
                            $short = !$expired && $batch->isShortDated((int) $settings->near_expiry_days);
                            $payload = \Illuminate\Support\Js::from([
                                'id' => (int) $batch->id,
                                'label' => mb_convert_encoding(trim(($batch->medicine?->display_name ?? '—') . ' · ' . ($batch->batch_no ?: __('health.ph_no_batch'))), 'UTF-8', 'UTF-8'),
                                'quantity' => (float) $batch->quantity,
                            ]);
                        @endphp
                        <div class="px-5 py-4 flex flex-wrap items-center gap-3">
                            <div class="flex-1 min-w-[220px]">
                                <p class="text-sm font-black">
                                    {{ $batch->medicine?->display_name ?? '—' }}
                                    @if($batch->status === HealthMedicineBatch::STATUS_QUARANTINED)
                                        <span class="ms-1.5 text-[10px] font-black px-1.5 py-0.5 rounded bg-purple-100 dark:bg-purple-900/40 text-purple-800 dark:text-purple-200 uppercase">{{ __('health.ph_status_quarantined') }}</span>
                                    @elseif($batch->status === HealthMedicineBatch::STATUS_WRITTEN_OFF)
                                        <span class="ms-1.5 text-[10px] font-black px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 uppercase">{{ __('health.ph_status_written_off') }}</span>
                                    @endif
                                    @if($expired)
                                        <span class="ms-1 text-[10px] font-black px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 uppercase">{{ __('health.ph_badge_expired') }}</span>
                                    @elseif($short)
                                        <span class="ms-1 text-[10px] font-black px-1.5 py-0.5 rounded bg-orange-100 dark:bg-orange-900/40 text-orange-800 dark:text-orange-200 uppercase">{{ __('health.ph_badge_short_dated') }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ __('health.ph_batch_no') }}: {{ $batch->batch_no ?: __('health.ph_no_batch') }}
                                    &middot; {{ __('health.ph_expiry') }}: {{ $batch->expiry_date?->format('d-m-Y') ?? __('health.ph_no_expiry') }}
                                    @if($isMultiBranch) &middot; {{ $batch->branch?->name ?? '—' }} @endif
                                    @if($batch->supplier) &middot; {{ $batch->supplier->name }} @endif
                                </p>
                            </div>

                            <div class="text-end">
                                <p class="text-sm font-black">{{ rtrim(rtrim(number_format((float) $batch->quantity, 3, '.', ''), '0'), '.') }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.ph_available') }}</p>
                            </div>
                            <div class="text-end min-w-[80px]">
                                <p class="text-sm font-bold">{{ number_format((float) $batch->cost_price, 2) }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.ph_cost') }}</p>
                            </div>

                            @if($canManage && $batch->status !== HealthMedicineBatch::STATUS_WRITTEN_OFF)
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <button type="button" @click="open('adjust', {{ $payload }})"
                                            class="px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-600 text-[11px] font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">{{ __('health.ph_adjust') }}</button>
                                    <button type="button" @click="open('writeoff', {{ $payload }})"
                                            class="px-2.5 py-1.5 rounded-lg border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-[11px] font-bold hover:bg-red-50 dark:hover:bg-red-900/20 transition">{{ __('health.ph_write_off') }}</button>
                                    @if($batch->status === HealthMedicineBatch::STATUS_QUARANTINED)
                                        <form method="POST" action="{{ url('/health/pharmacy/stock/' . $batch->id . '/release') }}">
                                            @csrf
                                            <button type="submit" class="px-2.5 py-1.5 rounded-lg border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-[11px] font-bold hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition">{{ __('health.ph_release') }}</button>
                                        </form>
                                    @else
                                        <button type="button" @click="open('quarantine', {{ $payload }})"
                                                class="px-2.5 py-1.5 rounded-lg border border-purple-200 dark:border-purple-800 text-purple-700 dark:text-purple-300 text-[11px] font-bold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition">{{ __('health.ph_quarantine') }}</button>
                                    @endif
                                    @if($canTransfer && $isMultiBranch)
                                        <button type="button" @click="open('transfer', {{ $payload }})"
                                                class="px-2.5 py-1.5 rounded-lg border border-sky-200 dark:border-sky-800 text-sky-700 dark:text-sky-300 text-[11px] font-bold hover:bg-sky-50 dark:hover:bg-sky-900/20 transition">{{ __('health.ph_transfer') }}</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div>{{ $batches->links() }}</div>

        {{-- ── Action dialog ── --}}
        @if($canManage)
            <div x-show="action" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="close()">
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-gray-800 p-5 shadow-2xl">
                    <p class="text-sm font-black mb-1" x-text="batch ? batch.label : ''"></p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-4">
                        {{ __('health.ph_available') }}: <span x-text="batch ? batch.quantity : ''"></span>
                    </p>

                    {{-- Adjust: counted correction, in either direction. --}}
                    <form x-show="action === 'adjust'" method="POST"
                          :action="'{{ url('/health/pharmacy/stock') }}/' + (batch ? batch.id : '') + '/adjust'" class="space-y-3">
                        @csrf
                        <h3 class="text-base font-black">{{ __('health.ph_adjust') }}</h3>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_counted_qty') }}</label>
                            <input type="number" step="0.001" min="0" name="quantity" required
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.ph_counted_help') }}</p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_reason') }}</label>
                            <select name="reason" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach($reasons as $reason)
                                    <option value="{{ $reason }}">{{ __('health.reason_' . $reason) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <input type="text" name="notes" maxlength="500" placeholder="{{ __('health.ph_f_notes') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <div class="flex items-center gap-2">
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.ph_save') }}</button>
                            <button type="button" @click="close()" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                        </div>
                    </form>

                    {{-- Write-off: the only action that actually removes goods. --}}
                    <form x-show="action === 'writeoff'" method="POST"
                          :action="'{{ url('/health/pharmacy/stock') }}/' + (batch ? batch.id : '') + '/write-off'" class="space-y-3">
                        @csrf
                        <h3 class="text-base font-black">{{ __('health.ph_write_off') }}</h3>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_qty') }}</label>
                            <input type="number" step="0.001" min="0.001" name="quantity" required
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_reason') }}</label>
                            <select name="reason" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach($reasons as $reason)
                                    <option value="{{ $reason }}">{{ __('health.reason_' . $reason) }}</option>
                                @endforeach
                            </select>
                        </div>
                        {{-- No separate "this is an expiry" switch: choosing the
                             `expired` reason (or an already-expired lot) is what
                             files it as an expiry write-off, so the two can never
                             disagree. --}}
                        <input type="text" name="notes" maxlength="500" placeholder="{{ __('health.ph_f_notes') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <div class="flex items-center gap-2">
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-red-700 hover:bg-red-800 text-white text-sm font-black transition">{{ __('health.ph_write_off') }}</button>
                            <button type="button" @click="close()" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                        </div>
                    </form>

                    {{-- Quarantine: status only, quantity untouched. --}}
                    <form x-show="action === 'quarantine'" method="POST"
                          :action="'{{ url('/health/pharmacy/stock') }}/' + (batch ? batch.id : '') + '/quarantine'" class="space-y-3">
                        @csrf
                        <h3 class="text-base font-black">{{ __('health.ph_quarantine') }}</h3>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.ph_quarantine_help') }}</p>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_reason') }}</label>
                            <select name="reason" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach($reasons as $reason)
                                    <option value="{{ $reason }}">{{ __('health.reason_' . $reason) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <input type="text" name="notes" maxlength="500" placeholder="{{ __('health.ph_f_notes') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <div class="flex items-center gap-2">
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-700 hover:bg-purple-800 text-white text-sm font-black transition">{{ __('health.ph_quarantine') }}</button>
                            <button type="button" @click="close()" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                        </div>
                    </form>

                    {{-- Transfer: the lot identity travels with the goods. --}}
                    <form x-show="action === 'transfer'" method="POST"
                          :action="'{{ url('/health/pharmacy/stock') }}/' + (batch ? batch.id : '') + '/transfer'" class="space-y-3">
                        @csrf
                        <h3 class="text-base font-black">{{ __('health.ph_transfer') }}</h3>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_transfer_to') }}</label>
                            <select name="to_branch_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_qty') }}</label>
                            <input type="number" step="0.001" min="0.001" name="quantity" required
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <input type="text" name="notes" maxlength="500" placeholder="{{ __('health.ph_f_notes') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <div class="flex items-center gap-2">
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-sky-700 hover:bg-sky-800 text-white text-sm font-black transition">{{ __('health.ph_transfer') }}</button>
                            <button type="button" @click="close()" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
</x-health-layout>
