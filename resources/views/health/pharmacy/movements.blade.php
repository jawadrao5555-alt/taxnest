@php use App\Models\HealthBatchMovement; @endphp
{{--
    Traceability ledger.

    One line per movement, never overwritten. This is the screen a pharmacist
    or an inspector opens to answer "where did those units go" — so the lot,
    the reason and the person are all on the row, not behind a click.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_movements_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ph_movements_subtitle') }}</p>
            </div>
            <a href="{{ route('health.pharmacy.stock') }}"
               class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                {{ __('health.ph_quick_stock') }}
            </a>
        </div>

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="medicine_id" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">{{ __('health.ph_all_medicines') }}</option>
                @foreach($medicines as $medicine)
                    <option value="{{ $medicine->id }}" @selected((string) $selectedMedicine === (string) $medicine->id)>
                        {{ trim($medicine->name . ' ' . ($medicine->strength ?? '')) }}
                    </option>
                @endforeach
            </select>
            <select name="type" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">{{ __('health.ph_all_types') }}</option>
                @foreach($types as $type)
                    <option value="{{ $type }}" @selected($selectedType === $type)>{{ __(HealthBatchMovement::typeLabelKey($type)) }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                {{ __('health.ph_apply') }}
            </button>
        </form>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($movements->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.ph_movements_none') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 text-start font-black">{{ __('health.ph_when') }}</th>
                                <th class="px-4 py-2 text-start font-black">{{ __('health.ph_medicine') }}</th>
                                <th class="px-4 py-2 text-start font-black">{{ __('health.ph_batch_no') }}</th>
                                <th class="px-4 py-2 text-start font-black">{{ __('health.ph_movement_type') }}</th>
                                <th class="px-4 py-2 text-end font-black">{{ __('health.ph_qty') }}</th>
                                <th class="px-4 py-2 text-end font-black">{{ __('health.ph_balance_after') }}</th>
                                <th class="px-4 py-2 text-start font-black">{{ __('health.ph_reference') }}</th>
                                <th class="px-4 py-2 text-start font-black">{{ __('health.ph_by') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($movements as $movement)
                                @php $isIn = $movement->direction === HealthBatchMovement::DIRECTION_IN; @endphp
                                <tr>
                                    <td class="px-4 py-2.5 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $movement->created_at?->format('d-m-Y H:i') }}</td>
                                    <td class="px-4 py-2.5 font-bold">{{ $movement->medicine?->display_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs">
                                        {{ $movement->batch?->batch_no ?: __('health.ph_no_batch') }}
                                        @if($movement->batch?->expiry_date)
                                            <span class="text-gray-500 dark:text-gray-400">&middot; {{ $movement->batch->expiry_date->format('m/Y') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span class="text-[11px] font-black px-2 py-0.5 rounded-full
                                                     {{ $isIn ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200' : 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200' }}">
                                            {{ __(HealthBatchMovement::typeLabelKey($movement->type)) }}
                                        </span>
                                        @if($movement->reason)
                                            <span class="ms-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.reason_' . $movement->reason) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-end font-black {{ $isIn ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' }}">
                                        {{ $isIn ? '+' : '−' }}{{ rtrim(rtrim(number_format((float) $movement->quantity, 3, '.', ''), '0'), '.') }}
                                    </td>
                                    <td class="px-4 py-2.5 text-end">{{ rtrim(rtrim(number_format((float) $movement->balance_after, 3, '.', ''), '0'), '.') }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400">{{ $movement->reference_number ?: '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs">{{ $movement->creator?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div>{{ $movements->links() }}</div>
    </div>
</x-health-layout>
