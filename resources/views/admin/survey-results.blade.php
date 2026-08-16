<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Survey Results — {{ $survey->title }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif

            <div class="mb-5 flex items-center justify-between gap-3 flex-wrap">
                <a href="{{ route('admin.surveys') }}" class="text-sm text-emerald-600 dark:text-emerald-400 hover:underline">← All surveys</a>
                <div class="flex items-center gap-2">
                    @if(!$survey->is_published)
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Draft</span>
                    @elseif($survey->closed_at)
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">Closed {{ $survey->closed_at->format('d M Y') }}</span>
                    @else
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">Live on POS</span>
                    @endif
                    @if($survey->is_published)
                        <form method="POST" action="{{ route('admin.surveys.toggle-close', $survey->id) }}">
                            @csrf
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold {{ $survey->closed_at ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-red-600 text-white hover:bg-red-700' }}">
                                {{ $survey->closed_at ? 'Reopen survey' : 'Close survey (results stay)' }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Reach summary --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                @foreach([
                    ['Companies saw', $seenCompanies],
                    ['Companies answered', $answeredCompanies],
                    ['Users saw', $responses->count()],
                    ['Users answered', $answered->count()],
                ] as [$lbl, $val])
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 px-4 py-3.5 text-center">
                        <div class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $val }}</div>
                        <div class="text-[11px] text-gray-400 mt-0.5">{{ $lbl }}</div>
                    </div>
                @endforeach
            </div>

            @if($survey->intro)
                <div class="mb-6 p-4 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 text-sm text-gray-600 dark:text-gray-300">{{ $survey->intro }}</div>
            @endif

            {{-- Per-question counts (overall + restaurant-mode split) --}}
            @foreach($stats as $qi => $q)
                <div class="mb-5 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ $qi + 1 }}. {{ $q['text'] }}</h3>
                    </div>
                    <div class="px-5 py-4 space-y-3">
                        @php $qMax = max(1, collect($q['options'])->max('count')); @endphp
                        @foreach($q['options'] as $opt)
                            <div>
                                <div class="flex items-center justify-between text-sm mb-1">
                                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ $opt['label'] }}</span>
                                    <span class="text-gray-500 dark:text-gray-400 text-xs">
                                        <span class="font-bold text-gray-800 dark:text-gray-100">{{ $opt['count'] }}</span>
                                        <span class="ml-1.5 px-1.5 py-0.5 rounded bg-orange-50 text-orange-600 dark:bg-orange-900/20 dark:text-orange-300 text-[10px] font-medium" title="Restaurant-mode companies">🍽 {{ $opt['restaurant'] }}</span>
                                    </span>
                                </div>
                                <div class="h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
                                    <div class="h-full bg-emerald-500 rounded-full" style="width: {{ round($opt['count'] / $qMax * 100) }}%;"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- Free-text mashwaray --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">💬 Mashwaray ({{ $comments->count() }})</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($comments as $c)
                        <div class="px-5 py-3.5">
                            <div class="flex items-center gap-2 flex-wrap text-xs text-gray-500 dark:text-gray-400">
                                <span class="font-semibold text-gray-800 dark:text-gray-100">{{ $c->company->name ?? 'Company #' . $c->company_id }}</span>
                                <span>· {{ $c->user->name ?? 'User #' . $c->user_id }}</span>
                                @if($c->company->restaurant_mode ?? false)
                                    <span class="px-1.5 py-0.5 rounded bg-orange-50 text-orange-600 dark:bg-orange-900/20 dark:text-orange-300 text-[10px] font-medium">Restaurant</span>
                                @endif
                                <span class="ml-auto">{{ $c->answered_at?->format('d M Y h:i A') }}</span>
                            </div>
                            <p class="mt-1.5 text-sm text-gray-700 dark:text-gray-200 whitespace-pre-line">{{ $c->comment }}</p>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center text-gray-400 text-sm">No written suggestions yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
