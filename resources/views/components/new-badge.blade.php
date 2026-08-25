{{--
    "NEW" nishan — owner ask, 26 Aug 2026.

    Aik hi component char tareeqon se chalta hai (register: App\Services\NewFeatureBadges):
      <x-new-badge feature="kot_last_addon_switch" />   ← khud us switch ke saath
      <x-new-badge page="pos.receipt-settings" />       ← us page par koi nayi cheez ho to
      <x-new-badge :url="$card['url']" />               ← Customize hub ke card par
      <x-new-badge panel="pos" dot />                   ← nav pill par chhota nuqta

    Window guzarne par khud gayab ho jata hai — kuch hataana nahi parta.
--}}
@props([
    'feature' => null,
    'page' => null,
    'url' => null,
    'panel' => null,
    'dot' => false,
])
@php
    $tnShowNew = \App\Services\NewFeatureBadges::shows($feature, $page, $url, $panel);
@endphp
@if($tnShowNew)
    @if($dot)
        <span {{ $attributes->merge(['class' => 'inline-block w-2 h-2 rounded-full bg-emerald-400 align-middle']) }}
              title="{{ __('pos.new_badge_title') }}"
              aria-label="{{ __('pos.new_badge_title') }}"></span>
    @else
        <span {{ $attributes->merge(['class' => 'shrink-0 inline-flex items-center text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full bg-emerald-500 text-white']) }}
              title="{{ __('pos.new_badge_title') }}">{{ __('pos.new_badge') }}</span>
    @endif
@endif
