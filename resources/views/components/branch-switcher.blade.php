@props([
    'color' => 'emerald', // emerald | purple | blue
    // Panel-aware "manage branches" target. Default = DI/web panel route, so
    // the DI + FBR layouts keep their existing behavior; the POS layout passes
    // its own /pos/branches page (Task 1347).
    'manageUrl' => '/branches',
    // Owner-only "All branches" entry (Task 1347, PRA POS): lets the owner see
    // company-wide figures again after branches exist. Opt-in per panel.
    'allowAll' => false,
])
@php
    $svc = app(\App\Services\BranchContextService::class);
    $branches = $svc->accessibleBranches();
    $current = $currentBranch ?? null;
    $canSwitch = $svc->canSwitch();
    $allOn = $allowAll && $svc->isOwner();
    $allActive = $allOn && $svc->isAllBranches();
    // With "All branches" available, a single-branch owner still needs the
    // dropdown (branch ↔ all), so the switcher must not render as locked.
    $canSwitch = $canSwitch || $allOn;
    $colorMap = [
        'emerald' => ['ring' => 'ring-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-300', 'bg' => 'bg-emerald-50 dark:bg-emerald-900/30', 'border' => 'border-emerald-200 dark:border-emerald-800', 'dot' => 'bg-emerald-500'],
        'purple'  => ['ring' => 'ring-purple-500', 'text' => 'text-purple-700 dark:text-purple-300', 'bg' => 'bg-purple-50 dark:bg-purple-900/30', 'border' => 'border-purple-200 dark:border-purple-800', 'dot' => 'bg-purple-500'],
        'blue'    => ['ring' => 'ring-blue-500', 'text' => 'text-blue-700 dark:text-blue-300', 'bg' => 'bg-blue-50 dark:bg-blue-900/30', 'border' => 'border-blue-200 dark:border-blue-800', 'dot' => 'bg-blue-500'],
    ];
    $c = $colorMap[$color] ?? $colorMap['emerald'];
@endphp

@if($branches->isNotEmpty())
<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button
        type="button"
        @click="{{ $canSwitch ? 'open = !open' : '' }}"
        @if(!$canSwitch) disabled @endif
        class="flex items-center gap-2 px-3 py-1.5 rounded-lg border {{ $c['border'] }} {{ $c['bg'] }} {{ $c['text'] }} text-xs font-bold hover:shadow-sm transition {{ $canSwitch ? 'cursor-pointer' : 'cursor-default opacity-90' }}"
        title="{{ $canSwitch ? __('pos.branch_switch_tooltip') : __('pos.branch_locked_tooltip') }}">
        <span class="w-2 h-2 rounded-full {{ $c['dot'] }} animate-pulse"></span>
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
        <span class="hidden sm:inline truncate max-w-[120px]">{{ $allActive ? __('pos.branch_all') : ($current->name ?? __('pos.branch_select')) }}</span>
        @if($canSwitch)
            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        @endif
    </button>

    @if($canSwitch)
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-cloak
        class="absolute right-0 mt-2 w-72 bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 z-50 overflow-hidden">
        <div class="px-4 py-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
            <p class="text-[10px] uppercase tracking-wider font-bold text-gray-500 dark:text-gray-400">{{ __('pos.branch_switch_heading') }}</p>
        </div>
        <div class="max-h-72 overflow-y-auto">
            @if($allOn)
                {{-- Owner-only company-wide view: keeps pre-branch (NULL branch_id)
                     history and every branch's figures visible in one place. --}}
                <form method="POST" action="/branch/switch" class="block">
                    @csrf
                    <input type="hidden" name="branch_id" value="all">
                    <button type="submit"
                        class="w-full text-left px-4 py-2.5 hover:{{ $c['bg'] }} flex items-center justify-between gap-3 {{ $allActive ? $c['bg'] : '' }}">
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-gray-900 dark:text-white truncate">{{ __('pos.branch_all') }}</div>
                            <div class="text-[11px] text-gray-500 truncate">{{ __('pos.branch_all_desc') }}</div>
                        </div>
                        @if($allActive)
                            <svg class="w-4 h-4 {{ $c['text'] }} flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        @endif
                    </button>
                </form>
            @endif
            @foreach($branches as $b)
                <form method="POST" action="/branch/switch" class="block">
                    @csrf
                    <input type="hidden" name="branch_id" value="{{ $b->id }}">
                    <button type="submit"
                        class="w-full text-left px-4 py-2.5 hover:{{ $c['bg'] }} flex items-center justify-between gap-3 {{ $current && $current->id === $b->id ? $c['bg'] : '' }}">
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-gray-900 dark:text-white truncate">
                                {{ $b->name }}
                                @if($b->is_head_office)
                                    <span class="ml-1 text-[9px] px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-800 font-bold uppercase">{{ __('pos.branch_hq_badge') }}</span>
                                @endif
                            </div>
                            <div class="text-[11px] text-gray-500 truncate">{{ $b->city ?: $b->address ?: '—' }}</div>
                        </div>
                        @if(!$allActive && $current && $current->id === $b->id)
                            <svg class="w-4 h-4 {{ $c['text'] }} flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        @endif
                    </button>
                </form>
            @endforeach
        </div>
        @if($svc->isOwner())
            <a href="{{ $manageUrl }}" class="block px-4 py-2 text-center text-xs font-bold {{ $c['text'] }} bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 hover:{{ $c['bg'] }}">
                {{ __('pos.branch_manage_link') }}
            </a>
        @endif
    </div>
    @endif
</div>
@endif
