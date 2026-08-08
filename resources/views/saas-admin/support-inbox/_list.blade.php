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
        {{ ($error ?? null) ? 'Mailbox load nahi ho saki.' : 'Koi email nahi.' }}
    </div>
@endforelse
