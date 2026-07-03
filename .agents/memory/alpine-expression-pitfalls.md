---
name: Alpine expression pitfalls
description: Silent Alpine v3 expression failures — leading comments and ternary x-model
---

## Leading /* */ comment kills statement detection
Alpine v3 decides whether to wrap an expression as a statement via regex `/^[\s\n]*if.*\(.*\)/`. If the expression starts with a `/* */` comment before `if (`, the regex misses, Alpine parses it as an expression → "Unexpected token 'if'" → the whole handler is silently DEAD (e.g. a form-level stray-Enter guard stops blocking accidental submits).
**How to apply:** never put comments inside an Alpine attribute expression; use a Blade `{{-- --}}` comment on the line above instead.

## x-model must be a plain assignable path
`x-model="(cond ? a.b : '')"` throws "Invalid left-hand side in assignment" on every eval. If @input/@focus/@blur handlers already write the model, replace with one-way `:value="cond ? a.b : ''"` — behavior is preserved.

## :class evaluates even when x-show is false
`x-show="obj"` does NOT stop `:class="obj.ok ? …"` from evaluating while obj is null. Always null-guard: `(obj && obj.ok)`.

**Why:** all three shipped as live console errors on the FBR POS sale screen (July 2026) and were only caught by an e2e console capture — Blade compile and php -l cannot see them.
