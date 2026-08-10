<x-pos-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    @include('pos.partials.back-link')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-gray-900 dark:text-white">{{ __('pos.tutorials_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.tutorials_sub') }}</p>
    </div>

    @forelse($groups as $key => $group)
    <div class="mb-8">
        <div class="flex items-center gap-2.5 mb-4">
            <h2 class="text-sm font-bold uppercase tracking-wide text-gray-700 dark:text-gray-300">{{ $group['label'] }}</h2>
            <div class="flex-1 h-px bg-gray-200 dark:bg-gray-800"></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            @foreach($group['videos'] as $v)
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                <video class="w-full bg-black tut-lazy" style="aspect-ratio: 16 / 9;" controls preload="none" playsinline data-src="{{ $v->video_url }}"></video>
                <div class="p-4">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ $v->title }}</h3>
                    @if($v->description)
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">{{ $v->description }}</p>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @empty
    <p class="text-center text-gray-500 py-16">{{ __('pos.tutorials_more_soon') }}</p>
    @endforelse

    <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 text-center">
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.tutorials_more_soon') }} {{ __('pos.tutorials_watch_public') }}</p>
    </div>
</div>
<script>
/* Lazy-load tutorial videos: set src only when video scrolls into view.
   preload="none" + data-src means zero network requests on page load.
   rootMargin 300px: starts loading one screen-height before the video appears. */
(function () {
    var obs = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (!e.isIntersecting) return;
            var v = e.target;
            if (v.dataset.src) { v.src = v.dataset.src; delete v.dataset.src; }
            obs.unobserve(v);
        });
    }, { rootMargin: '300px' });
    document.querySelectorAll('video.tut-lazy').forEach(function (v) { obs.observe(v); });
})();
</script>
</x-pos-layout>
