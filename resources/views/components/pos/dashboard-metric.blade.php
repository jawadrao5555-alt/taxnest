@props(['metric', 'href' => null])

@php
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif data-dashboard-metric="{{ $metric }}"
    {{ $attributes->class(['block focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2' => $href]) }}>
    {{ $slot }}
</{{ $tag }}>
