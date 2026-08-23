<x-fbr-pos-layout>
<div class="max-w-5xl mx-auto">
    @include('fbr-pos.partials.back-link')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.branches_title') }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ __('pos.branches_desc') }}</p>
        </div>
    </div>

    @if(session('success'))<div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">{{ $errors->first() }}</div>@endif

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 mb-5">
        <h2 class="font-bold mb-3 text-gray-900 dark:text-white">{{ __('pos.add_branch') }}</h2>
        <form method="POST" action="{{ route('fbrpos.branches.store') }}" class="grid sm:grid-cols-3 gap-3">
            @csrf
            <input type="text" name="name" value="{{ old('name') }}" placeholder="{{ __('pos.ph_branch_name') }}" required autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            <input type="text" name="city" value="{{ old('city') }}" placeholder="{{ __('pos.city_word') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            <button class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-blue-700">{{ __('pos.add_branch') }}</button>
        </form>
        @if(!($quota['allowed'] ?? true))
        <p class="mt-2 text-xs text-amber-600">{{ \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason'] ?? __('pos.plan_locked_feature')) }}</p>
        @endif

        {{-- Quota summary: package ki shamil branches + khareede hue paid slots. --}}
        @if($addon['limit'] !== null)
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ __('pos.eb_quota_line', ['used' => $addon['used'], 'limit' => $addon['limit']]) }}
            @if($addon['override'] !== null)
                <span class="text-gray-400">({{ __('pos.eb_admin_set') }})</span>
            @elseif($addon['included'] !== null)
                <span class="text-gray-400">({{ __('pos.eb_included_line', ['included' => $addon['included'], 'slots' => $addon['slots']]) }})</span>
            @endif
        </p>
        @endif
    </div>

    {{-- Paid extra-branch add-on (Rs 10,000/branch/year) — same component and
         same approval path as the PRA POS panel. --}}
    <x-extra-branch-addon :addon="$addon" :bank="$bank" :action="route('fbrpos.payment-proof.store')" accent="blue" />

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden" x-data="{ editId: null }">
        <table class="w-full text-sm table-cards">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr>
                    <th class="px-4 py-3">{{ __('pos.branch_word') }}</th><th>{{ __('pos.city_word') }}</th><th>{{ __('pos.status_word') }}</th><th class="text-right pr-4">{{ __('pos.actions_label') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($branches as $branch)
                <tr class="border-t dark:border-gray-700">
                    <td class="px-4 py-3 font-semibold dark:text-white">
                        {{ $branch->name }}
                        @if($branch->is_head_office)<span class="ml-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-[10px] align-middle">{{ __('pos.main_branch') }}</span>@endif
                    </td>
                    <td class="dark:text-gray-300">{{ $branch->city ?? '—' }}</td>
                    <td>@if($branch->is_active ?? true)<span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded text-xs">{{ __('pos.active_word') }}</span>@else<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs">{{ __('pos.inactive_word') }}</span>@endif</td>
                    <td class="text-right pr-4 py-3">
                        <button type="button" @click="editId = editId === {{ $branch->id }} ? null : {{ $branch->id }}" class="text-blue-600 hover:underline text-sm">{{ __('pos.edit') }}</button>
                        <form method="POST" action="{{ route('fbrpos.branches.toggle', $branch->id) }}" class="inline ml-2">
                            @csrf
                            <button class="{{ ($branch->is_active ?? true) ? 'text-red-600' : 'text-emerald-600' }} hover:underline text-sm">{{ ($branch->is_active ?? true) ? __('pos.deactivate') : __('pos.activate') }}</button>
                        </form>
                    </td>
                </tr>
                <tr x-show="editId === {{ $branch->id }}" x-cloak class="border-t dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
                    <td colspan="4" class="px-4 py-4">
                        <form method="POST" action="{{ route('fbrpos.branches.update', $branch->id) }}" class="grid sm:grid-cols-3 gap-3">
                            @csrf @method('PUT')
                            <input type="text" name="name" value="{{ $branch->name }}" required autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                            <input type="text" name="city" value="{{ $branch->city }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                            <div class="flex gap-2">
                                <button class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-blue-700">{{ __('pos.save_changes') }}</button>
                                <button type="button" @click="editId = null" class="text-gray-500 hover:underline text-sm">{{ __('pos.cancel') }}</button>
                            </div>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">{{ __('pos.no_branches_yet') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-fbr-pos-layout>
