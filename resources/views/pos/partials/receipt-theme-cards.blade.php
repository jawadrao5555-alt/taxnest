{{-- Receipt Theme cards (Task 712) — shared by PRA + FBR receipt-settings.
     Expects an enclosing Alpine scope created via rcptThemePicker() (see
     receipt-theme-preview.blade.php) providing `theme`. Must sit INSIDE the
     settings <form> — it owns the hidden rp_receipt_theme input.
     Vars: $accent = 'purple' (PRA) | 'blue' (FBR).
     Theme definitions: App\Support\PosReceiptThemes (single truth). --}}
@php
    $tnAccent = ($accent ?? 'purple') === 'blue' ? 'blue' : 'purple';
    if ($tnAccent === 'blue') {
        $tnSel  = 'border-blue-500 bg-blue-50/60 dark:bg-blue-900/20';
        $tnIdle = 'border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700';
        $tnTick = 'bg-blue-600';
    } else {
        $tnSel  = 'border-purple-500 bg-purple-50/60 dark:bg-purple-900/20';
        $tnIdle = 'border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700';
        $tnTick = 'bg-purple-600';
    }
@endphp
<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
    <h3 class="text-sm font-bold text-gray-900 dark:text-white">🎨 {{ __('pos.rcpt_theme_section') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('pos.applies_both_receipt_types') }}</span></h3>
    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 mb-4">{{ __('pos.rcpt_theme_section_sub') }}</p>

    <input type="hidden" name="rp_receipt_theme" :value="theme">

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        @foreach(\App\Support\PosReceiptThemes::THEMES as $tnKey => $tnDef)
        <button type="button" @click="theme = '{{ $tnKey }}'"
                :class="theme === '{{ $tnKey }}' ? '{{ $tnSel }}' : '{{ $tnIdle }}'"
                class="relative w-full rounded-xl border-2 p-3 text-left transition">
            <span x-show="theme === '{{ $tnKey }}'" x-cloak
                  class="absolute top-2 right-2 w-5 h-5 rounded-full {{ $tnTick }} text-white flex items-center justify-center">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
            </span>

            {{-- Mini receipt sample — pure inline styles (no build needed) --}}
            <span class="block" style="background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:7px 8px;width:96px;margin:0 auto;">
                @if($tnKey === 'pizza_bold')
                {{-- big centered logo + all-bold lines --}}
                <span style="display:block;width:22px;height:22px;border-radius:50%;background:#111827;margin:0 auto 3px;"></span>
                <span style="display:block;height:5px;background:#111827;border-radius:2px;width:70%;margin:0 auto 3px;"></span>
                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:90%;margin:0 auto 2px;"></span>
                <span style="display:block;border-top:1px dashed #6b7280;margin:4px 0;"></span>
                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:100%;margin-bottom:2px;"></span>
                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:100%;margin-bottom:3px;"></span>
                <span style="display:block;height:5px;background:#111827;border-radius:2px;width:100%;"></span>
                @elseif($tnKey === 'bold_side')
                {{-- name left + small logo right, all-bold lines --}}
                <span style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">
                    <span style="display:block;height:5px;background:#111827;border-radius:2px;width:55%;"></span>
                    <span style="display:block;width:14px;height:14px;border-radius:3px;background:#111827;"></span>
                </span>
                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:90%;margin-bottom:2px;"></span>
                <span style="display:block;border-top:1px dashed #6b7280;margin:4px 0;"></span>
                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:100%;margin-bottom:2px;"></span>
                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:100%;margin-bottom:3px;"></span>
                <span style="display:block;height:5px;background:#111827;border-radius:2px;width:100%;"></span>
                @else
                {{-- saada: light thin lines, only name + total dark --}}
                <span style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">
                    <span style="display:block;height:4px;background:#4b5563;border-radius:2px;width:55%;"></span>
                    <span style="display:block;width:12px;height:12px;border-radius:3px;border:1.5px solid #9ca3af;"></span>
                </span>
                <span style="display:block;height:2px;background:#d1d5db;border-radius:2px;width:90%;margin-bottom:2px;"></span>
                <span style="display:block;border-top:1px dashed #d1d5db;margin:4px 0;"></span>
                <span style="display:block;height:2px;background:#d1d5db;border-radius:2px;width:100%;margin-bottom:2px;"></span>
                <span style="display:block;height:2px;background:#d1d5db;border-radius:2px;width:100%;margin-bottom:3px;"></span>
                <span style="display:block;height:4px;background:#4b5563;border-radius:2px;width:100%;"></span>
                @endif
            </span>

            <span class="block text-sm font-bold text-gray-900 dark:text-white mt-2.5 text-center">{{ __($tnDef['label']) }}</span>
            <span class="block text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 text-center leading-snug">{{ __($tnDef['hint']) }}</span>
        </button>
        @endforeach
    </div>
</div>
