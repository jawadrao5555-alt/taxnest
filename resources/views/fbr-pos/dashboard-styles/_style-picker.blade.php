<div class="relative" x-data="{ styleOpen: false, currentStyle: '{{ $dashboardStyle ?? 'default' }}' }">
    <button @click="styleOpen = !styleOpen" @click.away="styleOpen = false" class="p-2 rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 hover:text-blue-600 transition shadow-sm" title="Dashboard Style">
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
            <button @click="currentStyle='{{ $s['id'] }}'; styleOpen=false; fetch('{{ route('fbrpos.settings.dashboard-style') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({style:'{{ $s['id'] }}'})}).then(()=>window.location.reload())" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all" :class="currentStyle === '{{ $s['id'] }}' ? 'bg-blue-50 dark:bg-blue-900/20 ring-2 ring-blue-500' : 'hover:bg-gray-50 dark:hover:bg-gray-800'">
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
                <span x-show="currentStyle === '{{ $s['id'] }}'" class="text-blue-600 flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </span>
            </button>
            @endforeach
        </div>
    </div>
</div>
