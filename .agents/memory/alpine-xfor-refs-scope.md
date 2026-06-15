---
name: Alpine $refs is row-scoped inside x-for handlers
description: A method invoked from an x-for row's event handler sees only that row's $refs; component-root refs are unreachable there — focus via document.querySelector instead.
---

# Alpine $refs is element-scoped — x-for row handlers can't reach root refs

When a component method is invoked from inside an `x-for` row template (e.g. an
inline input's `@keydown.enter="saveRow(index)"`), the `this.$refs` it reads only
resolves x-refs that live **inside that row's subtree**. Component-root refs declared
elsewhere on the page are `undefined` there, so `this.$refs.someRootRef?.focus()` is a
silent no-op.

**Why:** In Alpine v3, plain data props (`this.cart`, `this.guidedFlow`, …) inherit
through the x-for child scope chain and work fine, but the element-bound magics
(`$refs`, `$el`, `$root`) resolve against the row's scope/element, not the component
root. This made a "refocus the search box after committing an inline price" fix fail:
the price input's Enter handler ran in the row scope, so `this.$refs.searchInput` was
`undefined` — a diagnostic that pushed focus state into `window.__QFDBG` showed
`connected=false` at every timing stage (`$nextTick`, `setTimeout 0/80/300`) even though
the search box exists and is visible at the component root.

**How to apply:** From any handler that may run in an x-for row scope, do NOT rely on
`this.$refs.<rootRef>`. Target the live node directly, e.g.
`document.querySelector('input[name="pos_product_search_nofill"]').focus()` (the POS
search box has a unique stable name). Prefer a unique `id`/`data-*` selector if a name
could ever be duplicated by a second component. When the source element is being torn
down by `x-if` (its removal natively blurs focus to `<body>`), combine the refocus with
`$nextTick` + a `setTimeout(…, 0)`/`setTimeout(…, 60)` so a macrotask attempt wins the
teardown-blur race. To debug focus that "won't stick", capture activeElement into a
global array across those stages and read it back via page JS — console logs from the
test browser are not always surfaced.
