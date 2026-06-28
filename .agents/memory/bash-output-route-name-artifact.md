---
name: bash/rg STDOUT renders pos.restaurant.pos as "n"
description: bash & ripgrep STDOUT corrupt the literal route name pos.restaurant.pos into n; read/edit/sed work on real bytes
---
- In this repo, bash/ripgrep STDOUT renders the literal string `pos.restaurant.pos` as `n` — e.g. `route('pos.restaurant.pos')` prints as `route('n')`, `name('pos.restaurant.pos')` as `name('n')`, `view('pos.restaurant.pos')` as `view('n')`, `routeIs('pos.restaurant.pos')` as `routeIs('n')`. Other tokens (e.g. `restaurant_mode`) print fine — the corruption is specific to this dotted route name.
- The ripgrep MATCH engine still runs on the REAL bytes: a search for `pos\.restaurant\.pos` finds the lines; only the DISPLAYED line text is mangled.
- **Proof:** the `read` tool shows `route('pos.restaurant.pos')` for the exact lines bash shows as `route('n')`; a `sed s|route('pos.restaurant.pos'|...|` matched & replaced them, after which `rg -l "route('pos.restaurant.pos')"` returned none.
- **Why it matters:** a prior session built an entire false "the DB column / route / view are obfuscated to `n`" narrative from this display artifact and nearly edited the wrong strings. The REAL tokens are plain: DB column `restaurant_mode`, route name `pos.restaurant.pos` (`/pos/restaurant/pos`), nav gate `$useLegacyRestaurant = $isRestaurantLayout && pos_use_legacy_restaurant`.
- **How to apply:** when bash/rg prints a suspicious bare `n` / `route('n')` / `name('n')` / `view('n')`, do NOT trust it — confirm with the `read` tool (authoritative) before reasoning or writing an `edit` old_string. `edit`/`sed` operate on real bytes, so they apply correctly even though you cannot visually verify via bash output; verify the result afterward with `read` or `rg -l`.
