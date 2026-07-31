<x-fbr-pos-layout>
<div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-1">{{ __('pos.shifts_cash_drawer') }}</h1>
    <p class="text-sm text-gray-500 mb-6">{{ __('pos.shifts_cash_drawer_desc') }}</p>

    @if(session('success'))<div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>@endif

    @if($current)
        <div class="bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-700 rounded-xl p-5 mb-5">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <div class="text-xs text-emerald-700 font-semibold">{{ __('pos.shift_hash_open', ['id' => $current->id]) }}</div>
                    <div class="text-lg font-bold dark:text-white">{{ __('pos.opened_colon') }} {{ $current->opened_at->format('d M Y, h:i A') }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-300">{{ __('pos.opening_cash_colon') }} Rs {{ number_format($current->opening_cash, 2) }}</div>
                </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-4 mt-4">
                <form method="POST" action="{{ route('fbrpos.phase2.shift.cash') }}" class="bg-white dark:bg-gray-800 p-4 rounded-lg space-y-2">
                    @csrf
                    <h3 class="font-bold text-sm dark:text-white">{{ __('pos.cash_movement') }}</h3>
                    <select name="type" required class="w-full border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white">
                        <option value="cash_in">{{ __('pos.cash_in_extra') }}</option>
                        <option value="cash_out">{{ __('pos.cash_out_expense') }}</option>
                        <option value="drop">{{ __('pos.cash_drop') }}</option>
                        <option value="float">{{ __('pos.float_top_up') }}</option>
                    </select>
                    <input type="number" step="0.01" min="0.01" name="amount" required placeholder="{{ __('pos.ph_amount_rs') }}" class="w-full border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white">
                    <input type="text" name="reason" required placeholder="{{ __('pos.ph_reason') }}" class="w-full border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white">
                    <button class="bg-blue-600 text-white rounded px-3 py-1.5 text-sm font-semibold w-full">{{ __('pos.record_btn') }}</button>
                </form>

                <form method="POST" action="{{ route('fbrpos.phase2.shift.close') }}" class="bg-white dark:bg-gray-800 p-4 rounded-lg space-y-2">
                    @csrf
                    <h3 class="font-bold text-sm dark:text-white">{{ __('pos.close_shift_z_report') }}</h3>
                    <input type="number" step="0.01" min="0" name="closing_cash" required placeholder="{{ __('pos.ph_counted_cash_in_drawer') }}" class="w-full border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white">
                    <textarea name="notes" placeholder="{{ __('pos.ph_notes_optional') }}" rows="2" class="w-full border rounded px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-white"></textarea>
                    <button onclick="return confirm(@js(__('pos.close_current_shift_q')))" class="bg-red-600 text-white rounded px-3 py-1.5 text-sm font-semibold w-full">{{ __('pos.close_shift') }}</button>
                </form>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('fbrpos.phase2.shift.open') }}" class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 mb-5">
            @csrf
            <h2 class="font-bold mb-3 dark:text-white">{{ __('pos.open_new_shift') }}</h2>
            <div class="grid sm:grid-cols-2 gap-3">
                <input type="number" step="0.01" min="0" name="opening_cash" required placeholder="{{ __('pos.ph_opening_cash_rs') }}" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                <button class="bg-emerald-600 text-white rounded-lg px-5 py-2 text-sm font-semibold hover:bg-emerald-700">{{ __('pos.open_shift') }}</button>
            </div>
        </form>
    @endif

    <h2 class="text-lg font-bold dark:text-white mt-6 mb-3">{{ __('pos.shift_history') }}</h2>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm table-cards">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr><th class="px-4 py-3">#</th><th>{{ __('pos.th_opened') }}</th><th>{{ __('pos.th_closed') }}</th><th>{{ __('pos.th_sales') }}</th><th>{{ __('pos.th_cash') }}</th><th>{{ __('pos.th_variance') }}</th><th>{{ __('pos.th_status') }}</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($shifts as $s)
                <tr class="border-t dark:border-gray-700">
                    <td class="px-4 py-3 font-mono dark:text-gray-300">#{{ $s->id }}</td>
                    <td class="dark:text-gray-300">{{ $s->opened_at?->format('d M h:i A') }}</td>
                    <td class="dark:text-gray-300">{{ $s->closed_at?->format('d M h:i A') ?? '—' }}</td>
                    <td class="dark:text-gray-300">Rs {{ number_format($s->total_sales, 0) }}</td>
                    <td class="dark:text-gray-300">Rs {{ number_format($s->total_cash, 0) }}</td>
                    <td class="{{ $s->variance == 0 ? 'text-gray-600' : ($s->variance > 0 ? 'text-emerald-600' : 'text-red-600') }} font-semibold">{{ $s->status === 'closed' ? 'Rs '.number_format($s->variance, 2) : '—' }}</td>
                    <td>@if($s->status==='open')<span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded text-xs">{{ __('pos.open_word') }}</span>@else<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs">{{ __('pos.closed_word') }}</span>@endif</td>
                    <td class="text-right pr-4"><a href="{{ route('fbrpos.phase2.shift.report', $s->id) }}" class="text-blue-600 text-sm hover:underline">{{ __('pos.report_word') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">{{ __('pos.no_shifts_yet') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        @if(method_exists($shifts, 'links')) <div class="p-4">{{ $shifts->links() }}</div> @endif
    </div>
</div>
</x-fbr-pos-layout>
