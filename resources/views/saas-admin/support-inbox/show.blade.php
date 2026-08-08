<x-admin-layout>
<div class="p-4 sm:p-6 max-w-4xl mx-auto" x-data="{ reply: false }">
    <a href="{{ route('saas.admin.support-inbox', ['tab' => $box]) }}" class="text-sm text-gray-400 hover:text-gray-200">&larr; {{ $box === 'sent' ? 'Sent' : 'Inbox' }} par wapis</a>

    <div class="bg-gray-900 border border-gray-800 rounded-xl mt-3 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-800">
            <h1 class="text-lg font-bold text-white break-words">{{ $message['subject'] }}</h1>
            <div class="mt-1 text-xs text-gray-400 space-y-0.5">
                <p><span class="text-gray-500">From:</span> {{ $message['from_name'] }} &lt;{{ $message['from_email'] }}&gt;</p>
                <p><span class="text-gray-500">To:</span> {{ $message['to_email'] }}</p>
                <p><span class="text-gray-500">Date:</span> {{ $message['date'] ? $message['date']->format('d M Y, h:i A') : '' }}</p>
            </div>
        </div>

        <div class="bg-white">
            @if($message['html'])
                <iframe sandbox="" srcdoc="{{ $message['html'] }}" class="w-full border-0" style="min-height: 420px;" onload="try{this.style.height=(this.contentDocument.body.scrollHeight+40)+'px'}catch(e){}"></iframe>
            @else
                <pre class="p-5 text-sm text-gray-800 whitespace-pre-wrap font-sans">{{ $message['text'] ?? '(empty)' }}</pre>
            @endif
        </div>

        @if(count($message['attachments']))
            <div class="px-5 py-3 border-t border-gray-800 bg-gray-900">
                <p class="text-xs font-semibold text-gray-400 mb-2">Attachments ({{ count($message['attachments']) }})</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($message['attachments'] as $att)
                        <a href="{{ route('saas.admin.support-inbox.attachment', ['box' => $box, 'uid' => $message['uid'], 'index' => $att['index']]) }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800 text-xs text-gray-300 hover:bg-gray-700">
                            &#128206; {{ $att['name'] }} <span class="text-gray-500">({{ number_format($att['size'] / 1024, 1) }} KB)</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    @if($box === 'inbox')
        <div class="mt-4">
            <button @click="reply = !reply" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">Reply</button>

            <div x-show="reply" x-cloak class="bg-gray-900 border border-gray-800 rounded-xl p-5 mt-3">
                <form method="POST" action="{{ route('saas.admin.support-inbox.send') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <input type="hidden" name="in_reply_to" value="{{ $message['message_id'] }}" />
                    <input type="hidden" name="references" value="{{ $message['references'] }}" />
                    <input type="hidden" name="reply_box" value="{{ $box }}" />
                    <input type="hidden" name="reply_uid" value="{{ $message['uid'] }}" />
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">To</label>
                        <input type="email" name="to" required value="{{ old('to', $message['from_email']) }}"
                               class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Subject</label>
                        <input type="text" name="subject" required value="{{ old('subject', \Illuminate\Support\Str::startsWith(strtolower($message['subject']), 're:') ? $message['subject'] : 'Re: '.$message['subject']) }}"
                               class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Jawab</label>
                        <textarea name="body" rows="7" required placeholder="Write your reply..."
                                  class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2">{{ old('body') }}</textarea>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <input type="file" name="attachment" class="text-xs text-gray-400" />
                        <button type="submit" class="px-5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">Send Reply</button>
                    </div>
                    @error('to') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
                    @error('subject') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
                    @error('body') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
                </form>
            </div>
        </div>
    @endif
</div>
</x-admin-layout>
