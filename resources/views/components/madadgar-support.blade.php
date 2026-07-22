{{-- Madadgar unified support bubble (owner request 22 Jul 2026): one floating
     button -> panel with AI chat (all POS roles, incl. cashiers) + WhatsApp.
     POS (pos-app) layout ONLY — other layouts keep <x-whatsapp-support />.
     @keydown.stop on root: sale-screen F-key/Escape document listeners must
     never fire while typing in the chat (architect-mandated). --}}
@php
    $mWaNumber = preg_replace('/\D/', '', (string) \App\Models\SystemSetting::get('support_whatsapp_number', ''));
    $mAiEnabled = \App\Services\MadadgarService::enabled();
@endphp

@if($mAiEnabled || $mWaNumber)
<div x-data="tnMadadgar({{ $mAiEnabled ? 'true' : 'false' }}, @js($mWaNumber))"
     @keydown.stop @keydown.escape.prevent="open = false"
     class="fixed z-[60] bottom-5 left-5"
     style="padding-bottom: env(safe-area-inset-bottom, 0px);">

    {{-- Panel --}}
    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
         class="mb-3 bg-white rounded-2xl shadow-xl border border-gray-200 overflow-hidden flex flex-col"
         style="width: min(92vw, 360px); max-height: min(75vh, 560px);">

        {{-- Header --}}
        <div class="px-4 py-3 bg-purple-700 flex items-center justify-between">
            <div class="flex items-center gap-2 min-w-0">
                <div class="w-8 h-8 rounded-full bg-purple-600 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4.5 h-4.5 text-white" style="width:18px;height:18px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                </div>
                <div class="min-w-0">
                    <div class="text-white font-bold text-sm leading-tight">Madadgar</div>
                    <div class="text-purple-200 text-xs leading-tight" x-text="view === 'chat' ? 'NestPOS support — Roman Urdu mein poochein' : 'Hum aap ki madad ke liye hazir hain'"></div>
                </div>
            </div>
            <button @click="open = false" class="p-1.5 rounded-lg text-purple-200 hover:bg-purple-600 hover:text-white transition cursor-pointer" aria-label="Band karein">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- MENU VIEW: two options --}}
        <div x-show="view === 'menu'" class="p-4 space-y-3">
            <template x-if="ai">
                <button @click="startChat()" class="w-full flex items-center gap-3 p-3.5 rounded-xl border-2 border-purple-200 bg-purple-50 hover:border-purple-400 transition text-left cursor-pointer">
                    <div class="w-10 h-10 rounded-full bg-purple-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="font-bold text-sm text-gray-800">Madadgar se poochein</div>
                        <div class="text-xs text-gray-500">Foran jawab — din ho ya raat (AI assistant)</div>
                    </div>
                </button>
            </template>
            <template x-if="wa">
                <a :href="'https://wa.me/' + wa + '?text=' + encodeURIComponent('Assalam o Alaikum, mujhe NestPOS mein madad chahiye.')"
                   target="_blank" rel="noopener"
                   class="w-full flex items-center gap-3 p-3.5 rounded-xl border-2 border-green-200 bg-green-50 hover:border-green-400 transition text-left cursor-pointer">
                    <div class="w-10 h-10 rounded-full bg-[#25D366] flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.515 5.26l-.999 3.648 3.973-1.715zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.521.149-.174.198-.298.298-.497.099-.198.05-.372-.025-.521-.074-.149-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <div class="font-bold text-sm text-gray-800">WhatsApp par rabta karein</div>
                        <div class="text-xs text-gray-500">Support team se seedhi baat</div>
                    </div>
                </a>
            </template>
        </div>

        {{-- CHAT VIEW --}}
        <div x-show="view === 'chat'" x-cloak class="flex flex-col flex-1 min-h-0">
            <div x-ref="mbody" class="flex-1 overflow-y-auto px-3 py-3 space-y-2 bg-gray-50" style="min-height: 220px;">
                <template x-for="(m, i) in messages" :key="i">
                    <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                        <div :class="m.role === 'user'
                                ? 'bg-purple-600 text-white rounded-2xl rounded-br-md'
                                : (m.error ? 'bg-red-50 text-red-700 border border-red-200 rounded-2xl rounded-bl-md' : 'bg-white text-gray-800 border border-gray-200 rounded-2xl rounded-bl-md')"
                             class="px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-wrap break-words shadow-sm"
                             style="max-width: 85%;" x-text="m.content"></div>
                    </div>
                </template>

                {{-- Pending escalation confirm card (row created ONLY on Haan) --}}
                <div x-show="pending" x-cloak class="bg-amber-50 border border-amber-300 rounded-xl p-3.5">
                    <div class="text-xs font-bold text-amber-800 mb-1">Admin team ko bhejein?</div>
                    <div class="text-sm font-semibold text-gray-800" x-text="pending && pending.title"></div>
                    <div class="text-xs text-gray-600 mt-1 whitespace-pre-wrap" x-text="pending && pending.summary"></div>
                    <div class="flex gap-2 mt-3">
                        <button @click="confirmEscalation()" :disabled="busy"
                                class="flex-1 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold transition cursor-pointer disabled:opacity-50">Haan, bhej dein</button>
                        <button @click="rejectEscalation()" :disabled="busy"
                                class="flex-1 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs font-bold transition cursor-pointer disabled:opacity-50">Nahi</button>
                    </div>
                </div>

                <div x-show="busy" x-cloak class="flex justify-start">
                    <div class="bg-white border border-gray-200 rounded-2xl rounded-bl-md px-4 py-2.5 text-sm text-gray-400">likh raha hai…</div>
                </div>
            </div>

            <div class="border-t border-gray-200 bg-white p-2.5">
                <form @submit.prevent="send()" class="flex items-center gap-2">
                    <input x-ref="minput" x-model="draft" type="text" maxlength="1000"
                           placeholder="Apna sawal likhein…"
                           autocomplete="off" name="madadgar_q_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="flex-1 rounded-xl border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                    <button type="submit" :disabled="busy || !draft.trim()"
                            class="w-10 h-10 rounded-xl bg-purple-600 hover:bg-purple-700 text-white flex items-center justify-center transition cursor-pointer disabled:opacity-40 flex-shrink-0" aria-label="Bhejein">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0l-6 6m6-6l6 6"/></svg>
                    </button>
                </form>
                <div class="flex items-center justify-between mt-1.5 px-1">
                    <button @click="newChat()" class="text-xs text-gray-400 hover:text-purple-600 transition cursor-pointer">Nayi chat</button>
                    <button @click="view = 'menu'" class="text-xs text-gray-400 hover:text-purple-600 transition cursor-pointer">Wapas</button>
                    <span class="text-xs text-gray-400" x-show="remaining !== null && remaining <= 5" x-text="'Aaj baqi sawal: ' + remaining"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- Floating button --}}
    <button @click="toggle()" aria-label="Madadgar Support"
            class="flex items-center justify-center w-14 h-14 rounded-full shadow-lg bg-purple-600 hover:bg-purple-700 transition-transform hover:scale-105 active:scale-95 cursor-pointer">
        <svg x-show="!open" class="w-7 h-7 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        <svg x-show="open" x-cloak class="w-7 h-7 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
    </button>
</div>

<script>
function tnMadadgar(aiEnabled, waNumber) {
    return {
        ai: aiEnabled,
        wa: waNumber || '',
        open: false,
        view: 'menu',
        messages: [],
        draft: '',
        busy: false,
        pending: null,
        remaining: null,
        loaded: false,
        sid: null,

        init() {
            try {
                this.sid = localStorage.getItem('tn_madadgar_session') || null;
            } catch (e) { this.sid = null; }
            if (!this.sid) this.resetSid();
        },
        resetSid() {
            this.sid = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() :
                'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                    const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                });
            try { localStorage.setItem('tn_madadgar_session', this.sid); } catch (e) {}
        },
        toggle() {
            this.open = !this.open;
            if (this.open && !this.ai && this.wa) this.view = 'menu';
        },
        startChat() {
            this.view = 'chat';
            if (!this.loaded) this.loadHistory();
            this.$nextTick(() => { this.$refs.minput && this.$refs.minput.focus(); this.scrollDown(); });
        },
        greet() {
            this.messages.push({ role: 'assistant', content: 'Assalam o Alaikum! Main Madadgar hoon — NestPOS ke baare mein jo bhi poochna ho, Roman Urdu mein likhein. Misal: "Day close kaise karun?"' });
        },
        loadHistory() {
            this.loaded = true;
            fetch('/pos/madadgar/history?session_id=' + this.sid, { headers: this.headers(false) })
                .then(r => r.ok ? r.json() : Promise.reject(r))
                .then(d => {
                    this.remaining = d.remaining;
                    if (Array.isArray(d.messages) && d.messages.length) {
                        this.messages = d.messages;
                    } else {
                        this.greet();
                    }
                    this.scrollDown();
                })
                .catch(() => { if (!this.messages.length) this.greet(); });
        },
        send() {
            const q = this.draft.trim();
            if (!q || this.busy) return;
            this.draft = '';
            this.pending = null;
            this.messages.push({ role: 'user', content: q });
            this.busy = true;
            this.scrollDown();
            fetch('/pos/madadgar/message', {
                method: 'POST',
                headers: this.headers(true),
                body: JSON.stringify({ content: q, session_id: this.sid })
            })
                .then(async r => {
                    const d = await r.json().catch(() => ({}));
                    if (!r.ok) throw new Error(d.error || 'Maazrat, masla aa gaya — dobara koshish karein.');
                    return d;
                })
                .then(d => {
                    this.messages.push({ role: 'assistant', content: d.reply });
                    this.pending = d.escalation || null;
                    this.remaining = d.remaining;
                })
                .catch(e => this.messages.push({ role: 'assistant', content: e.message || 'Maazrat, masla aa gaya — dobara koshish karein.', error: true }))
                .finally(() => { this.busy = false; this.scrollDown(); this.$nextTick(() => this.$refs.minput && this.$refs.minput.focus()); });
        },
        confirmEscalation() {
            if (!this.pending || this.busy) return;
            this.busy = true;
            const p = this.pending;
            fetch('/pos/madadgar/escalate', {
                method: 'POST',
                headers: this.headers(true),
                body: JSON.stringify({ title: p.title, summary: p.summary, kind: p.kind, session_id: this.sid })
            })
                .then(async r => {
                    const d = await r.json().catch(() => ({}));
                    if (!r.ok) throw new Error(d.error || 'Bhejne mein masla aa gaya — dobara koshish karein.');
                    return d;
                })
                .then(d => { this.pending = null; this.messages.push({ role: 'assistant', content: d.reply, escalated: true }); })
                .catch(e => this.messages.push({ role: 'assistant', content: e.message, error: true }))
                .finally(() => { this.busy = false; this.scrollDown(); });
        },
        rejectEscalation() {
            this.pending = null;
            this.messages.push({ role: 'assistant', content: 'Theek hai, admin ko nahi bheja. Aur koi sawal ho to poochein!' });
            this.scrollDown();
        },
        newChat() {
            this.resetSid();
            this.messages = [];
            this.pending = null;
            this.greet();
            this.$nextTick(() => this.$refs.minput && this.$refs.minput.focus());
        },
        headers(json) {
            const h = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
            if (json) h['Content-Type'] = 'application/json';
            const t = document.querySelector('meta[name="csrf-token"]');
            if (t) h['X-CSRF-TOKEN'] = t.content;
            return h;
        },
        scrollDown() {
            this.$nextTick(() => { const b = this.$refs.mbody; if (b) b.scrollTop = b.scrollHeight; });
        }
    };
}
</script>
@endif
