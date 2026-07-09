---
name: POS plain-letter shortcut routing
description: Global plain-letter shortcuts (T/D/N) on POS sale screens must never claim the search input — even when it's empty.
---

# POS plain-letter shortcut routing (T / D / N)

**Rule:** A document-level plain-letter shortcut may fire ONLY when focus is on
body/non-input elements (or a qty input for T/N). The search input is a typing
surface at ALL times — including when empty. `Alt+key` is the "works anywhere"
variant.

**Why:** keydown fires BEFORE Alpine's x-model updates, so an
`isSearchInput && !searchQuery` branch sees the empty model on the FIRST
character and swallows it (preventDefault at document level cancels character
insertion). Cashiers couldn't type product names starting with t/d/n
("Tapal", "Dahi", "Naan") during quick-create — reported as "many keys don't
work when adding a product".

**How to apply:** When adding any new plain-letter shortcut to
`pos/universal.blade.php` or `fbr-pos/universal.blade.php` (identical port —
fix BOTH), never add an "empty input = shortcut free" branch. The safe gates
are: modal-open flags OFF + target not inside `input/textarea/select`
(search + customer-phone explicitly excluded). Both screens' handlers carry
NOTE comments marking the removed branch — don't reintroduce it.
