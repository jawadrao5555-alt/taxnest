<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">Tutorial Videos</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Control where each Urdu tutorial video appears. Files ship with deploys; this page only controls visibility.</p>
        </div>
    </x-slot>

    <div class="py-6 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-4">

        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl px-4 py-3 text-xs sm:text-sm text-blue-800 dark:text-blue-200 leading-relaxed">
            <span class="font-semibold">Published</span> = master switch (OFF hides the video everywhere).
            <span class="font-semibold">Landing page</span> = show on the public /tutorials page (visitors).
            <span class="font-semibold">Feature gate</span> = inside company logins, only subscriptions that include this feature see the video ("Everyone" = core feature, all companies).
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="px-4 py-3">Video</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Feature gate (subscription)</th>
                        <th class="px-4 py-3">Staff visibility (role)</th>
                        <th class="px-4 py-3">Landing page</th>
                        <th class="px-4 py-3">Published</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($videos as $v)
                    <tr class="{{ $v->is_published ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $v->product === 'nestpos' ? 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' }}">
                                    {{ \App\Models\TutorialVideo::PRODUCTS[$v->product] ?? ucfirst((string) $v->product) }}
                                </span>
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $v->title }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        <a href="{{ $v->video_url }}" target="_blank" class="hover:underline">{{ $v->slug }}</a>
                                        @if($v->duration_seconds) · {{ gmdate($v->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $v->duration_seconds) }} min @endif
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300 whitespace-nowrap">{{ \App\Models\TutorialVideo::CATEGORIES[$v->category] ?? ucfirst((string) $v->category) }}</td>
                        <td class="px-4 py-3">
                            <form method="POST" action="/admin/tutorial-videos/{{ $v->id }}/gate" class="flex items-center gap-1.5">
                                @csrf
                                <select name="required_feature" class="text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 py-1.5 pr-7">
                                    <option value="">Everyone (core)</option>
                                    @foreach($gateOptions as $key => $label)
                                    <option value="{{ $key }}" {{ $v->required_feature === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <select name="audience_family" aria-label="Audience family" class="text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 py-1.5 pr-7">
                                    @foreach($audienceOptions as $key => $label)
                                    <option value="{{ $key }}" {{ $v->audience_family === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200">Set</button>
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            {{-- ZFC (5 Aug 2026): staff role tier — waiter/kitchen/rider ko
                                 sirf 'Everyone' videos dikhti hain (PRA/settings unse chhupi). --}}
                            <form method="POST" action="/admin/tutorial-videos/{{ $v->id }}/role" class="flex items-center gap-1.5">
                                @csrf
                                <select name="min_role" class="text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 py-1.5 pr-7">
                                    @foreach($roleOptions as $key => $label)
                                    <option value="{{ $key }}" {{ ($v->min_role ?? 'any') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <select name="audience_family" aria-label="Audience family" class="text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 py-1.5 pr-7">
                                    @foreach($audienceOptions as $key => $label)
                                    <option value="{{ $key }}" {{ $v->audience_family === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200">Set</button>
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" action="/admin/tutorial-videos/{{ $v->id }}/toggle-public" class="inline">
                                @csrf
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium {{ $v->show_public ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300 hover:bg-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 hover:bg-gray-200' }}">
                                    {{ $v->show_public ? 'Visible' : 'Hidden' }}
                                </button>
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" action="/admin/tutorial-videos/{{ $v->id }}/toggle-published" class="inline">
                                @csrf
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium {{ $v->is_published ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300 hover:bg-green-200' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 hover:bg-amber-200' }}">
                                    {{ $v->is_published ? 'Published' : 'Unpublished' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">No tutorial videos yet — they arrive via deploys from the recording pipeline.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin-layout>
