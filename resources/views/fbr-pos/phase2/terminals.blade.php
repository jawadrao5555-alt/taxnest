<x-fbr-pos-layout>
<div class="max-w-5xl mx-auto">
    @include('fbr-pos.partials.back-link')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.counters_terminals') }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ __('pos.counters_terminals_desc') }}</p>
        </div>
    </div>

    @if(session('success'))<div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>@endif

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 mb-5">
        <h2 class="font-bold mb-3 text-gray-900 dark:text-white">{{ __('pos.add_new_counter_plus') }}</h2>
        <form method="POST" action="{{ route('fbrpos.phase2.terminals.store') }}" class="grid sm:grid-cols-3 gap-3">
            @csrf
            <input type="text" name="terminal_name" placeholder="{{ __('pos.ph_eg_counter_1') }}" required class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            <input type="text" name="location" placeholder="{{ __('pos.ph_location_optional') }}" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            <button class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-blue-700">{{ __('pos.add_counter') }}</button>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm table-cards">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr>
                    <th class="px-4 py-3">{{ __('pos.th_name') }}</th><th>{{ __('pos.th_code') }}</th><th>{{ __('pos.th_location') }}</th><th>{{ __('pos.th_status') }}</th><th class="text-right pr-4">{{ __('pos.th_actions') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($terminals as $t)
                <tr class="border-t dark:border-gray-700">
                    <td class="px-4 py-3 font-semibold dark:text-white">{{ $t->terminal_name }}</td>
                    <td class="dark:text-gray-300"><code>{{ $t->terminal_code }}</code></td>
                    <td class="dark:text-gray-300">{{ $t->location ?? '—' }}</td>
                    <td>@if($t->is_active)<span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded text-xs">{{ __('pos.active_word') }}</span>@else<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs">{{ __('pos.inactive_word') }}</span>@endif</td>
                    <td class="text-right pr-4 py-3">
                        <form method="POST" action="{{ route('fbrpos.phase2.terminals.toggle', $t->id) }}" class="inline">
                            @csrf
                            <button class="text-blue-600 hover:underline text-sm">{{ $t->is_active ? __('pos.deactivate') : __('pos.activate') }}</button>
                        </form>
                        <form method="POST" action="{{ route('fbrpos.phase2.terminals.delete', $t->id) }}" class="inline ml-2" onsubmit="return confirm(@js(__('pos.delete_q')))">
                            @csrf @method('DELETE')
                            <button class="text-red-600 hover:underline text-sm">{{ __('pos.delete') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">{{ __('pos.no_counters_yet') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-fbr-pos-layout>
