@php
    use App\Services\HealthModuleService;
    use App\Support\HealthPanel;
@endphp
<x-health-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.modules_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.modules_subtitle') }}</p>
        </div>

        <form method="POST" action="{{ route('health.settings.modules.update') }}" class="space-y-5">
            @csrf

            {{-- Organisation type: drives the sensible defaults, and the label the
                 panel prints. Changing it never rewrites the owner's own set. --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2.5">{{ __('health.org_type') }}</label>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    @foreach(HealthPanel::ORG_TYPES as $type)
                        <label class="cursor-pointer">
                            <input type="radio" name="org_type" value="{{ $type }}" class="peer sr-only" @checked($orgType === $type)>
                            <span class="block text-center px-3 py-2.5 rounded-xl border-2 border-gray-200 dark:border-gray-600 text-sm font-bold text-gray-600 dark:text-gray-300
                                         peer-checked:border-teal-600 peer-checked:bg-teal-50 peer-checked:text-teal-800
                                         dark:peer-checked:bg-teal-900/30 dark:peer-checked:text-teal-200 transition">
                                {{ __(HealthPanel::orgTypeLabelKey($type)) }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- One card per module. A module the package does not sell is shown
                 LOCKED rather than hidden: this is the one screen where naming an
                 unavailable feature is honest, because it is where an owner decides
                 whether to upgrade. Everywhere else it simply does not exist. --}}
            <div class="space-y-2.5">
                @foreach($allModules as $module)
                    @php
                        $sold = in_array($module, $planModules, true);
                        $on = in_array($module, $enabledModules, true);
                        $meta = HealthModuleService::MODULE_META[$module] ?? [];
                    @endphp
                    <label class="flex items-start gap-4 rounded-2xl border p-4 transition
                                  {{ $sold ? 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 cursor-pointer hover:border-teal-400' : 'bg-gray-50 dark:bg-gray-800/50 border-dashed border-gray-300 dark:border-gray-700' }}">
                        <input type="checkbox" name="modules[]" value="{{ $module }}"
                               class="mt-1 w-5 h-5 rounded border-gray-300 text-teal-600 focus:ring-teal-500 disabled:opacity-40"
                               @checked($on) @disabled(!$sold)>
                        <span class="flex-1 min-w-0">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="text-lg leading-none">{{ $meta['icon'] ?? '•' }}</span>
                                <span class="text-sm font-black">{{ __(HealthModuleService::moduleLabelKey($module)) }}</span>
                                @if(!$sold)
                                    <span class="px-2 py-0.5 rounded-full bg-gray-200 dark:bg-gray-700 text-[10px] font-black uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                        {{ __('health.module_locked') }}
                                    </span>
                                @elseif($on)
                                    <span class="px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-[10px] font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                                        {{ __('health.module_on') }}
                                    </span>
                                @endif
                            </span>
                            <span class="block mt-1 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                                {{ __(HealthModuleService::moduleDescriptionKey($module)) }}
                            </span>
                            @if(!$sold)
                                <span class="block mt-1 text-[11px] font-semibold text-gray-500 dark:text-gray-400">{{ __('health.module_locked_hint') }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>

            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('health.dashboard') }}" class="text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">
                    {{ __('health.back_to_dashboard') }}
                </a>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                    {{ __('health.modules_save') }}
                </button>
            </div>
        </form>
    </div>
</x-health-layout>
