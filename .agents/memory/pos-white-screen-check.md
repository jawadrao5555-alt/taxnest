---
name: POS white-screen deploy guard
description: Pre-deploy check that catches HTTP-200-but-blank POS pages (inline JS syntax errors) plus bare __() in <script>
---

- `scripts/pos-white-screen-check.sh` runs automatically as a deploy-live.sh preflight (bypass only via `SKIP_WHITE_SCREEN_CHECK=1`). Two layers:
  1. **Static**: scans all Blade views for a `{{ }}`/`{!! !!}` echo containing `__(` inside inline `<script>` blocks (Js::from-wrapped allowed). Root cause of the Aug-2026 /pos/features white screen: apostrophe in a translation broke the whole Alpine script.
  2. **Runtime**: multi-panel — logs in as posadmin@taxnest.com for PRA (/pos/*, 7 pages) AND fbrpostest@taxnest.com for FBR POS (/fbr-pos/*, 5 pages incl. sale screen /fbr-pos/create); each page requires HTTP 200 + a language-independent marker (element ids / field names, NEVER translated text) + `node --check` passing on EVERY inline `<script>` of the rendered HTML.
- **Why markers must be language-independent:** pages render in the company's chosen locale; translated headings are unstable markers.
- **How to apply:** when adding a critical POS page, add a `"/path|markerRegex"` entry to PRA_PAGES or FBR_PAGES (new panels: add a do_login call + own array). Runtime part needs Laravel Server + MySQL Staging workflows up (exit 2 = couldn't run, distinct from exit 1 = broken page).
- White-screen class of bug: whole page is x-cloak + Alpine x-data — one JS syntax error hides everything, HTTP still 200, no server log. curl 200-checks can never catch it; node --check of rendered inline scripts does.
