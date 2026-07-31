<x-pos-layout>
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('pos.back_to_customize') }}
    </a>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.pos_services') }}</h1>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.add_new_service') }}</h3>
        <form method="POST" action="{{ route('pos.services.store') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.name_label') }}</label>
                <input type="text" name="name" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('pos.ph_service_name') }}">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.price_pkr') }}</label>
                <input type="number" name="price" required step="0.01" min="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="0.00">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.tax_rate_pct_label') }}</label>
                <input type="number" name="tax_rate" step="0.01" min="0" max="100" value="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.description_label') }}</label>
                <input type="text" name="description" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('pos.optional') }}">
            </div>
            <div class="flex items-center gap-3 pt-5">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_tax_exempt" value="1" class="rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('pos.tax_exempt_label') }}</span>
                </label>
                <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 transition">{{ __('pos.add_service') }}</button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm table-cards">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 uppercase">
                    <th class="px-4 py-3">{{ __('pos.name_label') }}</th>
                    <th class="px-4 py-3 hidden md:table-cell">{{ __('pos.description_label') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('pos.receipt_price') }}</th>
                    <th class="px-4 py-3 text-right hidden sm:table-cell">{{ __('pos.tax_rate_col') }}</th>
                    <th class="px-4 py-3 hidden sm:table-cell">{{ __('pos.status_col') }}</th>
                    <th class="px-4 py-3">{{ __('pos.actions_col') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($services as $service)
                <tr class="border-b border-gray-100 dark:border-gray-800 {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}" x-data="{ editing: false }">
                    <td class="px-4 py-3" x-show="!editing">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $service->name }}</span>
                            @if($service->is_tax_exempt)
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">{{ __('pos.exempt_badge') }}</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 hidden md:table-cell" x-show="!editing">{{ $service->description ?? '-' }}</td>
                    <td class="px-4 py-3 text-right text-gray-900 dark:text-white" x-show="!editing">PKR {{ number_format($service->price, 2) }}</td>
                    <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 hidden sm:table-cell" x-show="!editing">{{ $service->tax_rate }}%</td>
                    <td class="px-4 py-3 hidden sm:table-cell" x-show="!editing">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $service->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' }}">
                            {{ $service->is_active ? __('pos.active_word') : __('pos.inactive_word') }}
                        </span>
                    </td>
                    <td class="px-4 py-3" x-show="!editing">
                        <div class="flex items-center gap-2">
                            <button @click="editing = true" class="text-blue-600 hover:underline text-xs">{{ __('pos.edit') }}</button>
                            <form method="POST" action="{{ route('pos.services.delete', $service->id) }}" onsubmit="return confirm({{ Js::from(__('pos.confirm_delete_service')) }})">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline text-xs">{{ __('pos.delete') }}</button>
                            </form>
                        </div>
                    </td>
                    <td colspan="6" class="px-4 py-3" x-show="editing" x-cloak>
                        <form method="POST" action="{{ route('pos.services.update', $service->id) }}" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 items-end">
                            @csrf @method('PUT')
                            <input type="text" name="name" value="{{ $service->name }}" required placeholder="{{ __('pos.name_label') }}" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1 w-full">
                            <input type="text" name="description" value="{{ $service->description }}" placeholder="{{ __('pos.description_label') }}" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1 w-full">
                            <input type="number" name="price" value="{{ $service->price }}" step="0.01" required placeholder="{{ __('pos.receipt_price') }}" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1 w-full">
                            <input type="number" name="tax_rate" value="{{ $service->tax_rate }}" step="0.01" placeholder="{{ __('pos.ph_tax_pct') }}" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1 w-full">
                            <div class="flex items-center gap-2 flex-wrap">
                                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_active" {{ $service->is_active ? 'checked' : '' }} class="rounded"> {{ __('pos.active_word') }}</label>
                                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_tax_exempt" value="1" {{ $service->is_tax_exempt ? 'checked' : '' }} class="rounded border-gray-300 text-amber-600 focus:ring-amber-500"> {{ __('pos.exempt') }}</label>
                                <button type="submit" class="text-emerald-600 text-xs font-medium hover:underline">{{ __('pos.save_btn') }}</button>
                                <button type="button" @click="editing = false" class="text-gray-500 text-xs hover:underline">{{ __('pos.cancel') }}</button>
                            </div>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">{{ __('pos.no_services_yet') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
</x-pos-layout>