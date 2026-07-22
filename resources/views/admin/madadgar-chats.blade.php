<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Madadgar Chats (POS AI Bot)</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>
            @endif

            {{-- Settings card --}}
            <div class="mb-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">AI Settings</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Status:
                            @if($botLive)
                                <span class="font-bold text-green-600">LIVE</span>
                            @else
                                <span class="font-bold text-red-600">OFF</span>
                                @if($keySource === 'none') (API key missing) @endif
                            @endif
                            — Key source:
                            @if($keySource === 'admin') Admin panel (encrypted)
                            @elseif($keySource === 'env') Server .env
                            @else <span class="text-red-600">none</span>
                            @endif
                        </p>
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.madadgar-settings') }}" class="px-5 py-4 grid gap-3 sm:grid-cols-3 items-end">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Bot Enabled</label>
                        <select name="enabled" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                            <option value="1" {{ $botEnabled ? 'selected' : '' }}>ON</option>
                            <option value="0" {{ !$botEnabled ? 'selected' : '' }}>OFF (kill switch)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">OpenAI API Key (leave blank to keep current)</label>
                        <input type="password" name="api_key" autocomplete="new-password" placeholder="sk-…"
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        <label class="inline-flex items-center gap-1.5 mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <input type="checkbox" name="clear_key" value="1" class="rounded border-gray-300"> Remove admin key (fall back to .env)
                        </label>
                    </div>
                    <div>
                        <button type="submit" class="px-5 py-2.5 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold transition cursor-pointer">Save Settings</button>
                    </div>
                </form>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                {{-- Sessions list --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">Chat Sessions</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Dekhen customers kya pooch rahe hain — baar baar aane wale sawal = UI behtar karne ka mauqa.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/40 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-2.5">Company / User</th>
                                    <th class="px-4 py-2.5">Msgs</th>
                                    <th class="px-4 py-2.5">Last</th>
                                    <th class="px-4 py-2.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @forelse($sessions as $sess)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30 {{ $activeSession === $sess->session_id ? 'bg-purple-50 dark:bg-purple-900/10' : '' }}">
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-800 dark:text-gray-100">{{ $companies[$sess->company_id] ?? 'Company #' . $sess->company_id }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $users[$sess->user_id] ?? 'User #' . $sess->user_id }}</div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                            {{ $sess->msg_count }}
                                            @if($sess->any_escalation)
                                                <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">ESC</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($sess->last_at)->format('d M, h:i A') }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <a href="{{ route('admin.madadgar-chats', ['session' => $sess->session_id, 'page' => request('page')]) }}"
                                               class="text-xs font-bold text-purple-600 hover:text-purple-800 dark:text-purple-400">View →</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Abhi koi chat nahi hui.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $sessions->links() }}</div>
                </div>

                {{-- Active session transcript --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">Transcript</h3>
                        @if($activeSession && $activeMessages->isNotEmpty())
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                {{ $activeMessages->first()->company->name ?? 'Company #' . $activeMessages->first()->company_id }}
                                — {{ $activeMessages->first()->user->name ?? 'User #' . $activeMessages->first()->user_id }}
                            </p>
                        @endif
                    </div>
                    <div class="p-4 space-y-2 overflow-y-auto" style="max-height: 560px;">
                        @if(!$activeSession)
                            <p class="text-sm text-gray-400 text-center py-8">Session select karein transcript dekhne ke liye.</p>
                        @elseif($activeMessages->isEmpty())
                            <p class="text-sm text-gray-400 text-center py-8">Is session mein koi message nahi.</p>
                        @else
                            @foreach($activeMessages as $m)
                                <div class="flex {{ $m->role === 'user' ? 'justify-end' : 'justify-start' }}">
                                    <div class="px-3.5 py-2.5 rounded-2xl text-sm leading-relaxed whitespace-pre-wrap break-words max-w-md
                                        {{ $m->role === 'user'
                                            ? 'bg-purple-600 text-white rounded-br-md'
                                            : 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-100 rounded-bl-md' }}">
                                        {{ $m->content }}
                                        @if($m->escalation_id)
                                            <div class="mt-1 text-[11px] font-bold {{ $m->role === 'user' ? 'text-purple-200' : 'text-amber-600 dark:text-amber-400' }}">→ Suggestion #{{ $m->escalation_id }}</div>
                                        @endif
                                    </div>
                                </div>
                                <div class="text-[10px] text-gray-400 {{ $m->role === 'user' ? 'text-right' : '' }} px-1">{{ $m->created_at->format('d M, h:i A') }}</div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
