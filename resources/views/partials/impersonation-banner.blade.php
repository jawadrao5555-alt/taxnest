@php($__imp = session('impersonation'))
@if(is_array($__imp))
    @php($__full = empty($__imp['readonly']))
    <div style="position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:2147483000;max-width:calc(100vw - 16px);">
        <div class="flex items-center gap-2 sm:gap-3 rounded-full shadow-xl px-3 sm:px-4 py-1.5 border {{ $__full ? 'bg-red-600 text-white border-red-700' : 'bg-amber-500 text-black border-amber-600' }}">
            @if($__full)
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <span class="text-xs sm:text-sm font-semibold truncate">
                    FULL ACCESS: <strong>{{ $__imp['company_name'] ?? 'company' }}</strong> — changes are LIVE
                </span>
                <form method="POST" action="{{ route('saas.admin.impersonation.lock') }}">
                    @csrf
                    <button type="submit" class="whitespace-nowrap px-2.5 sm:px-3 py-1 rounded-full bg-white/20 hover:bg-white/30 text-white text-[11px] sm:text-xs font-bold transition">
                        Lock to view-only
                    </button>
                </form>
            @else
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                <span class="text-xs sm:text-sm font-semibold truncate">
                    View-only: <strong>{{ $__imp['company_name'] ?? 'company' }}</strong>
                </span>
            @endif
            <form method="POST" action="{{ route('saas.admin.impersonation.stop') }}">
                @csrf
                <button type="submit" class="whitespace-nowrap px-2.5 sm:px-3 py-1 rounded-full bg-black text-white text-[11px] sm:text-xs font-bold hover:bg-gray-900 transition">
                    Exit
                </button>
            </form>
        </div>
    </div>
@endif
