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

    <div id="si-message-list" class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        @include('saas-admin.support-inbox._list', ['tab' => $tab, 'list' => $list, 'error' => $error])
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

    <script>
    (function () {
        var listEl = document.getElementById('si-message-list');
        if (!listEl) return;
        @php $siPollUrl = route('saas.admin.support-inbox.poll', ['tab' => $tab, 'page' => $list['page']], false); @endphp
        var url = {!! json_encode($siPollUrl) !!};
        var fingerprint = null; // learned from the first poll (server is the source of truth)
        var inFlight = false;

        function poll() {
            if (document.hidden || inFlight) return;
            inFlight = true;
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !d.ok || !d.fingerprint) return;
                    if (fingerprint !== null && d.fingerprint !== fingerprint && typeof d.html === 'string') {
                        listEl.innerHTML = d.html;
                    }
                    fingerprint = d.fingerprint;
                    // Keep the sidebar badge in sync too (same payload, free).
                    var badge = document.getElementById('si-unread-badge');
                    if (badge && typeof d.unread !== 'undefined') {
                        var n = parseInt(d.unread, 10) || 0;
                        if (n > 0) { badge.textContent = n; badge.style.display = ''; }
                        else { badge.textContent = ''; badge.style.display = 'none'; }
                    }
                })
                .catch(function () {})
                .finally(function () { inFlight = false; });
        }

        setInterval(poll, 30000);
        // Refresh promptly when the admin returns to the tab.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) poll();
        });
        poll(); // learn the initial fingerprint right away
    })();
    </script>
</div>
</x-admin-layout>
