---
name: Blade @endif (and bodyless directives) break when a letter is glued right after them
description: A bodyless Blade directive like @endif followed IMMEDIATELY by a letter (e.g. @endifcart) is NOT recognized — the @if stays unclosed and the compiled view fails php -l with "unexpected EOF expecting endif".
---

# Blade directive glue

Writing `@endif` (or `@else`, `@endforeach`, etc.) with a letter glued straight after it —
e.g. `@if($x)type → @endifcart` — makes Blade NOT recognize the directive. Blade's matcher
requires a non-letter after a bodyless directive, so `@endifcart` is emitted as literal text
and the `@if` is left UNCLOSED.

**Symptoms:**
- `view:cache` still reports "Blade templates cached successfully" (it does not fully parse).
- `php -l` on the COMPILED view fails: `syntax error, unexpected end of file, expecting "elseif" or "else" or "endif"`.
- Directive counts are off by one: `grep -oE '@if\b'` > `grep -oE '@endif\b'` (the glued
  `@endif\b` isn't counted because there's no word boundary before the following letter).

**Fix:** put a space (or any non-letter) after the directive: `@endif cart`. HTML collapses the
extra space, so `@if($x)type → @endif cart` renders "type → cart" (true) / "cart" (false) cleanly.

**How to catch it:** never trust `view:cache` success alone for @php/@if edge cases. Find the
compiled file with `grep -rl "<unique-string>" storage/framework/views/` (rg SKIPS storage/ —
it's gitignored) and run `php -l` on it. Also compare `@if`/`@endif` counts with `grep -oE`.
