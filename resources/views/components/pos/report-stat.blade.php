@props(['label', 'value', 'href' => null])

@php
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif
    {{ $attributes->class([
        'block min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900',
        'hover:shadow-md focus-visible:ring-2 focus-visible:ring-teal-600' => $href,
    ]) }}>
    <span class="block text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $label }}</span>
    <strong class="mt-1 block break-words text-xl font-bold text-gray-900 dark:text-white">{{ $value }}</strong>
    {{ $slot }}
</{{ $tag }}>
