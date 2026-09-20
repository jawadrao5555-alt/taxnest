<style>
    :root {
        --tn-inv-ink: #18383d;
        --tn-inv-muted: #60767a;
        --tn-inv-line: #d7e5e1;
        --tn-inv-canvas: #f4f8f5;
        --tn-inv-surface: #fffdf8;
        --tn-inv-teal: #087f78;
        --tn-inv-gold: #c7952d;
        --tn-inv-purple: #6d49a8;
    }
    .dark {
        --tn-inv-ink: #edf7f2;
        --tn-inv-muted: #a7bbb7;
        --tn-inv-line: #314846;
        --tn-inv-canvas: #182322;
        --tn-inv-surface: #202c2b;
    }
    .tn-inventory-family { color: var(--tn-inv-ink); font-variant-numeric: tabular-nums; }
    .tn-inventory-family .tn-family-header {
        background: linear-gradient(112deg, rgba(8,127,120,.12), rgba(199,149,45,.10) 68%, transparent);
        border: 1px solid var(--tn-inv-line); border-radius: 1.1rem; padding: 1rem 1.1rem;
    }
    .tn-inventory-family .tn-panel, .tn-inventory-family .tn-card {
        background: var(--tn-inv-surface); border: 1px solid var(--tn-inv-line);
        box-shadow: 0 8px 24px rgba(24,56,61,.06); border-radius: 1rem;
    }
    .tn-inventory-family :is(a,button,input,select,textarea):focus-visible {
        outline: 3px solid rgba(199,149,45,.52); outline-offset: 2px;
    }
    .tn-inventory-family .tn-tab-strip { display:flex; gap:.45rem; overflow-x:auto; padding:.35rem; margin:1rem 0; scrollbar-width:thin; }
    .tn-inventory-family .tn-tab-strip a { white-space:nowrap; transition: transform .16s ease, background-color .16s ease; }
    .tn-inventory-family .tn-tab-strip a:hover { transform: translateY(-1px); }
    .tn-inventory-family .tn-kicker { color: var(--tn-inv-teal); letter-spacing:.11em; font-size:.68rem; font-weight:800; text-transform:uppercase; }
    .tn-inventory-family .tn-metric { border-left: 3px solid var(--tn-inv-gold); padding-left:.7rem; }
    .tn-inventory-family .tn-scroll-x { overflow-x:auto; overscroll-behavior-inline:contain; }
    @media (max-width: 640px) {
        .tn-inventory-family .tn-family-header { padding:.85rem; }
        .tn-inventory-family .tn-tab-strip { margin-inline:-.35rem; padding-inline:.35rem; }
        .tn-inventory-family .tn-panel { border-radius:.85rem; }
    }
</style>