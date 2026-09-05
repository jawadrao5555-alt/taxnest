@php use App\Support\HealthPanel; @endphp
<x-health-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.settings_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.settings_subtitle') }}</p>
        </div>

        {{-- Organisation summary — read-only here; the type itself is changed on
             the modules screen, where it actually means something. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-base font-black">{{ __('health.settings_org_card') }}</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.settings_org_card_desc') }}</p>
            <dl class="mt-4 grid sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.org_name') }}</dt>
                    <dd class="mt-0.5 font-bold">{{ $company->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.org_type') }}</dt>
                    <dd class="mt-0.5 font-bold">{{ __(HealthPanel::orgTypeLabelKey($orgType)) }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.dash_package') }}</dt>
                    <dd class="mt-0.5 font-bold">{{ $plan->name ?? __('health.dash_no_package') }}</dd>
                </div>
            </dl>
        </div>

        {{-- Only the cards this person can actually open. --}}
        @php
            $cards = [];
            if ($isOwner) {
                $cards[] = [
                    'url' => route('health.settings.modules'),
                    'title' => __('health.settings_modules_card'),
                    'desc' => __('health.settings_modules_card_desc'),
                    'meta' => count($enabledModules) . ' ' . __('health.module_on'),
                ];
            }
            if (in_array('departments.manage', $healthCapabilities ?? [], true)) {
                $cards[] = [
                    'url' => route('health.departments'),
                    'title' => __('health.settings_departments_card'),
                    'desc' => __('health.settings_departments_card_desc'),
                    'meta' => $departmentCount . ($departmentLimit >= 0 ? ' / ' . $departmentLimit : ''),
                ];
            }
            if (in_array('staff.manage', $healthCapabilities ?? [], true)) {
                $cards[] = [
                    'url' => route('health.team'),
                    'title' => __('health.settings_team_card'),
                    'desc' => __('health.settings_team_card_desc'),
                    'meta' => null,
                ];
            }
        @endphp

        <div class="grid sm:grid-cols-2 gap-3">
            @foreach($cards as $card)
                <a href="{{ $card['url'] }}"
                   class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 hover:border-teal-400 transition">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-sm font-black">{{ $card['title'] }}</h3>
                        @if($card['meta'])
                            <span class="text-[10px] font-black uppercase tracking-wide px-2 py-0.5 rounded-full bg-teal-50 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300 whitespace-nowrap">
                                {{ $card['meta'] }}
                            </span>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">{{ $card['desc'] }}</p>
                </a>
            @endforeach
        </div>

        {{-- FBR: readiness only. The foundation submits nothing, so it must not
             suggest that it does. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-5 py-4 flex items-center gap-3">
            <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $fbrReadiness['configured'] ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {{ $fbrReadiness['configured'] ? __('health.dash_fbr_ready') : __('health.dash_fbr_not_ready') }}
            </p>
        </div>
    </div>
</x-health-layout>
