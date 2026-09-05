@php
    use App\Models\HealthDepartment;
    use App\Services\HealthAccessService;
    use App\Services\HealthModuleService;
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5" x-data="{ adding: false }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.team_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.team_subtitle') }}</p>
            </div>
            <button type="button" @click="adding = !adding"
                    class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.team_add') }}
            </button>
        </div>

        {{-- ══ Add a staff account ══ --}}
        <div x-show="adding" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <form method="POST" action="{{ url('/health/team') }}" class="space-y-4">
                @csrf
                <h2 class="text-base font-black">{{ __('health.team_add') }}</h2>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.your_name') }}</label>
                        <input type="text" name="name" value="{{ old('name') }}" required
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.email') }}</label>
                        <input type="email" name="email" value="{{ old('email') }}" required autocomplete="off"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.phone') }}</label>
                        <input type="text" name="phone" value="{{ old('phone') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.team_role') }}</label>
                        <select name="health_role" required
                                class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            @foreach($roles as $role)
                                <option value="{{ $role }}">{{ __(HealthAccessService::roleLabelKey($role)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.password') }}</label>
                        <input type="password" name="password" required autocomplete="new-password"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.confirm_password') }}</label>
                        <input type="password" name="password_confirmation" required autocomplete="new-password"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.team_department') }}</label>
                        <select name="health_department_id"
                                class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">{{ __('health.team_no_department') }}</option>
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.team_branches') }}</label>
                        <div class="rounded-xl border border-gray-200 dark:border-gray-600 p-2.5 max-h-32 overflow-y-auto space-y-1">
                            @forelse($branches as $branch)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}"
                                           class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                    {{ $branch->name }}
                                </label>
                            @empty
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.team_all_branches') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                        {{ __('health.team_save') }}
                    </button>
                    <button type="button" @click="adding = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">
                        {{ __('health.cancel') }}
                    </button>
                </div>
            </form>
        </div>

        {{-- ══ Staff list ══ --}}
        <div class="space-y-2.5">
            @foreach($staff as $member)
                @php
                    $memberRole = HealthAccessService::roleFor($member);
                    $memberIsOwner = HealthAccessService::isOwner($member);
                    $customSet = HealthAccessService::customSet($member);
                    $defaults = $roleDefaults[$memberRole] ?? [];
                    $effective = $customSet ?? $defaults;
                    $canDelegate = $isOwner && !$memberIsOwner && in_array($memberRole, $customizableRoles, true);
                @endphp
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden"
                     x-data="{ panel: null }">

                    <div class="px-5 py-4 flex flex-wrap items-center gap-3">
                        <div class="flex-1 min-w-[200px]">
                            <p class="text-sm font-black">
                                {{ $member->name }}
                                @if($memberIsOwner)
                                    <span class="ms-1.5 text-[10px] font-black px-1.5 py-0.5 rounded bg-teal-600 text-white uppercase tracking-wide">{{ __('health.team_owner_badge') }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                {{ $member->email }}
                                &middot; {{ __(HealthAccessService::roleLabelKey($memberRole)) }}
                                @if($customSet !== null)
                                    &middot; <span class="font-bold text-teal-700 dark:text-teal-300">{{ __('health.team_custom_access') }}</span>
                                @endif
                            </p>
                        </div>

                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide
                                     {{ $member->is_active ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                            {{ $member->is_active ? __('health.team_active') : __('health.team_inactive') }}
                        </span>

                        <button type="button" @click="panel = (panel === 'edit' ? null : 'edit')"
                                class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            {{ __('health.edit') }}
                        </button>

                        @if(!$memberIsOwner)
                            <button type="button" @click="panel = (panel === 'dept' ? null : 'dept')"
                                    class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                {{ __('health.team_departments') }}
                            </button>
                        @endif

                        @if($canDelegate)
                            <button type="button" @click="panel = (panel === 'perm' ? null : 'perm')"
                                    class="px-3 py-1.5 rounded-lg border border-teal-200 dark:border-teal-800 text-xs font-bold text-teal-700 dark:text-teal-300 hover:bg-teal-50 dark:hover:bg-teal-900/20 transition">
                                {{ __('health.team_permissions') }}
                            </button>
                        @endif

                        @if(!$memberIsOwner)
                            <form method="POST" action="{{ url('/health/team/' . $member->id . '/toggle-active') }}">
                                @csrf
                                <button type="submit"
                                        class="px-3 py-1.5 rounded-lg border text-xs font-bold transition
                                               {{ $member->is_active
                                                   ? 'border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/20'
                                                   : 'border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/20' }}">
                                    {{ $member->is_active ? __('health.team_deactivate') : __('health.team_activate') }}
                                </button>
                            </form>
                        @endif
                    </div>

                    {{-- ── Edit ── --}}
                    <div x-show="panel === 'edit'" x-cloak class="px-5 pb-5 border-t border-gray-200 dark:border-gray-700 pt-4">
                        <form method="POST" action="{{ url('/health/team/' . $member->id) }}" class="space-y-4">
                            @csrf
                            @method('PUT')
                            <div class="grid sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.your_name') }}</label>
                                    <input type="text" name="name" value="{{ $member->name }}" required
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.email') }}</label>
                                    <input type="email" name="email" value="{{ $member->email }}" required
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.phone') }}</label>
                                    <input type="text" name="phone" value="{{ $member->phone }}"
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>

                                {{-- Only the owner may move somebody between roles. --}}
                                @if($isOwner && !$memberIsOwner)
                                    <div>
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.team_role') }}</label>
                                        <select name="health_role"
                                                class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                            @foreach($roles as $role)
                                                <option value="{{ $role }}" @selected($memberRole === $role)>{{ __(HealthAccessService::roleLabelKey($role)) }}</option>
                                            @endforeach
                                        </select>
                                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.team_role_change_note') }}</p>
                                    </div>
                                @endif

                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.team_department') }}</label>
                                    <select name="health_department_id"
                                            class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                        <option value="">{{ __('health.team_no_department') }}</option>
                                        @foreach($departments as $department)
                                            <option value="{{ $department->id }}" @selected((int) $member->health_department_id === (int) $department->id)>{{ $department->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.team_branches') }}</label>
                                    <div class="rounded-xl border border-gray-200 dark:border-gray-600 p-2.5 max-h-32 overflow-y-auto space-y-1">
                                        @forelse($branches as $branch)
                                            <label class="flex items-center gap-2 text-sm">
                                                <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}"
                                                       @checked(in_array((int) $branch->id, $branchAssignments[$member->id] ?? [], true))
                                                       class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                                {{ $branch->name }}
                                            </label>
                                        @empty
                                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.team_all_branches') }}</p>
                                        @endforelse
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.team_new_password') }}</label>
                                    <input type="password" name="password" autocomplete="new-password"
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.confirm_password') }}</label>
                                    <input type="password" name="password_confirmation" autocomplete="new-password"
                                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                            </div>
                            <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                                {{ __('health.team_save') }}
                            </button>
                        </form>
                    </div>

                    {{-- ── Extra department postings ── --}}
                    @if(!$memberIsOwner)
                        <div x-show="panel === 'dept'" x-cloak class="px-5 pb-5 border-t border-gray-200 dark:border-gray-700 pt-4">
                            <form method="POST" action="{{ url('/health/team/' . $member->id . '/departments') }}" class="space-y-3">
                                @csrf
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.team_departments_hint') }}</p>
                                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-1.5">
                                    @forelse($departments as $department)
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="checkbox" name="department_ids[]" value="{{ $department->id }}"
                                                   @checked(in_array((int) $department->id, $extraDepartments[$member->id] ?? [], true))
                                                   class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                            <span class="truncate">{{ $department->name }}
                                                <span class="text-[10px] text-gray-400">{{ __(HealthDepartment::typeLabelKey($department->type)) }}</span>
                                            </span>
                                        </label>
                                    @empty
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.dept_none') }}</p>
                                    @endforelse
                                </div>
                                <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                                    {{ __('health.team_save') }}
                                </button>
                            </form>
                        </div>
                    @endif

                    {{-- ── Owner delegation. Only capabilities the enabled modules
                         actually expose are listed, so nothing can be granted
                         towards a module this organisation does not run. ── --}}
                    @if($canDelegate)
                        <div x-show="panel === 'perm'" x-cloak class="px-5 pb-5 border-t border-gray-200 dark:border-gray-700 pt-4">
                            <form method="POST" action="{{ url('/health/team/' . $member->id . '/permissions') }}" class="space-y-3">
                                @csrf
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.team_permissions_hint') }}</p>

                                @php
                                    $grouped = [];
                                    foreach ($delegatable as $capability) {
                                        $module = HealthModuleService::moduleForCapability($capability);
                                        $grouped[$module ?? '_core'][] = $capability;
                                    }
                                @endphp

                                <div class="space-y-3">
                                    @foreach($grouped as $group => $capabilities)
                                        <div>
                                            <p class="text-[11px] font-black uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">
                                                {{ $group === '_core' ? __('health.cap_group_core') : __(HealthModuleService::moduleLabelKey($group)) }}
                                            </p>
                                            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-1.5">
                                                @foreach($capabilities as $capability)
                                                    <label class="flex items-center gap-2 text-sm">
                                                        <input type="checkbox" name="capabilities[]" value="{{ $capability }}"
                                                               @checked(in_array($capability, $effective, true))
                                                               class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                                                        <span class="truncate">{{ __(HealthAccessService::capabilityLabelKey($capability)) }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                                        {{ __('health.team_save') }}
                                    </button>
                                    <button type="submit" name="use_defaults" value="1"
                                            class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                        {{ __('health.team_reset_defaults') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-health-layout>
