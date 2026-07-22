{{-- Owner (22 Jul 2026): the dashboard style DROPDOWN was removed — style choice now
     lives ONLY in Customize POS → "POS Ka Style" (#style). This partial keeps the PRA
     toggle + a plain admin-only link to that section. Do NOT re-add the dropdown here. --}}
<div class="flex items-center gap-3 flex-shrink-0">
    @if(($company->pos_integration_mode ?? 'pra') === 'standalone')
    {{-- Standalone (no-integration) edition: no PRA toggle, no nag — just a calm badge. --}}
    <div class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 shadow-md" title="Standalone POS — no government integration">
        <span class="w-2 h-2 rounded-full" style="background:#0d9488;"></span>
        <span class="text-xs font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">Standalone Mode</span>
    </div>
    @elseif(auth('pos')->user()?->isPosCashier())
    {{-- Owner rule (20 Jul 2026): cashiers get a READ-ONLY badge — the admin assigns
         Online/Offline from /pos/team; togglePra rejects cashier POSTs server-side. --}}
    @php $praAssignedOn = (bool) ($praStatus ?? auth('pos')->user()?->praReportingEnabled($company)); @endphp
    <div class="flex items-center gap-2.5 px-4 py-2 rounded-xl bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 shadow-md" title="Aap ka PRA Reporting status admin ne set kiya hai — change karwane ke liye admin se rabta karein.">
        <span class="text-xs font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">PRA Reporting</span>
        @if($praAssignedOn)
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-xs font-black uppercase tracking-wide">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Online
        </span>
        @else
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-500 dark:text-gray-400 text-xs font-black uppercase tracking-wide">
            <span class="w-2 h-2 rounded-full bg-gray-400"></span> Offline
        </span>
        @endif
    </div>
    @else
    <div x-data="{ praEnabled: {{ ($praStatus ?? auth('pos')->user()?->praReportingEnabled($company) ?? false) ? 'true' : 'false' }}, praLoading: false }" class="flex items-center gap-2.5 px-4 py-2 rounded-xl bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 shadow-md">
        <span class="text-xs font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">PRA Reporting</span>
        <button @click="praLoading=true; fetch('{{ route('pos.api.toggle-pra') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{praEnabled=d.enabled; praLoading=false;})" :class="praEnabled ? 'bg-purple-600' : 'bg-gray-400 dark:bg-gray-500'" class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out" :disabled="praLoading">
            <span :class="praEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
        </button>
        <span x-text="praEnabled ? 'ON' : 'OFF'" :class="praEnabled ? 'text-purple-700 font-black' : 'text-gray-600 font-extrabold'" class="text-xs"></span>
    </div>
    @endif
    @unless(auth('pos')->user()?->isPosCashier())
    <a href="{{ route('pos.customize') }}#style" class="p-2.5 rounded-xl bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition shadow-md" title="Style & Themes — Customize POS">
        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
    </a>
    @endunless
</div>
