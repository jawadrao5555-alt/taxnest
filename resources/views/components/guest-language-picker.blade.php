{{-- Guest language picker for POS / FBR POS login, register (Task: guests pick
     language on first visit). Saves into session (PosLocale::SESSION_KEY) via a
     tiny POST route; SetPosLocale then follows it on all guest pages including
     the root-level forgot/reset-password flow.
     Props: action = POST url, theme = 'purple' (POS dark) | 'blue' (FBR light bg dark hero) --}}
@props(['action', 'theme' => 'purple'])
@php
    $current = app()->getLocale();
    $langs = [
        \App\Support\PosLocale::ENGLISH => 'English',
        \App\Support\PosLocale::ROMAN_URDU => 'Roman Urdu',
        \App\Support\PosLocale::URDU_SCRIPT => 'اردو',
    ];
    // Pill styles per theme: 'purple'/'blue' sit on dark heroes, 'light' on light ones.
    if ($theme === 'blue') {
        $active = 'background: rgba(251,191,36,0.9); color: #1e3a5f; border: 1px solid rgba(251,191,36,1); font-weight: 700;';
        $inactive = 'background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.75); border: 1px solid rgba(255,255,255,0.18);';
        $icon = 'text-white';
    } elseif ($theme === 'light') {
        $active = 'background: #2563eb; color: #fff; border: 1px solid #1d4ed8; font-weight: 700;';
        $inactive = 'background: rgba(255,255,255,0.7); color: #1e3a5f; border: 1px solid rgba(37,99,235,0.25);';
        $icon = 'text-blue-900';
    } else {
        $active = 'background: rgba(139,92,246,0.85); color: #fff; border: 1px solid rgba(167,139,250,0.9); font-weight: 700;';
        $inactive = 'background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.75); border: 1px solid rgba(255,255,255,0.18);';
        $icon = 'text-white';
    }
@endphp
<form method="POST" action="{{ $action }}" class="flex items-center justify-center gap-1.5">
    @csrf
    <svg class="w-3.5 h-3.5 opacity-60 {{ $icon }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
    @foreach($langs as $code => $label)
        <button type="submit" name="language" value="{{ $code }}"
                class="px-2.5 py-1 rounded-full text-[11px] transition"
                style="{{ $current === $code ? $active : $inactive }}"
                @if($code === 'ur') dir="rtl" @endif>{{ $label }}</button>
    @endforeach
</form>
