@props(['title', 'subtitle' => null, 'back' => true, 'heading' => 'h1'])

<header {{ $attributes->class(['mb-5']) }} data-pos-report-header>
    @if($back)
        @include('pos.partials.back-link')
    @endif
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <{{ $heading }} class="text-2xl font-bold text-gray-900 dark:text-white">{{ $title }}</{{ $heading }}>
            @if($subtitle)
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
            @endif
        </div>
        @if(isset($actions))
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endif
    </div>
</header>
