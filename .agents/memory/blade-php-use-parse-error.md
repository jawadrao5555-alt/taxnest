---
name: Blade @php use-statement runtime ParseError
description: Why `use X;` inside a Blade @php block 500s at runtime while view:cache reports success
---

Putting a `use Some\Class;` import inside a Blade `@php ... @endphp` block throws a PHP ParseError at RUNTIME — `syntax error, unexpected token "use", expecting "elseif" or "else" or "endif"` — because Blade compiles `@php` into the middle of a method body, and `use` imports are illegal inside a function/method.

**The trap:** `php artisan view:cache` does NOT catch it. view:cache only WRITES the compiled PHP files; it never parses/executes them, so it happily reports "Blade templates cached successfully" for a view that will 500 on the first real request. `npm run build` and `php -l` on the source PHP also won't catch it.

**The rule:** never `use` inside `@php`. Fully-qualify class names instead (`\App\Services\Foo::BAR`, `\App\Services\Foo::method()`) — this is what the `<script>`/inline sections of these blades already do — or use the `@use('App\Services\Foo')` directive at the very top of the blade.

**How to verify a blade actually parses:** `php -l` the COMPILED file in `storage/framework/views/*.php` (find it via `rg -l "<unique source text>" storage/framework/views`). A clean `php -l` there is the real proof; view:cache success is not. A browser/e2e hit surfaces it immediately too.
