---
name: Full-width top banners must live outside scrollable <main>
description: Why dismissable top notification banners get clipped/uncovered when placed inside main.overflow-y-auto with negative margins.
---

Full-width top notification banners (trial reminder, pending/suspended notices) must render as normal-flow siblings BEFORE `<main>`, alongside the existing account-state notices — NOT inside `<main class="... overflow-y-auto">` wrapped in negative-margin utilities (`-mx-* -mt-*`).

**Why:** In `resources/views/layouts/app.blade.php` the right column is `flex flex-col h-full` with a fixed sidebar, and `<main>` is `flex-1 overflow-y-auto`. A banner placed inside main with a negative top margin gets visually clipped/covered by the scroll container — it stays in the DOM and a11y tree (so automated checks "see" it) but its dismiss button is not clickable. This wasted multiple debugging passes because the element appeared present but inert.

**How to apply:** When adding any full-width top banner to a layout, mirror the placement of the pending/suspended company notices (top-level, before `<main>`, no negative margins). The `pos-app` and `fbr-pos-app` layouts already place the banner as the first child of `<main>` with no negative margin, which also works. After moving it, run `view:clear`.
