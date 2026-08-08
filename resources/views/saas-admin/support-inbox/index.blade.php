<x-admin-layout>
<div class="p-4 sm:p-6 max-w-5xl mx-auto" x-data="{ compose: {{ $errors->any() ? 'true' : 'false' }} }">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-white">Support Inbox</h1>
            <p class="text-xs text-gray-500 dark:text-gray-400">support@taxnest.com.pk — emails yahin parhein aur jawab dein</p>
        </div>
        <button @click="compose = !compose" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">Compose Email</button>
    </div>

    @if($error)
        <div class="bg-red-900/40 border border-red-700 text-red-300 text-sm rounded-lg px-4 py-3 mb-4">{{ $error }}</div>
    @endif

    <div x-show="compose" x-cloak class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-4">
        <h2 class="text-sm font-semibold text-white mb-3">Nayi Email</h2>
        <form method="POST" action="{{ route('saas.admin.support-inbox.send') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="email" name="to" value="{{ old('to') }}" required placeholder="To — email address"
                   class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2" />
            <input type="text" name="subject" value="{{ old('subject') }}" required placeholder="Subject"
                   class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2" />
            <textarea name="body" rows="6" required placeholder="Message..."
                      class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2">{{ old('body') }}</textarea>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <input type="file" name="attachments[]" multiple class="text-xs text-gray-400" />
                    <p class="text-[11px] text-gray-500 mt-1">You can select multiple files — max 10 MB each.</p>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">Send</button>
            </div>
            @error('to') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
            @error('subject') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
            @error('body') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
            @foreach($errors->get('attachments') as $msgs) @foreach((array)$msgs as $m) <p class="text-xs text-red-400">{{ $m }}</p> @endforeach @endforeach
            @foreach($errors->get('attachments.*') as $msgs) @foreach((array)$msgs as $m) <p class="text-xs text-red-400">{{ $m }}</p> @endforeach @endforeach
        </form>
    </div>

    <div class="flex items-center gap-2 mb-3">
        <a href="{{ route('saas.admin.support-inbox') }}"
           class="px-4 py-1.5 rounded-lg text-sm {{ $tab === 'inbox' ? 'bg-emerald-600 text-white font-medium' : 'bg-gray-800 text-gray-400 hover:text-gray-200' }}">
            Inbox @if($unread > 0)<span class="ml-1 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold">{{ $unread }}</span>@endif
        </a>
        <a href="{{ route('saas.admin.support-inbox', ['tab' => 'sent']) }}"
           class="px-4 py-1.5 rounded-lg text-sm {{ $tab === 'sent' ? 'bg-emerald-600 text-white font-medium' : 'bg-gray-800 text-gray-400 hover:text-gray-200' }}">Sent</a>
        <a href="{{ route('saas.admin.support-inbox', ['tab' => $tab]) }}" class="ml-auto px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-gray-400 hover:text-gray-200" title="Refresh">&#8635; Refresh</a>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        @forelse($list['messages'] as $m)
            <a href="{{ route('saas.admin.support-inbox.show', ['box' => $tab, 'uid' => $m['uid']]) }}"
               class="flex items-center gap-3 px-4 py-3 border-b border-gray-800 last:border-0 hover:bg-gray-800/60 transition {{ (! $m['seen'] && $tab === 'inbox') ? 'bg-gray-800/30' : '' }}">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-sm truncate {{ (! $m['seen'] && $tab === 'inbox') ? 'font-bold text-white' : 'text-gray-300' }}">
                            {{ $tab === 'sent' ? ($m['to_email'] ?: '(unknown)') : ($m['from_name'] ?: $m['from_email'] ?: '(unknown)') }}
                        </span>
                        @if($m['has_attachments'])<span class="text-gray-500 text-xs">&#128206;</span>@endif
                    </div>
                    <p class="text-sm truncate {{ (! $m['seen'] && $tab === 'inbox') ? 'font-semibold text-gray-200' : 'text-gray-500' }}">{{ $m['subject'] }}</p>
                </div>
                <span class="text-xs text-gray-500 whitespace-nowrap">{{ $m['date'] ? $m['date']->format('d M Y, h:i A') : '' }}</span>
            </a>
        @empty
            <div class="px-4 py-10 text-center text-sm text-gray-500">
                {{ $error ? 'Mailbox load nahi ho saki.' : 'Koi email nahi.' }}
            </div>
        @endforelse
    </div>

    @if($list['last_page'] > 1)
        <div class="flex items-center justify-between mt-4 text-sm">
            <span class="text-gray-500 text-xs">Page {{ $list['page'] }} / {{ $list['last_page'] }} — total {{ $list['total'] }}</span>
            <div class="flex gap-2">
                @if($list['page'] > 1)
                    <a href="{{ route('saas.admin.support-inbox', ['tab' => $tab, 'page' => $list['page'] - 1]) }}" class="px-3 py-1.5 rounded-lg bg-gray-800 text-gray-300 hover:bg-gray-700">&larr; Pichli</a>
                @endif
                @if($list['page'] < $list['last_page'])
                    <a href="{{ route('saas.admin.support-inbox', ['tab' => $tab, 'page' => $list['page'] + 1]) }}" class="px-3 py-1.5 rounded-lg bg-gray-800 text-gray-300 hover:bg-gray-700">Agli &rarr;</a>
                @endif
            </div>
        </div>
    @endif
</div>
</x-admin-layout>
