<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Local Bills' }} — Nest Local</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        /* JNN first: its @font-face (urdu-font partial below) is unicode-range
           limited to the Arabic blocks and only exists for locale 'ur', so Latin
           text always falls through to the system stack. */
        body { font-family: 'Jameel Noori Nastaleeq', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .local-grad { background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%); }
        .accent { color: #a78bfa; }
        .accent-bg { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
    </style>
    {{-- Urdu-script UI font — renders only for locale 'ur' (Task 1287). --}}
    @include('partials.urdu-font')
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="local-grad min-h-screen">
    <nav class="border-b border-slate-800/60 bg-slate-900/60 backdrop-blur-md sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg accent-bg flex items-center justify-center font-bold text-white text-lg shadow-lg">🧾</div>
                <div>
                    <div class="font-bold text-base leading-tight">Nest Local</div>
                    <div class="text-[10px] uppercase tracking-widest text-slate-400">Local Bills Portal</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                {{-- Which branch's bills are on screen (Task 1361). Renders
                     nothing for a single-branch shop. --}}
                <x-branch-switcher color="purple" :allow-all="true" :show-manage="false" />
                <a href="{{ route('pos.local.index') }}" class="text-sm text-slate-300 hover:text-white px-3 py-1.5 rounded-md hover:bg-slate-800/50 transition">Local Bills</a>
                <a href="{{ route('pos.local.export') }}" class="text-sm text-slate-300 hover:text-white px-3 py-1.5 rounded-md hover:bg-slate-800/50 transition">Export CSV</a>
                @if(auth('pos')->user()?->isPosAdmin())
                <a href="{{ route('pos.dashboard') }}" class="text-sm text-slate-300 hover:text-white px-3 py-1.5 rounded-md hover:bg-slate-800/50 transition">← Back to POS</a>
                @endif
                <div class="text-xs text-slate-400 hidden sm:block px-3 border-l border-slate-800">{{ auth('pos')->user()->name ?? 'Viewer' }}</div>
                <form method="POST" action="{{ route('pos.logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="text-xs px-3 py-1.5 rounded-md bg-red-900/40 text-red-300 hover:bg-red-900/60 transition">Logout</button>
                </form>
            </div>
        </div>
    </nav>

    @if(session('success'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-4">
            <div class="bg-emerald-900/30 border border-emerald-700/50 text-emerald-200 px-4 py-2 rounded-lg text-sm">{{ session('success') }}</div>
        </div>
    @endif
    @if(session('error'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-4">
            <div class="bg-red-900/30 border border-red-700/50 text-red-200 px-4 py-2 rounded-lg text-sm">{{ session('error') }}</div>
        </div>
    @endif

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-800/60 mt-12 py-4 text-center text-xs text-slate-500">
        Nest Local · Read-only local bills portal · {{ now()->format('Y') }}
    </footer>
</div>
</body>
</html>
