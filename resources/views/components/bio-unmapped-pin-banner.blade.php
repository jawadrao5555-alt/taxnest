@props([
    'alerts' => collect(),
    // Panel-aware routes (FBR port, Aug 2026): the FBR layout passes its own
    // fbrpos.bio-sync.* routes; defaults keep the PRA layout unchanged.
    'dismissRoute' => null,
    'setupRoute' => null,
])

{{--
    Unmapped biometric PIN alert banner (Task #277, Aug 2026).

    Shown inside <main> in pos-app.blade.php, after the trial/payment banners.
    Only rendered when $alerts is non-empty (gating done in the layout PHP block).

    Design decisions (approved):
    - ≤3 PINs: show each PIN inline with a per-PIN dismiss button.
    - >3 PINs: show count + "View All" link to /pos/bio-sync (no per-PIN list).
    - Dismiss is server-side (POST sets dismissed_at) so page reloads; no Alpine needed.
    - Confined roles/cashiers: never reach this component ($alerts is always empty for them).
--}}

@if($alerts->isNotEmpty())
@php
    $alertCount = $alerts->count();
    $dismissRoute = $dismissRoute ?? route('pos.bio-sync.dismiss-pin-alert');
    $setupRoute   = $setupRoute   ?? route('pos.bio-sync.setup');
@endphp
<div class="bg-orange-50 dark:bg-orange-900/30 border-b border-orange-200 dark:border-orange-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-2.5 flex flex-wrap items-start gap-x-4 gap-y-1.5">

        {{-- Icon + title --}}
        <div class="flex items-center gap-2 min-w-0 flex-shrink-0">
            <svg class="w-4 h-4 flex-shrink-0 text-orange-500 dark:text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/>
            </svg>
            <span class="text-[12px] sm:text-[13px] font-semibold text-orange-800 dark:text-orange-200 whitespace-nowrap">
                {{ __('pos.bio_alert_panel_title', ['count' => $alertCount]) }}
            </span>
        </div>

        {{-- PIN list (≤3) or "View All" link (>3) --}}
        @if($alertCount <= 3)
            <div class="flex flex-wrap items-center gap-2 flex-1 min-w-0">
                @foreach($alerts as $alert)
                    <div class="flex items-center gap-1.5">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono font-semibold
                                     bg-orange-100 dark:bg-orange-800/50 text-orange-800 dark:text-orange-200 border border-orange-200 dark:border-orange-700">
                            PIN {{ $alert->device_pin }}
                        </span>
                        {{-- Per-PIN dismiss form (CSRF-protected, admin only) --}}
                        <form method="POST" action="{{ $dismissRoute }}" class="inline">
                            @csrf
                            <input type="hidden" name="device_pin" value="{{ $alert->device_pin }}">
                            <button type="submit"
                                    title="{{ __('pos.bio_alert_panel_dismiss') }}"
                                    aria-label="{{ __('pos.bio_alert_panel_dismiss') }}"
                                    class="p-0.5 rounded text-orange-400 dark:text-orange-500 hover:text-orange-700 dark:hover:text-orange-300 hover:bg-orange-100 dark:hover:bg-orange-800/50 transition">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                @endforeach

                {{-- Map Now link --}}
                <a href="{{ $setupRoute }}"
                   class="text-[12px] font-semibold text-orange-700 dark:text-orange-300 underline underline-offset-2 hover:text-orange-900 dark:hover:text-orange-100 whitespace-nowrap">
                    {{ __('pos.bio_map_now') }} →
                </a>
            </div>
        @else
            {{-- Collapsed view for >3 PINs --}}
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <span class="text-[12px] text-orange-700 dark:text-orange-300">
                    {{ __('pos.bio_alert_panel_sub', ['count' => $alertCount]) }}
                </span>
                <a href="{{ $setupRoute }}"
                   class="text-[12px] font-semibold text-orange-700 dark:text-orange-300 underline underline-offset-2 hover:text-orange-900 dark:hover:text-orange-100 whitespace-nowrap">
                    {{ __('pos.bio_map_now') }} →
                </a>
            </div>
        @endif

    </div>
</div>
@endif
