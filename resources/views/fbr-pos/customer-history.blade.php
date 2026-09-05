<x-fbr-pos-layout>
{{-- Task 1260: per-customer purchase history (FBR bills only) — port of
     resources/views/pos/customer-history.blade.php without the PRA-only
     khamosh-repeat chip / spend-snapshot rows. Mode badge: Local vs FBR. --}}
<div class="p-4 sm:p-6 max-w-6xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <a href="{{ route('fbrpos.customers') }}" class="text-xs text-blue-600 hover:text-blue-700">← {{ __('pos.back_to_customers') }}</a>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white mt-1">{{ $customer->name }}</h1>
            <p class="text-sm text-gray-500">
                {{ $customer->phone ?: __('pos.no_phone') }}
                @if($customer->city) · {{ $customer->city }} @endif
                · <span class="capitalize">{{ $customer->type }}</span>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('fbrpos.customers.history.export', $customer->id) }}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-md transition">{{ __('pos.download_csv') }}</a>
            <a href="{{ route('fbrpos.customers.history.pdf', $customer->id) }}" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-md transition">{{ __('pos.download_pdf') }}</a>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 uppercase">{{ __('pos.total_orders') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($totalOrders) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 uppercase">{{ __('pos.total_spent') }}</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1">PKR {{ number_format($totalSpent, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 uppercase">{{ __('pos.avg_order') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">PKR {{ number_format($avgOrder, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-sm p-4">
            <p class="text-xs text-gray-500 uppercase">{{ __('pos.last_order') }}</p>
            <p class="text-sm font-bold text-gray-900 dark:text-white mt-2">{{ $lastOrder ? $lastOrder->created_at->format('d M Y') : '—' }}</p>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">{{ __('pos.receipt_date') }}</th>
                        <th class="px-4 py-3">{{ __('pos.invoice_no_col') }}</th>
                        <th class="px-4 py-3 text-center hidden sm:table-cell">{{ __('pos.mode_col') }}</th>
                        <th class="px-4 py-3 hidden md:table-cell">{{ __('pos.payment') }}</th>
                        {{-- 💊 Pharmacy Mode (Task 1558, step 8): a schedule-medicine bill
                             must be findable again months later — doctor, patient and the
                             photographed slip live on the bill, so surface them here. --}}
                        @if($pharmacyMode ?? false)
                        <th class="px-4 py-3 hidden lg:table-cell">{{ __('pos.ph_rx_col') }}</th>
                        @endif
                        <th class="px-4 py-3 text-right hidden sm:table-cell">{{ __('pos.receipt_tax') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.total_word') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($transactions as $t)
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $t->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $t->invoice_number }}</td>
                        <td class="px-4 py-3 text-center hidden sm:table-cell">
                            @php $isLocalRow = ($t->invoice_mode ?? '') === 'local'; @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $isLocalRow ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                                {{ $isLocalRow ? __('pos.local_word') : 'FBR' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500 hidden md:table-cell">{{ ucwords(str_replace('_', ' ', (string) $t->payment_method)) }}</td>
                        @if($pharmacyMode ?? false)
                        <td class="px-4 py-3 hidden lg:table-cell text-xs">
                            @if(!empty($t->doctor_name) || !empty($t->patient_name) || !empty($t->prescription_image))
                                @if(!empty($t->patient_name))
                                    <span class="block text-gray-700 dark:text-gray-300">{{ $t->patient_name }}</span>
                                @endif
                                @if(!empty($t->doctor_name))
                                    <span class="block text-gray-500">{{ __('pos.ph_dr_prefix') }} {{ $t->doctor_name }}</span>
                                @endif
                                @if(!empty($t->prescription_image))
                                    <a href="{{ asset('storage/' . $t->prescription_image) }}" target="_blank" rel="noopener"
                                       class="inline-block mt-0.5 font-bold text-blue-600 hover:underline">{{ __('pos.ph_view_slip') }}</a>
                                @endif
                            @else
                                <span class="text-gray-300 dark:text-gray-600">—</span>
                            @endif
                        </td>
                        @endif
                        <td class="px-4 py-3 text-right text-gray-500 hidden sm:table-cell">{{ number_format($t->tax_amount, 0) }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-white">PKR {{ number_format($t->total_amount, 0) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="{{ ($pharmacyMode ?? false) ? 7 : 6 }}" class="px-4 py-12 text-center text-gray-500">{{ __('pos.no_purchase_history') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 text-xs text-gray-400 text-center">
        {{ __('pos.history_match_note') }}
    </div>
</div>
</x-fbr-pos-layout>
