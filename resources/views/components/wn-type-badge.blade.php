{{-- What's New update-type badge (Task 1286): 'feature' = Naya Feature (emerald),
     'improvement' = Behtari / Masla Hal (sky). Shared by the bell dropdown and
     the popup on BOTH panels (pos-app + fbr-pos-app). The model accessor
     normalizes legacy/blank/missing-column rows to 'improvement', so this can
     never error. `light` variant = solid chips for the gradient popup header. --}}
@props(['update', 'light' => false])
@php $wnbFeature = ($update->type ?? null) === 'feature'; @endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center flex-shrink-0 px-1.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wide align-middle ' . ($light
    ? ($wnbFeature ? 'bg-emerald-300 text-emerald-900' : 'bg-sky-300 text-sky-900')
    : ($wnbFeature ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300'))]) }}>{{ $wnbFeature ? __('pos.wn_type_feature') : __('pos.wn_type_improvement') }}</span>
