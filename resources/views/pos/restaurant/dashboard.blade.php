<x-pos-layout>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
.exe-card { background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(255,255,255,0.85)); backdrop-filter: blur(20px); border: 1px solid rgba(0,0,0,0.06); }
.dark .exe-card { background: linear-gradient(135deg, rgba(17,24,39,0.95), rgba(17,24,39,0.85)); border: 1px solid rgba(255,255,255,0.06); }
.exe-stat { position: relative; overflow: hidden; }
.exe-stat::before { content: ''; position: absolute; top: -50%; right: -50%; width: 100%; height: 100%; background: radial-gradient(circle, rgba(124,58,237,0.04) 0%, transparent 70%); }
.dark .exe-stat::before { background: radial-gradient(circle, rgba(124,58,237,0.08) 0%, transparent 70%); }
.exe-title-bar { background: linear-gradient(90deg, #1e1b4b 0%, #312e81 50%, #4c1d95 100%); }
.exe-sidebar-item { transition: all 0.15s ease; }
.exe-sidebar-item:hover, .exe-sidebar-item.active { background: rgba(124,58,237,0.12); }
.exe-sidebar-item.active { border-left: 3px solid #7c3aed; }
.glow-green { box-shadow: 0 0 8px rgba(16,185,129,0.4); }
.glow-red { box-shadow: 0 0 8px rgba(239,68,68,0.4); }
.stat-value { font-variant-numeric: tabular-nums; letter-spacing: -0.02em; }
.progress-bar { transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1); }
@keyframes slideInRight { from { opacity: 0; transform: translateX(10px); } to { opacity: 1; transform: translateX(0); } }
.slide-in { animation: slideInRight 0.3s ease forwards; }
.exe-table tr:hover td { background: rgba(124,58,237,0.03); }
.dark .exe-table tr:hover td { background: rgba(124,58,237,0.08); }
</style>

<div class="h-screen flex flex-col bg-slate-100 dark:bg-gray-950" x-data="rDash()" x-init="init()">

    <div class="exe-title-bar flex items-center justify-between px-4 py-2 flex-shrink-0">
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-lg bg-purple-500/30 flex items-center justify-center">
                    <svg class="w-4 h-4 text-purple-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <div>
                    <h1 class="text-sm font-extrabold text-white tracking-tight">NestPOS</h1>
                    <p class="text-[9px] text-purple-300/70 -mt-0.5">Restaurant Management System</p>
                </div>
            </div>
            <div class="h-5 w-px bg-purple-400/20 mx-2 hidden sm:block"></div>
            <div class="hidden sm:flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-green-400 glow-green"></span>
                <span class="text-[10px] text-green-300 font-medium">System Online</span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-[10px] text-purple-300/60 font-mono hidden md:block">{{ now()->format('D, d M Y — H:i') }}</span>
            <div class="h-5 w-px bg-purple-400/20 mx-1 hidden md:block"></div>
            <button @click="refreshDashboard()" class="p-1.5 rounded-lg text-purple-300 hover:text-white hover:bg-purple-500/20 transition" title="Refresh (F5)">
                <svg class="w-3.5 h-3.5" :class="refreshing ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </button>
            <a href="{{ route('pos.restaurant.pos') }}" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold text-white bg-purple-600 hover:bg-purple-500 shadow-lg shadow-purple-600/30 transition">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                POS Screen
            </a>
            <a href="{{ route('pos.restaurant.kds') }}" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold text-orange-200 bg-orange-600/30 hover:bg-orange-600/50 border border-orange-500/30 transition">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                KDS
            </a>
        </div>
    </div>

    <div class="flex-1 flex overflow-hidden">

        <div class="hidden lg:flex w-48 flex-col bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 flex-shrink-0">
            <div class="p-3 border-b border-gray-100 dark:border-gray-800">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                        <span class="text-sm font-bold text-purple-700 dark:text-purple-400">{{ substr(auth('pos')->user()->name ?? 'A', 0, 1) }}</span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-gray-900 dark:text-white truncate">{{ auth('pos')->user()->name ?? 'Admin' }}</p>
                        <p class="text-[9px] text-gray-400 capitalize">{{ auth('pos')->user()->pos_role ?? 'admin' }}</p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 py-2 space-y-0.5 px-1.5">
                <a href="{{ route('pos.restaurant.dashboard') }}" class="exe-sidebar-item active flex items-center gap-2 px-2.5 py-2 rounded-lg text-xs font-semibold text-purple-700 dark:text-purple-400">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    Dashboard
                </a>
                <a href="{{ route('pos.restaurant.pos') }}" class="exe-sidebar-item flex items-center gap-2 px-2.5 py-2 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-400">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    POS Screen
                </a>
                <a href="{{ route('pos.restaurant.orders') }}" class="exe-sidebar-item flex items-center gap-2 px-2.5 py-2 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-400">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    Orders
                </a>
                <a href="{{ route('pos.restaurant.tables') }}" class="exe-sidebar-item flex items-center gap-2 px-2.5 py-2 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-400">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    Tables
                </a>
                <a href="{{ route('pos.restaurant.kds') }}" class="exe-sidebar-item flex items-center gap-2 px-2.5 py-2 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-400">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Kitchen Display
                </a>
                <a href="{{ route('pos.restaurant.menu') }}" class="exe-sidebar-item flex items-center gap-2 px-2.5 py-2 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-400">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    Menu Items
                </a>
                <a href="{{ route('pos.restaurant.ingredients') }}" class="exe-sidebar-item flex items-center gap-2 px-2.5 py-2 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-400">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    Inventory
                </a>
            </nav>
            <div class="p-3 border-t border-gray-100 dark:border-gray-800">
                <div class="flex items-center gap-2 text-[10px] text-gray-400">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-400"></span>
                    v2.0 Enterprise
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-3 sm:p-4">

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <div class="exe-card exe-stat rounded-xl p-3.5 shadow-sm slide-in" style="animation-delay: 0s">
                    <div class="flex items-center justify-between mb-2">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-green-400 to-emerald-600 flex items-center justify-center shadow-md shadow-green-600/20">
                            <svg class="w-4.5 h-4.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        @if($yesterdaySales > 0)
                        @php $changePercent = round(($todaySales - $yesterdaySales) / $yesterdaySales * 100); @endphp
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full {{ $changePercent >= 0 ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400' }}">{{ $changePercent >= 0 ? '+' : '' }}{{ $changePercent }}%</span>
                        @endif
                    </div>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Today's Revenue</p>
                    <p class="text-xl font-extrabold text-gray-900 dark:text-white stat-value mt-0.5">Rs. {{ number_format($todaySales) }}</p>
                </div>

                <div class="exe-card exe-stat rounded-xl p-3.5 shadow-sm slide-in" style="animation-delay: 0.05s">
                    <div class="flex items-center justify-between mb-2">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-400 to-indigo-600 flex items-center justify-center shadow-md shadow-blue-600/20">
                            <svg class="w-4.5 h-4.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </div>
                        <div class="flex gap-1">
                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">{{ $heldCount }} active</span>
                        </div>
                    </div>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Total Orders</p>
                    <p class="text-xl font-extrabold text-gray-900 dark:text-white stat-value mt-0.5">{{ $todayOrders }}</p>
                </div>

                <div class="exe-card exe-stat rounded-xl p-3.5 shadow-sm slide-in" style="animation-delay: 0.1s">
                    <div class="flex items-center justify-between mb-2">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-400 to-violet-600 flex items-center justify-center shadow-md shadow-purple-600/20">
                            <svg class="w-4.5 h-4.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </div>
                        @if($peakHour)
                        <span class="text-[9px] font-medium px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">Peak: {{ $peakHour }}</span>
                        @endif
                    </div>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Avg. Order</p>
                    <p class="text-xl font-extrabold text-gray-900 dark:text-white stat-value mt-0.5">Rs. {{ $todayOrders > 0 ? number_format($todaySales / $todayOrders) : 0 }}</p>
                </div>

                <div class="exe-card exe-stat rounded-xl p-3.5 shadow-sm slide-in" style="animation-delay: 0.15s">
                    <div class="flex items-center justify-between mb-2">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-orange-600 flex items-center justify-center shadow-md shadow-amber-600/20">
                            <svg class="w-4.5 h-4.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </div>
                    </div>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Tables</p>
                    <p class="text-xl font-extrabold text-gray-900 dark:text-white stat-value mt-0.5">{{ $occupiedTables }}<span class="text-sm text-gray-400 font-medium">/{{ $totalTables }}</span></p>
                    <div class="mt-1.5 w-full bg-gray-100 dark:bg-gray-800 rounded-full h-1.5">
                        <div class="bg-gradient-to-r from-amber-400 to-orange-500 h-1.5 rounded-full progress-bar" style="width: {{ $totalTables > 0 ? round($occupiedTables / $totalTables * 100) : 0 }}%"></div>
                    </div>
                </div>
            </div>

            @if(auth('pos')->user() && auth('pos')->user()->pos_role === 'pos_admin')
            <div class="grid grid-cols-3 gap-3 mb-4">
                <div class="exe-card rounded-xl p-3.5 shadow-sm border-l-4 border-l-emerald-500">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Gross Profit</p>
                            <p class="text-lg font-extrabold stat-value mt-0.5 {{ ($todayProfit ?? 0) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600' }}">Rs. {{ number_format($todayProfit ?? 0) }}</p>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        </div>
                    </div>
                </div>
                <div class="exe-card rounded-xl p-3.5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Total Cost</p>
                            <p class="text-lg font-extrabold text-gray-900 dark:text-white stat-value mt-0.5">Rs. {{ number_format($todayCost ?? 0) }}</p>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-gray-50 dark:bg-gray-800 flex items-center justify-center">
                            <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                        </div>
                    </div>
                </div>
                <div class="exe-card rounded-xl p-3.5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider">Margin</p>
                            <p class="text-lg font-extrabold stat-value mt-0.5 {{ ($todayProfit ?? 0) >= 0 ? 'text-indigo-700 dark:text-indigo-400' : 'text-red-600' }}">{{ $todaySales > 0 ? round(($todayProfit ?? 0) / $todaySales * 100) : 0 }}%</p>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/></svg>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <div class="exe-card rounded-xl p-3 shadow-sm flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-cyan-50 dark:bg-cyan-900/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                    </div>
                    <div>
                        <p class="text-[9px] text-gray-400 font-medium uppercase">Tax</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-white stat-value">Rs. {{ number_format($todayTax) }}</p>
                    </div>
                </div>
                <div class="exe-card rounded-xl p-3 shadow-sm flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </div>
                    <div>
                        <p class="text-[9px] text-gray-400 font-medium uppercase">Discounts</p>
                        <p class="text-sm font-bold text-orange-600 stat-value">Rs. {{ number_format($todayDiscount) }}</p>
                    </div>
                </div>
                <div class="exe-card rounded-xl p-3 shadow-sm flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-green-50 dark:bg-green-900/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <p class="text-[9px] text-gray-400 font-medium uppercase">Completed</p>
                        <p class="text-sm font-bold text-green-600 stat-value">{{ $completedCount }}</p>
                    </div>
                </div>
                <div class="exe-card rounded-xl p-3 shadow-sm flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg {{ $lowStockItems->count() > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 {{ $lowStockItems->count() > 0 ? 'text-red-600' : 'text-green-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    </div>
                    <div>
                        <p class="text-[9px] text-gray-400 font-medium uppercase">Low Stock</p>
                        <p class="text-sm font-bold {{ $lowStockItems->count() > 0 ? 'text-red-600' : 'text-green-600' }} stat-value">{{ $lowStockItems->count() }}</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
                <div class="lg:col-span-2 exe-card rounded-xl p-4 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-xs font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <span class="w-1.5 h-4 rounded-full bg-purple-600"></span>
                            Revenue — Last 7 Days
                        </h2>
                    </div>
                    <div style="height: 180px;"><canvas id="salesChart"></canvas></div>
                </div>

                <div class="exe-card rounded-xl p-4 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-xs font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <span class="w-1.5 h-4 rounded-full bg-blue-600"></span>
                            Order Distribution
                        </h2>
                    </div>
                    <div style="height: 180px;"><canvas id="orderTypeChart"></canvas></div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-4">
                <div class="exe-card rounded-xl shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2">
                        <span class="w-1.5 h-4 rounded-full bg-amber-500"></span>
                        <h2 class="text-xs font-bold text-gray-900 dark:text-white">Top Selling Items</h2>
                    </div>
                    <div class="p-3 space-y-0.5">
                        @forelse($topProducts->take(5) as $idx => $p)
                        <div class="flex items-center gap-2.5 py-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                            <span class="w-6 h-6 rounded-lg flex items-center justify-center text-[10px] font-extrabold {{ $idx < 3 ? 'bg-gradient-to-br from-purple-500 to-violet-600 text-white' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">{{ $idx + 1 }}</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $p->item_name }}</p>
                            </div>
                            <span class="text-[10px] text-gray-400 bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded font-mono">{{ $p->total_qty }}x</span>
                            <span class="text-xs font-bold text-gray-900 dark:text-white stat-value">Rs. {{ number_format($p->total_revenue) }}</span>
                        </div>
                        @empty
                        <p class="text-xs text-gray-400 py-6 text-center">No sales data yet</p>
                        @endforelse
                    </div>
                </div>

                <div class="exe-card rounded-xl shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-4 rounded-full {{ $lowStockItems->count() > 0 ? 'bg-red-500' : 'bg-green-500' }}"></span>
                            <h2 class="text-xs font-bold text-gray-900 dark:text-white">Stock Alerts</h2>
                        </div>
                        @if($lowStockItems->count() > 0)
                        <span class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">{{ $lowStockItems->count() }} items</span>
                        @endif
                    </div>
                    <div class="p-3 space-y-0.5">
                        @forelse($lowStockItems->take(5) as $ing)
                        <div class="flex items-center gap-2.5 py-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                            <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $ing->current_stock <= 0 ? 'bg-red-500 glow-red' : 'bg-amber-500' }}"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $ing->name }}</p>
                            </div>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-lg {{ $ing->current_stock <= 0 ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">
                                {{ number_format($ing->current_stock, 1) }} {{ $ing->unit }}
                            </span>
                        </div>
                        @empty
                        <div class="text-center py-6">
                            <svg class="w-8 h-8 mx-auto text-green-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <p class="text-xs text-green-600 font-semibold">All ingredients in stock</p>
                        </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="exe-card rounded-xl shadow-sm overflow-hidden mb-4">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-4 rounded-full bg-indigo-500"></span>
                        <h2 class="text-xs font-bold text-gray-900 dark:text-white">Recent Transactions</h2>
                    </div>
                    <a href="{{ route('pos.restaurant.orders') }}" class="text-[10px] text-purple-600 hover:text-purple-700 font-semibold">View All</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs exe-table">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-800/50">
                                <th class="text-left text-[10px] text-gray-500 font-semibold uppercase tracking-wider py-2.5 px-4">Order #</th>
                                <th class="text-left text-[10px] text-gray-500 font-semibold uppercase tracking-wider py-2.5 px-4 hidden sm:table-cell">Type</th>
                                <th class="text-left text-[10px] text-gray-500 font-semibold uppercase tracking-wider py-2.5 px-4">Table</th>
                                <th class="text-right text-[10px] text-gray-500 font-semibold uppercase tracking-wider py-2.5 px-4">Amount</th>
                                <th class="text-center text-[10px] text-gray-500 font-semibold uppercase tracking-wider py-2.5 px-4">Status</th>
                                <th class="text-right text-[10px] text-gray-500 font-semibold uppercase tracking-wider py-2.5 px-4 hidden lg:table-cell">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentOrders->take(8) as $ro)
                            <tr class="border-b border-gray-50 dark:border-gray-800/50">
                                <td class="py-2.5 px-4 font-bold text-gray-900 dark:text-white">{{ $ro->order_number }}</td>
                                <td class="py-2.5 px-4 hidden sm:table-cell"><span class="text-[10px] px-2 py-0.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 capitalize font-medium">{{ str_replace('_', ' ', $ro->order_type) }}</span></td>
                                <td class="py-2.5 px-4 text-gray-600 dark:text-gray-400">{{ $ro->table ? 'T-' . $ro->table->table_number : '—' }}</td>
                                <td class="py-2.5 px-4 text-right font-bold text-gray-900 dark:text-white stat-value">Rs. {{ number_format($ro->total_amount) }}</td>
                                <td class="py-2.5 px-4 text-center">
                                    <span class="text-[9px] px-2 py-1 rounded-lg font-bold inline-block
                                        {{ $ro->status === 'completed' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : '' }}
                                        {{ $ro->status === 'held' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : '' }}
                                        {{ $ro->status === 'preparing' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : '' }}
                                        {{ $ro->status === 'ready' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : '' }}
                                        {{ $ro->status === 'cancelled' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : '' }}
                                    ">{{ ucfirst($ro->status) }}</span>
                                </td>
                                <td class="py-2.5 px-4 text-right text-gray-400 hidden lg:table-cell text-[10px]">{{ $ro->created_at->diffForHumans() }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="py-8 text-center text-gray-400 text-xs">No orders today</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if(auth('pos')->user() && auth('pos')->user()->pos_role === 'pos_admin')
            <div x-data="{ showSettings: false, mgrPin: '', cashierLimit: {{ $company->cashier_discount_limit ?? 10 }}, managerLimit: {{ $company->manager_discount_limit ?? 50 }}, saving: false, saved: false }" class="exe-card rounded-xl shadow-sm overflow-hidden mb-4">
                <button @click="showSettings = !showSettings" class="flex items-center justify-between w-full px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition">
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-4 rounded-full bg-gray-400"></span>
                        <h2 class="text-xs font-bold text-gray-900 dark:text-white">Role & Discount Settings</h2>
                    </div>
                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="showSettings ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="showSettings" x-transition class="px-4 pb-4 space-y-4 border-t border-gray-100 dark:border-gray-800 pt-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider block mb-1.5">Cashier Discount Limit (%)</label>
                            <input type="number" x-model.number="cashierLimit" min="0" max="100" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider block mb-1.5">Manager Discount Limit (%)</label>
                            <input type="number" x-model.number="managerLimit" min="0" max="100" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider block mb-1.5">Manager Override PIN</label>
                            <input type="password" x-model="mgrPin" maxlength="6" placeholder="{{ $company->manager_override_pin ? '******' : 'Set 4-6 digit PIN' }}" class="w-full text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition">
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button @click="async function() { saving = true; saved = false; try { const res = await fetch('{{ route('pos.restaurant.save-manager-pin') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ pin: mgrPin || undefined, cashier_discount_limit: cashierLimit, manager_discount_limit: managerLimit }) }); const d = await res.json(); if (d.success) { saved = true; mgrPin = ''; setTimeout(() => saved = false, 3000); } } catch(e) {} saving = false; }()" :disabled="saving" class="px-5 py-2.5 text-xs font-bold text-white bg-purple-600 rounded-xl hover:bg-purple-700 disabled:opacity-50 shadow-md shadow-purple-600/20 transition">
                            <span x-text="saving ? 'Saving...' : 'Save Settings'"></span>
                        </button>
                        <span x-show="saved" x-transition class="text-xs text-green-600 font-semibold">Settings saved!</span>
                    </div>
                </div>
            </div>
            @endif

            <div class="text-center py-2">
                <p class="text-[9px] text-gray-300 dark:text-gray-700">NestPOS Enterprise v2.0 — Restaurant Management System</p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
function rDash() {
    return {
        refreshing: false,
        autoRefreshTimer: null,
        init() {
            this.$nextTick(() => {
                this.renderSalesChart();
                this.renderOrderTypeChart();
            });
            this.autoRefreshTimer = setInterval(() => this.refreshDashboard(), 120000);
        },
        async refreshDashboard() {
            this.refreshing = true;
            try { window.location.reload(); } catch(e) {}
        },
        renderSalesChart() {
            const ctx = document.getElementById('salesChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: @json($salesChartLabels),
                    datasets: [{
                        label: 'Revenue (Rs.)',
                        data: @json($salesChartData),
                        backgroundColor: function(context) {
                            const chart = context.chart;
                            const {ctx: c, chartArea} = chart;
                            if (!chartArea) return 'rgba(124, 58, 237, 0.15)';
                            const gradient = c.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                            gradient.addColorStop(0, 'rgba(124, 58, 237, 0.05)');
                            gradient.addColorStop(1, 'rgba(124, 58, 237, 0.25)');
                            return gradient;
                        },
                        borderColor: 'rgba(124, 58, 237, 0.8)',
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 11, weight: '600' }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8, displayColors: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false }, ticks: { font: { size: 9, weight: '500' }, padding: 8 }, border: { display: false } },
                        x: { grid: { display: false, drawBorder: false }, ticks: { font: { size: 9, weight: '500' }, padding: 4 }, border: { display: false } }
                    }
                }
            });
        },
        renderOrderTypeChart() {
            const ctx = document.getElementById('orderTypeChart');
            if (!ctx) return;
            const data = @json($orderTypeCounts);
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(data).map(k => k.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())),
                    datasets: [{
                        data: Object.values(data),
                        backgroundColor: ['#7c3aed', '#3b82f6', '#f59e0b', '#10b981'],
                        borderWidth: 0,
                        spacing: 3,
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, pointStyle: 'circle', font: { size: 10, weight: '500' } } },
                        tooltip: { backgroundColor: '#1e1b4b', titleFont: { size: 11, weight: '600' }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8, displayColors: true }
                    }
                }
            });
        },
    };
}
</script>
</x-pos-layout>
