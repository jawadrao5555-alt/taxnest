<x-pos-layout>
@php
    $tnTeamUser = auth('pos')->user();
    $tnTeamCompany = \App\Models\Company::find(app('currentCompanyId'));
    // Plan gate (Aug 2026): Custom Access is Unlimited-only.
    $customAccessPlanAllowed = \App\Services\PosFeatureService::planAllows(
        $tnTeamCompany, 'custom_access_enabled');
    // Billing Scope visibility (owner rule 07 Aug 2026): by default sirf OWNER
    // ko dikhta hai; owner neeche wale switch se admins ko bhi ijazat de sakta hai.
    $scopeManageAllowed = $tnTeamUser?->canManageBillingScope($tnTeamCompany) ?? false;
    $scopeAdminEnabled = (bool) ($tnTeamCompany->billing_scope_admin_enabled ?? false);
    // Task 705: PRA counterpart link (khufia station identity switch) — same
    // owner-only visibility as billing scope. PROD schema-drift guard.
    $counterpartColReady = \Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_counterpart_user_id');
@endphp
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('pos.back_to_customize') }}
    </a>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.team_management') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.team_management_sub') }}</p>
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.add_team_member') }}</h3>
            <button @click="showForm = !showForm" class="text-sm text-purple-600 hover:text-purple-700 font-medium">
                <span x-text="showForm ? @js(__('pos.cancel')) : @js(__('pos.add_member_btn'))"></span>
            </button>
        </div>
        <form x-show="showForm" x-transition method="POST" action="{{ route('pos.team.store-cashier') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.full_name') }}</label>
                <input type="text" name="name" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="{{ __('pos.ph_cashier_name') }}">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.email_label') }}</label>
                <input type="email" name="email" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="cashier@email.com">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.phone_optional') }}</label>
                <input type="text" name="phone" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="03001234567">
            </div>
            {{-- Task 529: optional login username — staff logs in with it instead
                 of the full email (username login already works backend-side). --}}
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.username_label') }} ({{ __('pos.optional_lc') }})</label>
                <input type="text" name="username" value="{{ old('username') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="{{ __('pos.ph_eg_username') }}">
                <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-1">{{ __('pos.username_login_hint') }}</p>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.password_label') }}</label>
                <input type="password" name="password" required minlength="6" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="{{ __('pos.ph_min_6_chars') }}">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.role_label') }}</label>
                <select name="pos_role" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                    <option value="pos_cashier">{{ __('pos.role_opt_cashier') }}</option>
                    <option value="pos_manager">{{ __('pos.role_opt_manager') }}</option>
                    <option value="pos_kitchen">{{ __('pos.role_opt_kitchen') }}</option>
                    <option value="pos_waiter">{{ __('pos.role_opt_waiter') }}</option>
                    <option value="pos_delivery">{{ __('pos.role_opt_delivery') }}</option>
                </select>
            </div>
            {{-- Billing Scope (07 Aug 2026): lock a cashier/manager to one billing
                 stream. Server ignores it for confined roles (kitchen/waiter/delivery).
                 Owner rule: sirf owner (ya allowed admin) ko dikhta hai. --}}
            @if($scopeManageAllowed)
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.billing_scope_label') }}</label>
                {{-- Task 1186: "Auto" (default) = NULL column — cashier ki effective
                     stream us ki reporting status se derive hoti hai; server 'auto'
                     ko ignore kar ke column unset chhorta hai. --}}
                <select name="pos_billing_scope" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                    <option value="auto" selected>{{ __('pos.billing_scope_auto') }}</option>
                    <option value="both">{{ __('pos.billing_scope_both') }}</option>
                    <option value="local">{{ __('pos.billing_scope_local') }}</option>
                    <option value="pra">{{ __('pos.billing_scope_pra') }}</option>
                </select>
                <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-1">{{ __('pos.billing_scope_hint_role') }} {{ __('pos.billing_scope_auto_hint') }}</p>
            </div>
            @endif
            <div class="sm:col-span-2">
                <button type="submit" class="px-6 py-2 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition font-semibold">{{ __('pos.create_account') }}</button>
            </div>
        </form>
    </div>

    {{-- Billing Scope permission switch (owner rule 07 Aug 2026): SIRF owner ko
         nazar aata hai — ON karne se managers/admins bhi Billing Scope set kar
         sakte hain; OFF (default) = sirf owner. --}}
    @if(($tnTeamUser->role ?? null) === 'company_admin')
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.billing_scope_perm_title') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.billing_scope_perm_hint') }}</p>
        </div>
        <form method="POST" action="{{ route('pos.team.scope-permission') }}" class="shrink-0">
            @csrf
            <input type="hidden" name="enabled" value="{{ $scopeAdminEnabled ? 0 : 1 }}">
            <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-lg transition {{ $scopeAdminEnabled ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300' }}">
                {{ $scopeAdminEnabled ? __('pos.billing_scope_perm_on') : __('pos.billing_scope_perm_off') }}
            </button>
        </form>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                        <th class="px-4 py-3">{{ __('pos.name_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.email_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.username_label') }}</th>
                        <th class="px-4 py-3 hidden sm:table-cell">{{ __('pos.phone_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.password_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.role_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.status_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.pra_reporting') }}</th>
                        <th class="px-4 py-3">{{ __('pos.actions_label') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($team as $member)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50" x-data="{ editing: false, showPw: false, accessOpen: false }">
                        <td class="px-4 py-3" data-label="{{ __('pos.name_label') }}">
                            <span x-show="!editing" class="font-medium text-gray-900 dark:text-white">{{ $member->name }}</span>
                            <template x-if="editing">
                                <input form="edit-{{ $member->id }}" type="text" name="name" value="{{ $member->name }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            </template>
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('pos.email_label') }}">
                            <span x-show="!editing" class="text-gray-600 dark:text-gray-400">{{ $member->email }}</span>
                            <template x-if="editing">
                                <input form="edit-{{ $member->id }}" type="email" name="email" value="{{ $member->email }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            </template>
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('pos.username_label') }}">
                            {{-- Task 529: login username — admin sets/changes it from the edit row --}}
                            <span x-show="!editing" class="text-gray-600 dark:text-gray-400 font-mono text-xs">{{ $member->username ?: '—' }}</span>
                            <template x-if="editing">
                                <input form="edit-{{ $member->id }}" type="text" name="username" value="{{ $member->username }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore placeholder="{{ __('pos.ph_eg_username') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            </template>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell" data-label="{{ __('pos.phone_label') }}">
                            <span x-show="!editing" class="text-gray-600 dark:text-gray-400">{{ $member->phone ?? '—' }}</span>
                            <template x-if="editing">
                                <input form="edit-{{ $member->id }}" type="text" name="phone" value="{{ $member->phone }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            </template>
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('pos.password_label') }}">
                            {{-- Owner request (Jul 2026): admin can VIEW team passwords.
                                 Decrypted server-side (admin-gated page); hidden behind an
                                 eye toggle. Old accounts have no stored copy until the
                                 admin sets a new password from the edit row. --}}
                            @if(isset($teamPasswords[$member->id]))
                            <div class="flex items-center gap-1.5">
                                <span class="font-mono text-xs text-gray-700 dark:text-gray-300" x-text="showPw ? {{ \Illuminate\Support\Js::from($teamPasswords[$member->id]) }} : '••••••••'"></span>
                                <button type="button" @click="showPw = !showPw" class="text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition" :title="showPw ? @js(__('pos.ti_hide_password')) : @js(__('pos.ti_show_password'))">
                                    <svg x-show="!showPw" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <svg x-show="showPw" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                </button>
                            </div>
                            @elseif(in_array($member->pos_role, ['pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter', 'pos_delivery'], true))
                            <span class="text-xs text-gray-400" title="{{ __('pos.ti_password_not_saved') }}">{{ __('pos.set_new_password_to_view') }}</span>
                            @else
                            <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('pos.role_label') }}">
                            @if($member->pos_role === 'pos_admin' || $member->role === 'company_admin')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">{{ __('pos.role_admin') }}</span>
                            @elseif($member->pos_role === 'pos_manager')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400">{{ __('pos.role_manager') }}</span>
                            @elseif($member->pos_role === 'pos_kitchen')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">{{ __('pos.role_kitchen') }}</span>
                            @elseif($member->pos_role === 'pos_waiter')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">{{ __('pos.role_waiter') }}</span>
                            @elseif($member->pos_role === 'pos_delivery')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400">{{ __('pos.role_delivery_manager') }}</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ __('pos.role_cashier') }}</span>
                            @endif
                            {{-- Billing Scope badge (07 Aug 2026): owner (ya allowed admin) ko hi dikhta hai.
                                 Task 1186: explicit lock = purane sky/emerald badges; DERIVED default
                                 (unset cashier) = "Auto" badge jo effective stream dikhata hai. --}}
                            @if($scopeManageAllowed && in_array($member->pos_role, ['pos_cashier', 'pos_manager'], true))
                                @php $mExplicitScope = $member->posBillingScopeExplicit(); @endphp
                                @if($mExplicitScope !== 'both')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide mt-1 {{ $mExplicitScope === 'local' ? 'bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' }}" title="{{ __('pos.billing_scope_label') }}">
                                    {{ $mExplicitScope === 'local' ? __('pos.billing_scope_badge_local') : __('pos.billing_scope_badge_pra') }}
                                </span>
                                @elseif($member->posBillingScopeIsDerived())
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide mt-1 bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300" title="{{ __('pos.billing_scope_auto_hint') }}">
                                    {{ $member->posBillingScope($company) === 'local' ? __('pos.billing_scope_badge_auto_local') : __('pos.billing_scope_badge_auto_pra') }}
                                </span>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('pos.status_label') }}">
                            @if($member->is_active)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">{{ __('pos.active_word') }}</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">{{ __('pos.inactive_word') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('pos.pra_reporting') }}">
                            {{-- Owner rule (20 Jul 2026): admin ASSIGNS each cashier Online (PRA
                                 reporting) / Offline here — the sale-screen toggle is read-only
                                 for cashiers. Admin/Manager keep their own sale-screen toggle. --}}
                            @if(($company->pos_integration_mode ?? 'pra') === 'standalone')
                            <span class="text-xs text-gray-400">—</span>
                            @elseif($member->pos_role === 'pos_cashier')
                            @php $memberPraOn = (bool) $member->praReportingEnabled($company); @endphp
                            <form method="POST" action="{{ route('pos.team.set-pra', $member->id) }}" class="inline">
                                @csrf
                                <input type="hidden" name="enabled" value="{{ $memberPraOn ? 0 : 1 }}">
                                <button type="submit"
                                    title="{{ $memberPraOn ? __('pos.ti_set_cashier_offline') : __('pos.ti_set_cashier_online') }}"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wide transition {{ $memberPraOn ? 'bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/50' : 'bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700' }}">
                                    <span class="w-2 h-2 rounded-full {{ $memberPraOn ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                    {{ $memberPraOn ? __('pos.online') : __('pos.offline') }}
                                    <svg class="w-3 h-3 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                </button>
                            </form>
                            @elseif($member->pos_role === 'pos_admin' || $member->pos_role === 'pos_manager' || $member->role === 'company_admin')
                            @php $memberPraOn = (bool) $member->praReportingEnabled($company); @endphp
                            <span title="{{ __('pos.ti_admin_controls_own_pra') }}"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wide {{ $memberPraOn ? 'bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300' : 'bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400' }}">
                                <span class="w-2 h-2 rounded-full {{ $memberPraOn ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                {{ $memberPraOn ? __('pos.online') : __('pos.offline') }}
                            </span>
                            @else
                            <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" data-label="{{ __('pos.actions_label') }}">
                            @if(in_array($member->pos_role, ['pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter', 'pos_delivery'], true))
                            <div class="flex items-center gap-2">
                                <button x-show="!editing" @click="editing = true" class="text-amber-600 hover:text-amber-700 text-xs font-medium" title="{{ __('pos.edit') }}">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                                <template x-if="editing">
                                    <div class="flex items-center gap-1">
                                        <form id="edit-{{ $member->id }}" method="POST" action="{{ route('pos.team.update-cashier', $member->id) }}">
                                            @csrf
                                            @method('PUT')
                                        </form>
                                        {{-- Item #7: optional password reset — blank keeps the current one.
                                             Setting a new password also refreshes the admin-viewable
                                             encrypted copy shown in the Password column. --}}
                                        <input form="edit-{{ $member->id }}" type="password" name="password" placeholder="{{ __('pos.ph_new_password_optional') }}" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-36 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1.5 focus:ring-purple-500 focus:border-purple-500">
                                        {{-- Billing Scope (07 Aug 2026): cashier + manager only; owner (ya allowed admin) hi dekh sakta hai --}}
                                        @if($scopeManageAllowed && in_array($member->pos_role, ['pos_cashier', 'pos_manager'], true))
                                        @php
                                            // Task 1186: dropdown selection = RAW column state, not the
                                            // effective scope. Cashier gets the extra "Auto" option
                                            // (derived default — label shows the current effective
                                            // stream); "Dono (Both)" is the owner's OFF switch.
                                            $mScopeIsCashier = $member->pos_role === 'pos_cashier';
                                            $mScopeRaw = in_array($member->pos_billing_scope, ['both', 'local', 'pra'], true)
                                                ? $member->pos_billing_scope
                                                : ($mScopeIsCashier ? 'auto' : 'both');
                                        @endphp
                                        <select form="edit-{{ $member->id }}" name="pos_billing_scope" title="{{ __('pos.billing_scope_label') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1.5 focus:ring-purple-500 focus:border-purple-500">
                                            @if($mScopeIsCashier)
                                            <option value="auto" {{ $mScopeRaw === 'auto' ? 'selected' : '' }}>{{ $member->praReportingEnabled($company) ? __('pos.billing_scope_auto_pra') : __('pos.billing_scope_auto_local') }}</option>
                                            @endif
                                            <option value="both" {{ $mScopeRaw === 'both' ? 'selected' : '' }}>{{ __('pos.billing_scope_both') }}</option>
                                            <option value="local" {{ $mScopeRaw === 'local' ? 'selected' : '' }}>{{ __('pos.billing_scope_local') }}</option>
                                            <option value="pra" {{ $mScopeRaw === 'pra' ? 'selected' : '' }}>{{ __('pos.billing_scope_pra') }}</option>
                                        </select>
                                        @endif
                                        {{-- Task 705: PRA counterpart (khufia identity switch target) —
                                             LOCAL-scoped cashier only; owner-only visibility (scope rule).
                                             Options = same-company ACTIVE cashiers that can bill PRA. --}}
                                        {{-- Task 1186: counterpart link = EXPLICIT-lock feature (Task 705) — derived default par nahi khulta. --}}
                                        @if($counterpartColReady && $scopeManageAllowed && $member->pos_role === 'pos_cashier' && $member->posBillingScopeExplicit() === 'local')
                                        <select form="edit-{{ $member->id }}" name="pos_counterpart_user_id" title="{{ __('pos.pra_counterpart_label') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1.5 focus:ring-purple-500 focus:border-purple-500">
                                            <option value="">{{ __('pos.pra_counterpart_none') }}</option>
                                            @foreach($team as $cpOption)
                                                @if($cpOption->pos_role === 'pos_cashier' && $cpOption->id !== $member->id && $cpOption->is_active && $cpOption->posBillingScopeExplicit() !== 'local')
                                                <option value="{{ $cpOption->id }}" {{ (int) ($member->pos_counterpart_user_id ?? 0) === $cpOption->id ? 'selected' : '' }}>{{ __('pos.pra_counterpart_label') }}: {{ $cpOption->name }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        @endif
                                        <button form="edit-{{ $member->id }}" type="submit" class="text-emerald-600 hover:text-emerald-700 text-xs font-medium">{{ __('pos.save_btn') }}</button>
                                        <button @click="editing = false" class="text-gray-400 hover:text-gray-600 text-xs font-medium">{{ __('pos.cancel') }}</button>
                                    </div>
                                </template>
                                <form method="POST" action="{{ route('pos.team.toggle-cashier', $member->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="{{ $member->is_active ? 'text-red-500 hover:text-red-700' : 'text-emerald-600 hover:text-emerald-700' }} text-xs font-medium" title="{{ $member->is_active ? __('pos.deactivate') : __('pos.activate') }}">
                                        {{ $member->is_active ? __('pos.deactivate') : __('pos.activate') }}
                                    </button>
                                </form>
                                {{-- Custom Access (Task #111): cashier + manager only — confined
                                     roles (kitchen/waiter/delivery) keep their fixed confinement. --}}
                                @if(in_array($member->pos_role, \App\Services\PosAccessService::CUSTOMIZABLE_ROLES, true))
                                @php $memberAccess = \App\Services\PosAccessService::customSet($member); @endphp
                                <button x-show="!editing" @click="accessOpen = true" class="inline-flex items-center gap-1 text-purple-600 hover:text-purple-700 text-xs font-medium" title="{{ __('pos.custom_access') }}">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                    {{ __('pos.custom_access') }}
                                    @if($memberAccess !== null)
                                    <span class="px-1.5 py-0.5 rounded bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 text-[9px] font-bold uppercase">{{ __('pos.custom_badge') }}</span>
                                    @endif
                                </button>
                                {{-- Modal --}}
                                <template x-teleport="body">
                                <div x-show="accessOpen" x-cloak class="fixed inset-0 z-[120] flex items-center justify-center p-4" style="background: rgba(15,10,40,0.55); backdrop-filter: blur(3px);" @keydown.escape.window="accessOpen = false">
                                    <div class="w-full max-w-md bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden" @click.outside="accessOpen = false"
                                         x-data="{ customOn: {{ $memberAccess !== null ? 'true' : 'false' }} }"
                                         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
                                        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                                            <div>
                                                <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.custom_access_title', ['name' => $member->name]) }}</h3>
                                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.custom_access_sale_note') }}</p>
                                            </div>
                                            <button @click="accessOpen = false" class="text-gray-400 hover:text-gray-600 p-1">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                        @if(!($customAccessPlanAllowed ?? true))
                                        {{-- Plan lock-card (Aug 2026): Custom Access is Unlimited-only --}}
                                        <div class="px-5 py-6 flex flex-col items-center text-center gap-3">
                                            <div class="w-12 h-12 rounded-xl bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                                                <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                            </div>
                                            <p class="text-xs text-gray-600 dark:text-gray-300 max-w-xs">{{ __('pos.custom_access_plan_locked') }}</p>
                                            <a href="{{ route('pos.billing') }}" class="px-5 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold">{{ __('pos.upgrade_your_plan') }}</a>
                                        </div>
                                        @else
                                        <form method="POST" action="{{ route('pos.team.set-access', $member->id) }}">
                                            @csrf
                                            <div class="px-5 py-4 max-h-[60vh] overflow-y-auto">
                                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.custom_access_hint') }}</p>
                                                <label class="flex items-center gap-2.5 mb-3 cursor-pointer">
                                                    <input type="hidden" name="custom_enabled" :value="customOn ? 1 : 0">
                                                    <input type="checkbox" x-model="customOn" class="rounded border-gray-300 dark:border-gray-600 text-purple-600 focus:ring-purple-500">
                                                    <span class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ __('pos.custom_access_enable_label') }}</span>
                                                </label>
                                                <div class="grid grid-cols-2 gap-2" :class="!customOn && 'opacity-40 pointer-events-none'">
                                                    @foreach(\App\Services\PosAccessService::FEATURES as $featKey)
                                                    <label class="flex items-center gap-2 px-2.5 py-2 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer">
                                                        <input type="checkbox" name="features[]" value="{{ $featKey }}"
                                                               @checked($memberAccess !== null && in_array($featKey, $memberAccess, true))
                                                               class="rounded border-gray-300 dark:border-gray-600 text-purple-600 focus:ring-purple-500">
                                                        <span class="text-[11px] font-medium text-gray-700 dark:text-gray-300">{{ __('pos.feat_' . $featKey) }}</span>
                                                    </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                            <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-800 flex items-center justify-end gap-2">
                                                <button type="button" @click="accessOpen = false" class="px-4 py-2 rounded-lg text-xs font-medium text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800">{{ __('pos.cancel') }}</button>
                                                <button type="submit" class="px-5 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold">{{ __('pos.save_btn') }}</button>
                                            </div>
                                        </form>
                                        @endif
                                    </div>
                                </div>
                                </template>
                                @endif
                            </div>
                            @else
                            <span class="text-xs text-gray-400">{{ __('pos.owner_word') }}</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">{{ __('pos.no_team_members') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</x-pos-layout>
