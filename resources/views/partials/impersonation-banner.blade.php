{{--
    Admin "View as / Manage as company" marker — an INLINE chip that lives in the
    panel's own top bar.

    It used to be a pill fixed to the middle of the screen at the highest
    z-index there is, which put it on top of the nav it was floating over: the
    buttons underneath could not be pressed, and modals and dropdowns opened
    behind it. Sitting inside the bar, it takes its own space, covers nothing,
    and still travels with the admin on every page.

    Exit stays here. It is deliberately the ONLY way out of impersonation
    (login/logout are blocked while impersonating), so this chip must render on
    every layout that can be impersonated into, at every screen width — hence
    the label collapses on small screens but the buttons never do.
--}}
@php($__imp = session('impersonation'))
@if(is_array($__imp))
    @php($__full = empty($__imp['readonly']))
    @php($__onDark = ($onDark ?? false))
    <div class="flex flex-shrink-0 items-center gap-1.5 rounded-full border px-2 py-0.5 max-w-[45vw] {{ $__full ? 'bg-red-600 text-white border-red-700' : 'bg-amber-400 text-black border-amber-500' }}">
        @if($__full)
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <span class="hidden md:inline text-[11px] font-bold truncate">
                LIVE: {{ $__imp['company_name'] ?? 'company' }}
            </span>
            <form method="POST" action="{{ route('saas.admin.impersonation.lock') }}">
                @csrf
                <button type="submit" title="Lock to view-only"
                        class="whitespace-nowrap px-2 py-0.5 rounded-full bg-white/25 hover:bg-white/40 text-white text-[10px] font-bold transition">
                    <span class="hidden lg:inline">Lock to view-only</span>
                    <span class="lg:hidden">Lock</span>
                </button>
            </form>
        @else
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
            <span class="hidden md:inline text-[11px] font-bold truncate">
                {{ $__imp['company_name'] ?? 'company' }}
            </span>
        @endif
        <form method="POST" action="{{ route('saas.admin.impersonation.stop') }}">
            @csrf
            <button type="submit" title="Exit impersonation"
                    class="whitespace-nowrap px-2 py-0.5 rounded-full bg-black text-white text-[10px] font-bold hover:bg-gray-900 transition">
                Exit
            </button>
        </form>
    </div>
@endif
