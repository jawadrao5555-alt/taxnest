---
name: Production lazy-loading is disabled (strict Eloquent)
description: Live throws LazyLoadingViolationException; shared serializers need every relation eager-loaded at EVERY caller; how to pinpoint the caller from live logs.
---

# Prod lazy-loading strictness

Live (cPanel PROD) runs with Eloquent lazy loading DISABLED — any relation access
that wasn't eager-loaded throws `LazyLoadingViolationException` = user-facing 500.
Dev often won't catch it (different data shapes / roles exercised).

**The trap:** shared private serializers (e.g. a controller's `orderJson($o)` that
reads `$o->creator?->name`, `$o->table`, `$o->items`) are called from MULTIPLE
query sites. Adding a relation read to the serializer silently breaks every caller
whose `->with([...])` list lacks it. One missing `'creator'` in one caller caused
62 live 500s on the waiter My Orders screen (Jul–Aug 2026) while sibling endpoints
were fine.

**How to apply:**
- When touching any shared toArray/Json builder, grep ALL its call sites and check
  each query's `->with()` covers every relation the builder touches (including
  nested like `activeOrders.creator`).
- Freshly `create()`d models have NO relations loaded — `setRelation()` or
  `load()` before passing them to a serializer.
- Pinpoint the guilty caller from live logs:
  `grep -A80 'Attempted to lazy load \[REL\]' storage/logs/laravel.log | grep -oE '#[0-9]+ [^ ]*Controllers/[^(]+\([0-9]+\)' | sort | uniq -c | sort -rn`
  (frame line numbers belong to the commit that was live at error time, not HEAD).
