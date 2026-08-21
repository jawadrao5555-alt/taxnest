<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Local Bills Archive' }} — Nest Archive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .archive-grad { background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%); }
        .gold { color: #d4af37; }
        .gold-bg { background: linear-gradient(135deg, #d4af37, #b8941f); }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="archive-grad min-h-screen">
    <nav class="border-b border-slate-800/60 bg-slate-900/60 backdrop-blur-md sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg gold-bg flex items-center justify-center font-bold text-slate-900 text-lg shadow-lg">📦</div>
                <div>
                    <div class="font-bold text-base leading-tight">Nest Archive</div>
                    <div class="text-[10px] uppercase tracking-widest text-slate-400">Local Bills Vault</div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                {{-- Which branch's history is on screen (Task 1361). Renders
                     nothing for a single-branch shop. --}}
                <x-branch-switcher color="blue" :allow-all="true" :show-manage="false" />
                <a href="{{ route('pos.archive.index') }}" class="text-sm text-slate-300 hover:text-white px-3 py-1.5 rounded-md hover:bg-slate-800/50 transition">Archived Bills</a>
                <a href="{{ route('pos.archive.export') }}" class="text-sm text-slate-300 hover:text-white px-3 py-1.5 rounded-md hover:bg-slate-800/50 transition">Export CSV</a>
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
        Nest Archive · Read-only audit portal · {{ now()->format('Y') }}
    </footer>
</div>
</body>
</html>
