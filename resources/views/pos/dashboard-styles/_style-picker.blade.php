<div class="flex items-center gap-2 flex-shrink-0" x-data="{ styleOpen: false, currentStyle: '{{ $dashboardStyle ?? 'default' }}' }">
    <div x-data="{ praEnabled: {{ ($praStatus ?? $company->pra_reporting_enabled ?? false) ? 'true' : 'false' }}, praLoading: false }" class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white/90 dark:bg-gray-800/90 backdrop-blur border border-gray-200 dark:border-gray-700 shadow-sm">
        <span class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">PRA</span>
        <button @click="praLoading=true; fetch('{{ route('pos.api.toggle-pra') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{praEnabled=d.enabled; praLoading=false;})" :class="praEnabled ? 'bg-purple-600' : 'bg-gray-300 dark:bg-gray-600'" class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out" :disabled="praLoading">
            <span :class="praEnabled ? 'translate-x-4' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
        </button>
        <span x-text="praEnabled ? 'ON' : 'OFF'" :class="praEnabled ? 'text-purple-600 font-bold' : 'text-gray-400 font-semibold'" class="text-[10px]"></span>
    </div>
    <div class="relative">
        <button @click.stop="styleOpen = !styleOpen" class="p-2 rounded-xl bg-white/90 dark:bg-gray-800/90 backdrop-blur border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:text-purple-600 dark:hover:text-purple-400 transition shadow-sm" title="Dashboard Style">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
        </button>
        <div x-show="styleOpen" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 scale-95" class="absolute right-0 mt-2 w-72 bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-100 dark:border-gray-800 z-50 p-3" @click.away="styleOpen = false" x-cloak>
            <p class="text-[9px] font-extrabold text-gray-400 uppercase tracking-widest mb-2.5 px-1">Dashboard Design</p>
            <div class="space-y-1">
                @php
                $styles = [
                    ['id' => 'default', 'name' => 'Square Classic', 'desc' => 'Clean & minimal', 'icon' => '◻', 'colors' => ['#f3f4f6','#e5e7eb','#d1d5db']],
                    ['id' => 'toast', 'name' => 'Toast Analytics', 'desc' => 'Data-rich insights', 'icon' => '📊', 'colors' => ['#fbbf24','#f59e0b','#d97706']],
                    ['id' => 'lightspeed', 'name' => 'Lightspeed Grid', 'desc' => 'Colorful tiles', 'icon' => '⚡', 'colors' => ['#8b5cf6','#6366f1','#4f46e5']],
                    ['id' => 'clover', 'name' => 'Clover Insights', 'desc' => 'Card analytics', 'icon' => '🍀', 'colors' => ['#22c55e','#16a34a','#15803d']],
                    ['id' => 'oscar', 'name' => 'Oscar Pakistan', 'desc' => 'Tax compliance', 'icon' => '🇵🇰', 'colors' => ['#0ea5e9','#0284c7','#0369a1']],
                    ['id' => 'shopify', 'name' => 'Shopify Modern', 'desc' => 'Ultra premium', 'icon' => '✨', 'colors' => ['#1e293b','#334155','#475569']],
                ];
                @endphp
                @foreach($styles as $s)
                <button @click="currentStyle='{{ $s['id'] }}'; styleOpen=false; fetch('{{ route('pos.settings.dashboard-style') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({style:'{{ $s['id'] }}'})}).then(()=>window.location.reload())" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all" :class="currentStyle === '{{ $s['id'] }}' ? 'bg-purple-50 dark:bg-purple-900/20 ring-2 ring-purple-500' : 'hover:bg-gray-50 dark:hover:bg-gray-800'">
                    <span class="text-lg w-7 text-center flex-shrink-0">{{ $s['icon'] }}</span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="text-[11px] font-bold text-gray-900 dark:text-white">{{ $s['name'] }}</p>
                        <p class="text-[9px] text-gray-400">{{ $s['desc'] }}</p>
                    </div>
                    <div class="flex gap-0.5 flex-shrink-0">
                        @foreach($s['colors'] as $c)
                        <span class="w-3 h-3 rounded-full" style="background: {{ $c }}"></span>
                        @endforeach
                    </div>
                    <span x-show="currentStyle === '{{ $s['id'] }}'" class="text-purple-600 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    </span>
                </button>
                @endforeach
            </div>
        </div>
    </div>
</div>
