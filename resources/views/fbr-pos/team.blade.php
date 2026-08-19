<x-fbr-pos-layout>
<div class="max-w-6xl mx-auto">
    @include('fbr-pos.partials.back-link')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.team_management') }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ __('pos.team_management_sub') }}</p>
        </div>
    </div>

    @if(session('success'))<div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">{{ $errors->first() }}</div>@endif

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 mb-5">
        <h2 class="font-bold mb-3 text-gray-900 dark:text-white">{{ __('pos.add_team_member') }}</h2>
        <form method="POST" action="{{ route('fbrpos.team.store') }}" class="grid sm:grid-cols-2 lg:grid-cols-{{ $branches->isNotEmpty() ? '7' : '6' }} gap-3">
            @csrf
            <input type="text" name="name" value="{{ old('name') }}" placeholder="{{ __('pos.ph_cashier_name') }}" required autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            <input type="email" name="email" value="{{ old('email') }}" placeholder="{{ __('pos.email_label') }}" required autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            {{-- Task 529 (PRA twin): optional login username --}}
            <input type="text" name="username" value="{{ old('username') }}" placeholder="{{ __('pos.username_label') }} ({{ __('pos.optional_lc') }})" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            <input type="text" name="password" placeholder="{{ __('pos.password_label') }}" required autocomplete="new-password" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            <select name="pos_role" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                <option value="pos_cashier">{{ __('pos.role_cashier') }}</option>
                <option value="pos_manager">{{ __('pos.role_manager') }}</option>
            </select>
            @if($branches->isNotEmpty())
            <select name="default_branch_id" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                <option value="">{{ __('pos.main_branch') }}</option>
                @foreach($branches as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>
            @endif
            <button class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-blue-700">{{ __('pos.create_account') }}</button>
        </form>
        @if(!($quota['allowed'] ?? true))
        <p class="mt-2 text-xs text-amber-600">{{ $quota['reason'] ?? '' }}</p>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden" x-data="{ editId: null }">
        <table class="w-full text-sm table-cards">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr>
                    <th class="px-4 py-3">{{ __('pos.full_name') }}</th><th>{{ __('pos.email_label') }}</th><th>{{ __('pos.username_label') }}</th><th>{{ __('pos.role_label') }}</th>@if($branches->isNotEmpty())<th>{{ __('pos.branch_word') }}</th>@endif<th>{{ __('pos.password_label') }}</th><th>{{ __('pos.status_word') }}</th><th class="text-right pr-4">{{ __('pos.actions_label') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($team as $member)
                @php $isAdminRow = $member->pos_role === 'pos_admin'; @endphp
                <tr class="border-t dark:border-gray-700">
                    <td class="px-4 py-3 font-semibold dark:text-white">{{ $member->name }}</td>
                    <td class="dark:text-gray-300">{{ $member->email }}</td>
                    <td class="dark:text-gray-300 font-mono text-xs">{{ $member->username ?: '—' }}</td>
                    <td class="dark:text-gray-300">
                        @if($isAdminRow)<span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs">{{ __('pos.owner_word') }}</span>
                        @elseif($member->pos_role === 'pos_manager'){{ __('pos.role_manager') }}
                        @else{{ __('pos.role_cashier') }}@endif
                    </td>
                    @if($branches->isNotEmpty())
                    <td class="dark:text-gray-300">{{ optional($branches->firstWhere('id', $member->default_branch_id))->name ?? __('pos.main_branch') }}</td>
                    @endif
                    <td class="dark:text-gray-300">
                        @if(!$isAdminRow && isset($teamPasswords[$member->id]))
                        <span x-data="{ show: false }">
                            <span x-show="show" x-cloak class="font-mono">{{ $teamPasswords[$member->id] }}</span>
                            <button type="button" @click="show = !show" class="text-blue-600 hover:underline text-xs" x-text="show ? '✕' : @js(__('pos.show_password'))"></button>
                        </span>
                        @else
                        —
                        @endif
                    </td>
                    <td>@if($member->is_active)<span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded text-xs">{{ __('pos.active_word') }}</span>@else<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs">{{ __('pos.inactive_word') }}</span>@endif</td>
                    <td class="text-right pr-4 py-3">
                        @unless($isAdminRow)
                        <button type="button" @click="editId = editId === {{ $member->id }} ? null : {{ $member->id }}" class="text-blue-600 hover:underline text-sm">{{ __('pos.edit') }}</button>
                        <form method="POST" action="{{ route('fbrpos.team.toggle', $member->id) }}" class="inline ml-2">
                            @csrf
                            <button class="{{ $member->is_active ? 'text-red-600' : 'text-emerald-600' }} hover:underline text-sm">{{ $member->is_active ? __('pos.deactivate') : __('pos.activate') }}</button>
                        </form>
                        @endunless
                    </td>
                </tr>
                @unless($isAdminRow)
                <tr x-show="editId === {{ $member->id }}" x-cloak class="border-t dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
                    <td colspan="{{ $branches->isNotEmpty() ? 8 : 7 }}" class="px-4 py-4">
                        <form method="POST" action="{{ route('fbrpos.team.update', $member->id) }}" class="grid sm:grid-cols-2 lg:grid-cols-{{ $branches->isNotEmpty() ? '7' : '6' }} gap-3">
                            @csrf @method('PUT')
                            <input type="text" name="name" value="{{ $member->name }}" required autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                            <input type="email" name="email" value="{{ $member->email }}" required autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                            {{-- Task 529: set/change login username (blank = clear) --}}
                            <input type="text" name="username" value="{{ $member->username }}" placeholder="{{ __('pos.username_label') }} ({{ __('pos.optional_lc') }})" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                            <input type="text" name="password" placeholder="{{ __('pos.ph_new_password_optional') }}" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-1p-ignore class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                            <select name="pos_role" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                                <option value="pos_cashier" @selected($member->pos_role === 'pos_cashier')>{{ __('pos.role_cashier') }}</option>
                                <option value="pos_manager" @selected($member->pos_role === 'pos_manager')>{{ __('pos.role_manager') }}</option>
                            </select>
                            {{-- Task 1271: khufia identity-switch counterpart (PRA Task 705 parity,
                                 NO billing-scope condition — FBR panel has no scopes). Cashier rows
                                 only; options = other ACTIVE cashiers of this company. --}}
                            @if(\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_counterpart_user_id') && $member->pos_role === 'pos_cashier')
                            <select name="pos_counterpart_user_id" title="{{ __('pos.fbr_counterpart_label') }}" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                                <option value="">{{ __('pos.pra_counterpart_none') }}</option>
                                @foreach($team as $cpOption)
                                    @if($cpOption->pos_role === 'pos_cashier' && $cpOption->id !== $member->id && $cpOption->is_active)
                                    <option value="{{ $cpOption->id }}" @selected((int) ($member->pos_counterpart_user_id ?? 0) === $cpOption->id)>{{ __('pos.fbr_counterpart_label') }}: {{ $cpOption->name }}</option>
                                    @endif
                                @endforeach
                            </select>
                            @endif
                            @if($branches->isNotEmpty())
                            <select name="default_branch_id" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                                <option value="">{{ __('pos.main_branch') }}</option>
                                @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected((int) $member->default_branch_id === $b->id)>{{ $b->name }}</option>
                                @endforeach
                            </select>
                            @endif
                            <div class="flex gap-2">
                                <button class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-blue-700">{{ __('pos.save_changes') }}</button>
                                <button type="button" @click="editId = null" class="text-gray-500 hover:underline text-sm">{{ __('pos.cancel') }}</button>
                            </div>
                        </form>
                    </td>
                </tr>
                @endunless
            @empty
                <tr><td colspan="{{ $branches->isNotEmpty() ? 8 : 7 }}" class="px-4 py-8 text-center text-gray-500">{{ __('pos.no_team_members') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-fbr-pos-layout>
