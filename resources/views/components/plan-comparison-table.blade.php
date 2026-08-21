@props([
    /** planColumns() output: [['id','name','price','popular','current'], ...].
     *  A LANDING column may also carry the buying block (Task 1483):
     *    price_period    small unit line under the amount ("/ year")
     *    price_compare   struck-through pre-sale amount
     *    sale_badge      running-campaign badge text
     *    price_note      secondary price line (PRA's 3-month alternative)
     *    cta_url         signup link carrying this package
     *    cta_label       button text
     *    price_x / price_compare_x / price_note_x
     *                    optional Alpine expressions that overwrite the three
     *                    server-rendered strings (Digital Invoice's billing
     *                    cycle switch drives its headings this way). */
    'cols' => [],
    /** sections() output: [['key','title','rows' => [...]], ...] */
    'sections' => [],
    /** Flat list of "included in every package" labels (strings). */
    'included' => [],
    /** Chrome — every string is supplied by the caller so the same table can
     *  render a translated POS panel and an English marketing landing. */
    'heading' => null,
    'sub' => null,
    'colLabel' => 'Features',
    'popularLabel' => 'Popular',
    'currentLabel' => 'Your plan',
    'tickLabel' => 'Included',
    'note' => null,
    'tip' => null,
    'includedTitle' => null,
    'includedSub' => null,
    /** Heading of the phone-only one-tap package list (Task 1483). */
    'picksTitle' => null,
    /** landing | panel — only changes the surrounding chrome, never the data. */
    'surface' => 'landing',
    'showHeading' => true,
])

@php
    /**
     * Shared package-comparison TABLE (presentation only).
     *
     * Holds no product knowledge on purpose: PRA POS, FBR POS and Digital
     * Invoice each hand it rows already derived from the plan columns their
     * own gates read (PosPlanComparisonService / FbrPosPlanComparisonService /
     * DiPlanComparisonService). One table = one look, so the three pricing
     * pages cannot drift apart visually either.
     *
     * Task 1483: on the LANDING surface the column heading is also the thing
     * that sells — price, sale, and a button that starts signup on that exact
     * package. The PANEL surface (POS billing) never grows a buy button, so
     * the sell block only switches on when the caller supplies cta_url.
     */
    $tnpcCount = count($cols);

    $tnpcSell = false;
    if ($surface === 'landing') {
        foreach ($cols as $tnpcCol) {
            if (!empty($tnpcCol['cta_url'])) {
                $tnpcSell = true;
                break;
            }
        }
    }
@endphp

@if($tnpcCount)
@once
<style>
/* ── TaxNest package comparison (Task 1350 / 1383) ───────────────────────
   Deliberately plain CSS, not Tailwind: this table ships on the marketing
   landing pages (no Vite build in that request path) AND inside the POS panel,
   so arbitrary utility classes would render invisible until the next build. */
.tnpc { --tnpc-ink:#052730; --tnpc-teal:#0A4D5C; --tnpc-gold:#E7BF3B;
        --tnpc-zebra:#F3F8F8; --tnpc-line:#E5E7EB; --tnpc-body:#FFFFFF;
        --tnpc-muted:#6B7280; --tnpc-label:#111827; --tnpc-shadow:rgba(5,39,48,.45); }
.tnpc { font-family:'Inter',system-ui,-apple-system,sans-serif; }
.tnpc-head h3 { font-family:'Playfair Display',Georgia,serif; font-size:1.75rem; line-height:1.2;
                color:var(--tnpc-ink); margin:0; }
.tnpc-head p { margin:.5rem 0 0; font-size:.875rem; line-height:1.6; color:var(--tnpc-muted); max-width:44rem; }

.tnpc-panel { border:1px solid rgba(10,77,92,.2); background:var(--tnpc-body); }
.tnpc-scroll { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
.tnpc-table { width:100%; border-collapse:separate; border-spacing:0; text-align:left; }

.tnpc-table th, .tnpc-table td { padding:.75rem 1rem; vertical-align:middle; }
.tnpc-corner, .tnpc-rowhead { position:sticky; left:0; }
.tnpc-corner { z-index:3; background:var(--tnpc-ink); vertical-align:bottom;
               width:300px; min-width:300px; box-shadow:6px 0 8px -7px var(--tnpc-shadow); }
.tnpc-corner span { font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.625rem;
                    font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:rgba(255,255,255,.7); }

.tnpc-plan { background:var(--tnpc-ink); vertical-align:bottom; text-align:center;
             width:136px; min-width:136px; }
.tnpc-plan--popular { background:var(--tnpc-teal); border-top:4px solid var(--tnpc-gold); }
.tnpc-plan--current { background:var(--tnpc-teal); border-top:4px solid #7FD4E3; }
.tnpc-plan .tnpc-tag { display:inline-block; margin-bottom:.25rem; padding:.125rem .375rem;
                       background:var(--tnpc-gold); color:var(--tnpc-ink);
                       font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.5rem;
                       font-weight:700; text-transform:uppercase; letter-spacing:.12em; }
.tnpc-plan--current .tnpc-tag { background:#7FD4E3; }
.tnpc-plan .tnpc-name { display:block; font-family:'Playfair Display',Georgia,serif;
                        font-size:1.25rem; line-height:1.15; color:#FFFFFF; }
.tnpc-plan .tnpc-price { display:block; margin-top:.25rem; font-family:'JetBrains Mono',ui-monospace,monospace;
                         font-size:.625rem; color:rgba(255,255,255,.6); }

/* Landing surface only (Task 1483): the heading becomes the buying block. */
/* Bottom-aligned on purpose: the POPULAR tag makes the flagged column taller,
   and on a buying surface the BUTTONS are what must line up across columns. */
.tnpc-plan--sell { vertical-align:bottom; width:172px; min-width:172px; padding-top:1rem; }
.tnpc-plan--sell .tnpc-name { font-size:1.125rem; }
.tnpc-plan .tnpc-was { display:block; margin-top:.375rem; font-family:'JetBrains Mono',ui-monospace,monospace;
                       font-size:.625rem; color:rgba(255,255,255,.45); text-decoration:line-through; }
.tnpc-plan .tnpc-amount { display:block; margin-top:.125rem; font-family:'JetBrains Mono',ui-monospace,monospace;
                          font-size:1rem; font-weight:700; line-height:1.2; color:#FFFFFF; }
.tnpc-plan .tnpc-per { display:block; font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.5625rem;
                       text-transform:uppercase; letter-spacing:.1em; color:rgba(255,255,255,.55); }
.tnpc-plan .tnpc-sale { display:inline-block; margin-top:.375rem; padding:.0625rem .3125rem;
                        background:var(--tnpc-gold); color:var(--tnpc-ink);
                        font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.5rem; font-weight:700;
                        text-transform:uppercase; letter-spacing:.06em; }
.tnpc-plan .tnpc-second { display:block; margin-top:.375rem; font-size:.5625rem; line-height:1.35;
                          color:rgba(255,255,255,.55); }
/* Digital Invoice blanks this line on monthly billing (x-text -> ''), so an
   empty secondary line must take no space at all. */
.tnpc-plan .tnpc-second:empty { display:none; }
.tnpc-cta { display:block; margin-top:.75rem; padding:.4375rem .375rem; border:1px solid #FFFFFF;
            background:#FFFFFF; color:var(--tnpc-ink); text-decoration:none;
            font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.5625rem; font-weight:700;
            text-transform:uppercase; letter-spacing:.08em; text-align:center; }
.tnpc-cta:hover, .tnpc-cta:focus { background:var(--tnpc-gold); border-color:var(--tnpc-gold); color:var(--tnpc-ink); }
.tnpc-plan--popular .tnpc-cta { background:var(--tnpc-gold); border-color:var(--tnpc-gold); }
.tnpc-plan--popular .tnpc-cta:hover, .tnpc-plan--popular .tnpc-cta:focus { background:#FFFFFF; border-color:#FFFFFF; }

/* Phone-only one-tap package list — the in-table buttons sit behind a
   sideways scroll on a 98px column, so every package also gets a full-width
   row here. Desktop never sees it. */
.tnpc-picks { display:none; }

.tnpc-grouprow > td { background:rgba(15,97,113,.1); border-top:1px solid rgba(10,77,92,.2);
                      border-bottom:1px solid rgba(10,77,92,.2); }
.tnpc-grouprow > td.tnpc-rowhead { z-index:2; border-right:2px solid rgba(10,77,92,.2);
                                   box-shadow:6px 0 8px -7px var(--tnpc-shadow); }
.tnpc-grouprow span { font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.625rem;
                      font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:var(--tnpc-ink); }

.tnpc-row > td { border-bottom:1px solid var(--tnpc-line); background:var(--tnpc-body); }
.tnpc-row--zebra > td { background:var(--tnpc-zebra); }
.tnpc-row > td.tnpc-rowhead { z-index:2; border-right:2px solid rgba(10,77,92,.2);
                              box-shadow:6px 0 8px -7px var(--tnpc-shadow);
                              width:300px; min-width:300px; }
.tnpc-row > td.tnpc-val { text-align:center; }
.tnpc-row > td.tnpc-val--popular { background:rgba(10,77,92,.05); }
.tnpc-row--zebra > td.tnpc-val--popular { background:rgba(10,77,92,.1); }
.tnpc-row > td.tnpc-val--current { background:rgba(10,77,92,.09); }
.tnpc-row--zebra > td.tnpc-val--current { background:rgba(10,77,92,.15); }

.tnpc-label { display:block; font-size:.875rem; font-weight:500; line-height:1.35; color:var(--tnpc-label); }
.tnpc-hint { display:block; margin-top:.125rem; font-size:.6875rem; line-height:1.35; color:var(--tnpc-muted); }
.tnpc-num { font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.8125rem;
            font-weight:600; color:var(--tnpc-ink); }
.tnpc-num--unl { font-weight:700; color:var(--tnpc-teal); }

.tnpc-tick, .tnpc-cross { display:inline-flex; align-items:center; justify-content:center;
                          width:1.5rem; height:1.5rem; }
.tnpc-tick { background:var(--tnpc-teal); color:#FFFFFF; }
.tnpc-cross { background:#F9FAFB; border:1px solid #D1D5DB; color:#9CA3AF; }
.tnpc-tick svg, .tnpc-cross svg { width:.875rem; height:.875rem; }

.tnpc-note { display:flex; align-items:flex-start; gap:.5rem; margin:.75rem 0 0;
             font-size:.75rem; line-height:1.6; color:#4B5563; }
.tnpc-note i { flex:none; margin-top:.375rem; width:.375rem; height:.375rem;
               background:var(--tnpc-gold); font-style:normal; }
.tnpc-tip { margin:.25rem 0 0; font-size:.6875rem; color:var(--tnpc-muted); }

.tnpc-included { margin-top:2rem; background:#07333E; padding:1.5rem; }
.tnpc-included h4 { margin:0; font-family:'Playfair Display',Georgia,serif; font-size:1.25rem; color:#FFFFFF; }
.tnpc-included p { margin:.375rem 0 0; font-size:.8125rem; color:#CBD5E1; }
.tnpc-included ul { list-style:none; margin:1rem 0 0; padding:0; display:grid; gap:.625rem;
                    grid-template-columns:1fr; }
.tnpc-included li { display:flex; align-items:flex-start; gap:.5rem; font-size:.8125rem; color:#F1F5F9; }
.tnpc-included li svg { flex:none; width:1rem; height:1rem; margin-top:.0625rem; color:#2EA0B3; }

@media (min-width:640px) { .tnpc-included ul { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (min-width:1024px) { .tnpc-included ul { grid-template-columns:repeat(4,minmax(0,1fr)); } }

/* Phone: the features column stays put, the packages slide sideways. */
@media (max-width:639px) {
    .tnpc-head h3 { font-size:1.375rem; }
    .tnpc-table th, .tnpc-table td { padding:.5rem; }
    .tnpc-corner, .tnpc-row > td.tnpc-rowhead { width:150px; min-width:150px; }
    .tnpc-plan { width:98px; min-width:98px; }
    .tnpc-plan--sell { width:112px; min-width:112px; }
    .tnpc-plan .tnpc-name { font-size:.8125rem; }
    .tnpc-plan--sell .tnpc-name { font-size:.8125rem; }
    .tnpc-plan .tnpc-price { font-size:.5rem; }
    .tnpc-plan .tnpc-amount { font-size:.8125rem; }
    .tnpc-plan .tnpc-was, .tnpc-plan .tnpc-second { font-size:.5rem; }
    /* The buy button moves out of the sideways scroll into .tnpc-picks. */
    .tnpc-plan .tnpc-cta { display:none; }
    .tnpc-label { font-size:.75rem; }
    .tnpc-hint { font-size:.625rem; }
    .tnpc-num { font-size:.6875rem; }
    .tnpc-tick, .tnpc-cross { width:1.25rem; height:1.25rem; }
    .tnpc-tick svg, .tnpc-cross svg { width:.75rem; height:.75rem; }
    .tnpc-included { padding:1.125rem; }

    .tnpc-picks { display:block; margin-top:.875rem; border:1px solid rgba(10,77,92,.2);
                  background:var(--tnpc-body); }
    .tnpc-picks h4 { margin:0; padding:.625rem .875rem; background:var(--tnpc-ink); color:#FFFFFF;
                     font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.625rem; font-weight:700;
                     text-transform:uppercase; letter-spacing:.12em; }
    .tnpc-pick { display:flex; align-items:center; gap:.625rem; padding:.6875rem .875rem;
                 border-top:1px solid var(--tnpc-line); text-decoration:none; }
    .tnpc-pick:first-child { border-top:0; }
    .tnpc-pick-name { flex:1 1 auto; min-width:0; font-size:.8125rem; font-weight:600; color:var(--tnpc-label); }
    .tnpc-pick-name em { display:inline-block; margin-left:.375rem; padding:.0625rem .25rem; background:var(--tnpc-gold);
                         color:var(--tnpc-ink); font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.5rem;
                         font-style:normal; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
    .tnpc-pick-price { flex:none; font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.6875rem;
                       color:var(--tnpc-muted); }
    .tnpc-pick-go { flex:none; padding:.375rem .5rem; background:var(--tnpc-teal); color:#FFFFFF;
                    font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.5625rem; font-weight:700;
                    text-transform:uppercase; letter-spacing:.08em; }
}

/* POS panel dark mode (the marketing landings are always light). */
.dark .tnpc { --tnpc-body:#111827; --tnpc-zebra:#0F1B22; --tnpc-line:#1F2937;
              --tnpc-label:#F3F4F6; --tnpc-muted:#9CA3AF; }
.dark .tnpc-head h3 { color:#F9FAFB; }
.dark .tnpc-panel { border-color:rgba(45,212,238,.2); }
.dark .tnpc-grouprow > td { background:rgba(15,97,113,.28); }
.dark .tnpc-grouprow span { color:#A5E5F0; }
.dark .tnpc-num { color:#E5E7EB; }
.dark .tnpc-num--unl { color:#5EC7DA; }
.dark .tnpc-cross { background:#1F2937; border-color:#374151; color:#6B7280; }
.dark .tnpc-note { color:#9CA3AF; }
</style>
@endonce

<div {{ $attributes->merge(['class' => 'tnpc']) }} data-surface="{{ $surface }}">
    @if($showHeading && ($heading || $sub))
    <div class="tnpc-head" style="margin-bottom:1.25rem;">
        @if($heading)<h3>{{ $heading }}</h3>@endif
        @if($sub)<p>{{ $sub }}</p>@endif
    </div>
    @endif

    <div class="tnpc-panel">
        <div class="tnpc-scroll">
            {{-- table-static keeps public/css/mobile.css's universal
                 "turn every table into a block scroller" rule away from this
                 one — it would break the sticky features column. --}}
            <table class="tnpc-table table-static">
                <thead>
                    <tr>
                        <th class="tnpc-corner" scope="col"><span>{{ $colLabel }}</span></th>
                        @foreach($cols as $col)
                        <th scope="col" class="tnpc-plan{{ $col['current'] ? ' tnpc-plan--current' : ($col['popular'] ? ' tnpc-plan--popular' : '') }}{{ $tnpcSell ? ' tnpc-plan--sell' : '' }}">
                            @if($col['current'])
                                <span class="tnpc-tag">{{ $currentLabel }}</span>
                            @elseif($col['popular'])
                                <span class="tnpc-tag">{{ $popularLabel }}</span>
                            @endif
                            <span class="tnpc-name">{{ $col['name'] }}</span>
                            @if($tnpcSell)
                                @if(!empty($col['price_compare']))
                                    <span class="tnpc-was" @if(!empty($col['price_compare_x'])) x-text="{{ $col['price_compare_x'] }}" @endif>{{ $col['price_compare'] }}</span>
                                @endif
                                <span class="tnpc-amount" @if(!empty($col['price_x'])) x-text="{{ $col['price_x'] }}" @endif>{{ $col['price'] }}</span>
                                @if(!empty($col['price_period']))
                                    <span class="tnpc-per">{{ $col['price_period'] }}</span>
                                @endif
                                @if(!empty($col['sale_badge']))
                                    <span class="tnpc-sale">{{ $col['sale_badge'] }}</span>
                                @endif
                                @if(!empty($col['price_note']) || !empty($col['price_note_x']))
                                    <span class="tnpc-second" @if(!empty($col['price_note_x'])) x-text="{{ $col['price_note_x'] }}" @endif>{{ $col['price_note'] ?? '' }}</span>
                                @endif
                                @if(!empty($col['cta_url']))
                                    <a class="tnpc-cta" href="{{ $col['cta_url'] }}">{{ $col['cta_label'] ?? $col['name'] }}</a>
                                @endif
                            @else
                                <span class="tnpc-price">{{ $col['price'] }}</span>
                            @endif
                        </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($sections as $section)
                    <tr class="tnpc-grouprow">
                        <td class="tnpc-rowhead"><span>{{ $section['title'] }}</span></td>
                        <td colspan="{{ $tnpcCount }}"></td>
                    </tr>
                    @foreach($section['rows'] as $rowIndex => $row)
                    <tr class="tnpc-row{{ $rowIndex % 2 === 1 ? ' tnpc-row--zebra' : '' }}">
                        <th scope="row" class="tnpc-rowhead" style="font-weight:400;">
                            <span class="tnpc-label">{{ $row['label'] }}</span>
                            @if(!empty($row['hint']))
                                <span class="tnpc-hint">{{ $row['hint'] }}</span>
                            @endif
                        </th>
                        @foreach($cols as $colIndex => $col)
                        <td class="tnpc-val{{ $col['current'] ? ' tnpc-val--current' : ($col['popular'] ? ' tnpc-val--popular' : '') }}">
                            @if($row['kind'] === 'limit')
                                <span class="tnpc-num{{ $row['values'][$colIndex]['unlimited'] ? ' tnpc-num--unl' : '' }}">{{ $row['values'][$colIndex]['text'] }}</span>
                            @elseif($row['values'][$colIndex])
                                <span class="tnpc-tick" role="img" aria-label="{{ $tickLabel }}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                                </span>
                            @else
                                <span class="tnpc-cross" role="img" aria-label="—">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                                </span>
                            @endif
                        </td>
                        @endforeach
                    </tr>
                    @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($tnpcSell)
    {{-- Phone-only (CSS): one full-width row per package so a visitor never
         has to scroll the table sideways to reach a buy button. --}}
    <div class="tnpc-picks">
        @if($picksTitle)<h4>{{ $picksTitle }}</h4>@endif
        @foreach($cols as $col)
            @if(!empty($col['cta_url']))
            <a class="tnpc-pick" href="{{ $col['cta_url'] }}">
                <span class="tnpc-pick-name">{{ $col['name'] }}@if($col['popular'])<em>{{ $popularLabel }}</em>@endif</span>
                <span class="tnpc-pick-price"><span @if(!empty($col['price_x'])) x-text="{{ $col['price_x'] }}" @endif>{{ $col['price'] }}</span>@if(!empty($col['price_period'])) {{ $col['price_period'] }}@endif</span>
                <span class="tnpc-pick-go">{{ $col['cta_label'] ?? $col['name'] }}</span>
            </a>
            @endif
        @endforeach
    </div>
    @endif

    @if($note)<p class="tnpc-note"><i></i><span>{{ $note }}</span></p>@endif
    @if($tip)<p class="tnpc-tip">{{ $tip }}</p>@endif

    @if(!empty($included))
    <div class="tnpc-included">
        @if($includedTitle)<h4>{{ $includedTitle }}</h4>@endif
        @if($includedSub)<p>{{ $includedSub }}</p>@endif
        <ul>
            @foreach($included as $item)
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                <span>{{ $item }}</span>
            </li>
            @endforeach
        </ul>
    </div>
    @endif
</div>
@endif
