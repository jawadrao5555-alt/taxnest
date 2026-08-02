@php($__cc = session('consultant_console'))
@if(is_array($__cc) && auth()->check() && (int) auth()->id() === (int) ($__cc['client_user_id'] ?? 0))
    {{-- Consultant "switched into client" pill — sits below the admin impersonation
         pill slot (top:56px) so the two can never overlap. --}}
    <div style="position:fixed;top:56px;left:50%;transform:translateX(-50%);z-index:2147482999;max-width:calc(100vw - 16px);">
        <div class="flex items-center gap-2 sm:gap-3 rounded-full shadow-xl px-3 sm:px-4 py-1.5 border bg-indigo-600 text-white border-indigo-700">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a4 4 0 11-8 0"/>
            </svg>
            <span class="text-xs sm:text-sm font-semibold truncate">
                Consultant mode: <strong>{{ $__cc['client_company_name'] ?? 'client' }}</strong>
                <span class="hidden sm:inline opacity-80">({{ $__cc['consultant_name'] ?? '' }})</span>
            </span>
            <form method="POST" action="{{ route('consultant.exit') }}">
                @csrf
                <button type="submit" class="whitespace-nowrap px-2.5 sm:px-3 py-1 rounded-full bg-black text-white text-[11px] sm:text-xs font-bold hover:bg-gray-900 transition">
                    Exit client
                </button>
            </form>
        </div>
    </div>
@endif
