{{-- Loaded after Vite: Tailwind preflight must not turn collection actions into plain text.
     Type selectors leave explicitly themed shared-page buttons' utility classes intact. --}}
<style>
main button, main .button {
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    min-width:44px;min-height:44px;padding:12px 18px;
    border:1px solid #096c73;border-radius:8px;background-color:#096c73;color:#fff;
    font:inherit;font-weight:700;line-height:1.35;text-decoration:none;cursor:pointer;
}
main button:focus-visible, main .button:focus-visible {outline:3px solid #e7b84b;outline-offset:3px}
main [hidden] {display:none!important}
main button:disabled {cursor:not-allowed;opacity:.55}
main .cash-confirm {min-height:44px;cursor:pointer}
main .cash-confirm input {margin-top:3px}
.collection-support-dock {max-width:980px;margin:24px auto;padding:0 20px calc(24px + env(safe-area-inset-bottom, 0px))}
.collection-support-dock > [data-madadgar-placement="inline"] {position:relative;inset:auto;width:fit-content;max-width:100%}
@media(max-width:600px){
    main .actions > button[type="submit"] {width:100%}
    main .actions {align-items:stretch}
}
@media print {.collection-support-dock {display:none!important}}
</style>
