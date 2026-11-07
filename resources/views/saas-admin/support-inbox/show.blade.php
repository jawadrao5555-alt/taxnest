<x-admin-layout>
<div class="p-4 sm:p-6 max-w-4xl mx-auto" x-data="{ reply: {{ $errors->any() ? 'true' : 'false' }}, lightbox: null }">
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
                <div class="flex flex-wrap gap-3">
                    @foreach($message['attachments'] as $att)
                        @php
                            $isImage = in_array(strtolower($att['mime']), ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
                            $isPdf = strtolower($att['mime']) === 'application/pdf';
                            $downloadUrl = route('saas.admin.support-inbox.attachment', ['box' => $box, 'uid' => $message['uid'], 'index' => $att['index']]);
                            $inlineUrl = $downloadUrl.'?inline=1';
                        @endphp
                        <div class="flex flex-col gap-1">
                            @if($isImage)
                                <button type="button" @click="lightbox = @js($inlineUrl)"
                                        class="block rounded-lg overflow-hidden border border-gray-700 hover:border-emerald-500 focus:outline-none"
                                        title="Preview: {{ $att['name'] }}">
                                    <img src="{{ $inlineUrl }}" alt="{{ $att['name'] }}" loading="lazy"
                                         class="h-24 w-24 object-cover bg-gray-800" />
                                </button>
                            @endif
                            <div class="flex items-center gap-1.5">
                                @if($isPdf)
                                    <a href="{{ $inlineUrl }}" target="_blank" rel="noopener"
                                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800 text-xs text-gray-300 hover:bg-gray-700"
                                       title="PDF preview (new tab)">
                                        &#128462; {{ $att['name'] }} <span class="text-gray-500">({{ number_format($att['size'] / 1024, 1) }} KB)</span>
                                    </a>
                                @else
                                    <a href="{{ $downloadUrl }}"
                                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-800 text-xs text-gray-300 hover:bg-gray-700"
                                       title="Download">
                                        &#128206; {{ $att['name'] }} <span class="text-gray-500">({{ number_format($att['size'] / 1024, 1) }} KB)</span>
                                    </a>
                                @endif
                                @if($isPdf)
                                    <a href="{{ $downloadUrl }}" class="px-2 py-1.5 rounded-lg bg-gray-800 text-xs text-gray-400 hover:bg-gray-700" title="Download">&darr;</a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    @if($box === 'inbox')
        <section class="mt-4 bg-gray-900 border border-gray-800 rounded-xl p-5" aria-labelledby="support-case-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="support-case-heading" class="text-sm font-bold text-white">Company support case</h2>
                    <p class="mt-1 text-xs text-gray-500">Email reply apne aap case resolve nahi karti. Company, owner aur resolution build alag record hotay hain.</p>
                </div>
                @if($supportCase)
                    @php
                        $caseTone = $supportCase->status === 'resolved'
                            ? 'bg-emerald-900/50 text-emerald-300 border-emerald-700'
                            : ($supportCase->status === 'reopened'
                                ? 'bg-amber-900/50 text-amber-300 border-amber-700'
                                : 'bg-sky-900/50 text-sky-300 border-sky-700');
                    @endphp
                    <span class="rounded-full border px-3 py-1 text-xs font-bold {{ $caseTone }}">#{{ $supportCase->id }} · {{ strtoupper($supportCase->status) }}</span>
                @endif
            </div>

            @if(! $supportCase)
                <form method="POST" action="{{ route('saas.admin.support-inbox.case.store', ['box' => $box, 'uid' => $message['uid']]) }}" class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-end">
                    @csrf
                    <div class="flex-1">
                        <label for="support-company" class="block text-xs font-medium text-gray-400 mb-1">Link company</label>
                        <select id="support-company" name="company_id" required class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2">
                            <option value="">Select company…</option>
                            @foreach($supportCompanies as $company)
                                <option value="{{ $company->id }}" @selected((int) old('company_id') === (int) $company->id)>
                                    {{ $company->account_code ? $company->account_code.' · ' : '' }}{{ $company->name }} ({{ strtoupper($company->product_type ?? 'unknown') }})
                                </option>
                            @endforeach
                        </select>
                        @error('company_id') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-sm font-semibold">Open linked case</button>
                </form>
                @if($supportCompanies->isEmpty())
                    <p class="mt-3 text-xs text-amber-300">No active company is available to link.</p>
                @endif
            @else
                <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-xs">
                    <div><dt class="text-gray-500">Company</dt><dd class="mt-0.5 font-semibold text-gray-200">{{ $supportCase->company?->account_code ? $supportCase->company->account_code.' · ' : '' }}{{ $supportCase->company?->name ?? 'Deleted company record' }}</dd></div>
                    <div><dt class="text-gray-500">Owner</dt><dd class="mt-0.5 font-semibold text-gray-200">{{ $supportCase->assignedAdmin?->name ?? 'Unassigned' }}</dd></div>
                    <div><dt class="text-gray-500">Opened</dt><dd class="mt-0.5 text-gray-300">{{ $supportCase->opened_at?->format('d M Y, h:i A') }}</dd></div>
                    <div><dt class="text-gray-500">Last resolution build</dt><dd class="mt-0.5 font-mono text-gray-300">{{ $supportCase->resolution_build ?? 'Not resolved yet' }}</dd></div>
                </dl>

                <form method="POST" action="{{ route('saas.admin.support-inbox.case.assign', $supportCase) }}" class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-end">
                    @csrf
                    <div class="flex-1">
                        <label for="support-owner" class="block text-xs font-medium text-gray-400 mb-1">Case owner</label>
                        <select id="support-owner" name="assigned_admin_id" required class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2">
                            @foreach($supportOwners as $owner)
                                <option value="{{ $owner->id }}" @selected((int) old('assigned_admin_id', $supportCase->assigned_admin_id) === (int) $owner->id)>{{ $owner->name }} · {{ $owner->role }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 text-white text-sm font-semibold">Update owner</button>
                </form>

                @if($supportCase->status !== 'resolved')
                    <form method="POST" action="{{ route('saas.admin.support-inbox.case.resolve', $supportCase) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium text-gray-400 mb-1">Resolution build / release</label>
                            <input name="resolution_build" required maxlength="100" value="{{ old('resolution_build') }}" placeholder="e.g. 1.13.35 or commit SHA" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-400 mb-1">Resolution evidence</label>
                            <textarea name="resolution_note" required maxlength="2000" rows="2" placeholder="What was verified before resolving?" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2">{{ old('resolution_note') }}</textarea>
                        </div>
                        <div class="sm:col-span-2 flex justify-end">
                            <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold">Resolve explicitly</button>
                        </div>
                    </form>
                @else
                    <form method="POST" action="{{ route('saas.admin.support-inbox.case.reopen', $supportCase) }}" class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-end">
                        @csrf
                        <div class="flex-1">
                            <label class="block text-xs font-medium text-gray-400 mb-1">Why is this case being reopened?</label>
                            <textarea name="reopen_note" required maxlength="2000" rows="2" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2">{{ old('reopen_note') }}</textarea>
                        </div>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold">Reopen case</button>
                    </form>
                @endif

                <div class="mt-5 border-t border-gray-800 pt-4">
                    <h3 class="text-xs font-bold uppercase tracking-wide text-gray-400">Immutable timeline</h3>
                    <ol class="mt-3 space-y-3">
                        @foreach($supportCase->events as $event)
                            <li class="border-l-2 border-gray-700 pl-3 text-xs">
                                <div class="flex flex-wrap gap-x-2 text-gray-300">
                                    <strong>{{ ucfirst($event->event_type) }}</strong>
                                    <span>{{ $event->actor?->name ?? 'Former administrator' }}</span>
                                    <time class="text-gray-500">{{ $event->occurred_at?->format('d M Y, h:i A') }}</time>
                                    @if($event->resolution_build)<code class="text-emerald-300">{{ $event->resolution_build }}</code>@endif
                                </div>
                                @if($event->note)<p class="mt-1 whitespace-pre-wrap text-gray-400">{{ $event->note }}</p>@endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif
        </section>
    @endif

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
                        <div>
                            <input type="file" name="attachments[]" multiple class="text-xs text-gray-400" />
                            <p class="text-[11px] text-gray-500 mt-1">You can select multiple files — max 10 MB each.</p>
                        </div>
                        <button type="submit" class="px-5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">Send Reply</button>
                    </div>
                    @error('to') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
                    @error('subject') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
                    @error('body') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
                    @foreach($errors->get('attachments') as $msgs) @foreach((array)$msgs as $m) <p class="text-xs text-red-400">{{ $m }}</p> @endforeach @endforeach
                    @foreach($errors->get('attachments.*') as $msgs) @foreach((array)$msgs as $m) <p class="text-xs text-red-400">{{ $m }}</p> @endforeach @endforeach
                </form>
            </div>
        </div>
    @endif

    <!-- Image lightbox -->
    <div x-show="lightbox" x-cloak @click="lightbox = null" @keydown.escape.window="lightbox = null"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 cursor-zoom-out">
        <img :src="lightbox" class="max-h-[90vh] max-w-[95vw] rounded-lg shadow-2xl object-contain" alt="Attachment preview" />
        <button type="button" @click="lightbox = null"
                class="absolute top-4 right-4 w-10 h-10 rounded-full bg-gray-800/80 text-white text-xl hover:bg-gray-700">&times;</button>
    </div>
</div>
</x-admin-layout>
