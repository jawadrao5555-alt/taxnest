@php
    use App\Services\HealthModuleService;
    use App\Support\HealthPanel;
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        {{-- ── Heading ── --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.welcome_back', ['name' => $healthUser->name ?? '']) }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $company->name ?? '' }} &middot; {{ __(HealthPanel::orgTypeLabelKey($orgType)) }}
                </p>
            </div>
            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-teal-50 dark:bg-teal-900/25 text-teal-800 dark:text-teal-200 text-xs font-bold">
                {{ __('health.dash_package') }}: {{ $plan->name ?? __('health.dash_no_package') }}
            </span>
        </div>

        {{-- ── Platform notifications (same table every other panel reads) ── --}}
        @if($notifications->isNotEmpty())
            <div class="space-y-2">
                @foreach($notifications as $notif)
                    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm text-amber-900 dark:text-amber-100">
                        <span class="font-bold">{{ $notif->title }}</span> &middot; {{ $notif->message }}
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ── First-run setup nudge (owner only; disappears once modules saved) ── --}}
        @if(!$setupComplete && in_array('settings.manage.modules', $healthCapabilities ?? [], true))
            <div class="rounded-2xl border border-teal-200 dark:border-teal-800 bg-teal-50 dark:bg-teal-900/20 p-5 flex flex-wrap items-center gap-4">
                <div class="flex-1 min-w-[220px]">
                    <h2 class="text-base font-black text-teal-900 dark:text-teal-100">{{ __('health.dash_setup_title') }}</h2>
                    <p class="text-sm text-teal-800/80 dark:text-teal-200/80 mt-0.5">{{ __('health.dash_setup_body') }}</p>
                </div>
                <a href="{{ route('health.settings.modules') }}"
                   class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                    {{ __('health.dash_setup_cta') }}
                </a>
            </div>
        @endif

        {{-- ── Foundation counters. Only what this task actually owns: no invented
             patient or revenue tiles for data that does not exist yet. ── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @php
                $tiles = [
                    ['label' => __('health.dash_modules_on'), 'value' => count($enabledModules)],
                    ['label' => __('health.dash_departments'), 'value' => $departments->count()],
                    ['label' => __('health.dash_branches'), 'value' => max($branches->count(), 1)],
                ];
                if (in_array('staff.manage', $healthCapabilities ?? [], true)) {
                    $tiles[] = ['label' => __('health.dash_staff'), 'value' => $staffCount];
                }
            @endphp
            @foreach($tiles as $tile)
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</p>
                    <p class="mt-1 text-2xl font-black">{{ $tile['value'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- ── The organisation's own modules ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-black">{{ __('health.dash_your_modules') }}</h2>
                @if(in_array('settings.manage.modules', $healthCapabilities ?? [], true))
                    <a href="{{ route('health.settings.modules') }}" class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">
                        {{ __('health.nav_modules') }}
                    </a>
                @endif
            </div>

            @if(empty($enabledModules))
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('health.modules_none_on') }}</p>
            @else
                <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($enabledModules as $module)
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-center gap-2">
                                <span class="text-lg leading-none">{{ HealthModuleService::MODULE_META[$module]['icon'] ?? '•' }}</span>
                                <span class="text-sm font-black">{{ __(HealthModuleService::moduleLabelKey($module)) }}</span>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                                {{ __(HealthModuleService::moduleDescriptionKey($module)) }}
                            </p>
                            {{-- Honest status: the module is switched on, but its own
                                 screens ship with the module's own task. Saying so here
                                 is why the sidebar does not carry a dead link. --}}
                            <p class="mt-2 inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-[10px] font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                {{ __('health.coming_soon') }}
                            </p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('health.module_not_built_yet') }}</p>
            @endif
        </div>

        {{-- ── Quick actions the foundation really has ── --}}
        @php
            $actions = [];
            if (in_array('departments.manage', $healthCapabilities ?? [], true)) {
                $actions[] = ['url' => route('health.departments'), 'label' => __('health.nav_departments')];
            }
            if (in_array('staff.manage', $healthCapabilities ?? [], true)) {
                $actions[] = ['url' => route('health.team'), 'label' => __('health.nav_team')];
            }
            if (in_array('settings.manage', $healthCapabilities ?? [], true)) {
                $actions[] = ['url' => route('health.settings'), 'label' => __('health.nav_settings')];
            }
        @endphp
        @if(!empty($actions))
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-base font-black">{{ __('health.dash_quick_actions') }}</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($actions as $action)
                        <a href="{{ $action['url'] }}"
                           class="px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700/60 transition">
                            {{ $action['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── FBR readiness. READINESS only — the foundation files nothing. ── --}}
        @if(in_array('settings.manage', $healthCapabilities ?? [], true))
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-5 py-4 flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $fbrReadiness['configured'] ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    {{ $fbrReadiness['configured'] ? __('health.dash_fbr_ready') : __('health.dash_fbr_not_ready') }}
                </p>
            </div>
        @endif
    </div>
</x-health-layout>
