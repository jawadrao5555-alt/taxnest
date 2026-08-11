<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Shared-component language purity lint — locks Task 462 forever.
 *
 * Task 462 localized every shared layout-level component (pwa-* banners,
 * branch-switcher) plus the two POS layouts into en/rur/ur via __() keys.
 * LangPurityTest guards the lang files, but nothing stopped a future edit
 * from re-introducing hardcoded English straight into these shared Blade
 * templates — which every Urdu shop would then see in English.
 *
 * This test scans the shared templates for user-facing hardcoded English
 * literals OUTSIDE __()/trans() calls:
 *   - visible text nodes between tags
 *   - user-facing attributes (title / placeholder / aria-label / alt),
 *     single- OR double-quoted, plain or Alpine-bound (:title="'…'")
 *   - literal-string Blade echoes in those positions ({{ 'Sneak' }}) —
 *     echoes are NOT blanket-exempt: their translation calls are removed
 *     and any remaining sentence-like string literal is flagged.
 *
 * Allowlist policy: brand names, regulator acronyms, key names (F1–F12),
 * on-screen Latin badges that are intentionally English on all locales.
 * Words of 1–2 letters are ignored (by, of, OK…).
 */
class SharedComponentPurityTest extends TestCase
{
    /** Files that must stay free of hardcoded user-facing English. */
    private function targetFiles(): array
    {
        $base = __DIR__ . '/../../resources/views';
        $files = glob($base . '/components/pwa-*.blade.php') ?: [];
        $files[] = $base . '/components/branch-switcher.blade.php';
        $files[] = $base . '/components/subscription-expiry-popup.blade.php';
        $files[] = $base . '/components/trial-lock-modal.blade.php';
        $files[] = $base . '/components/trial-reminder-banner.blade.php';
        $files[] = $base . '/components/trial-restaurant-notice.blade.php';
        $files[] = $base . '/components/payment-status-banner.blade.php';
        $files[] = $base . '/components/bio-unmapped-pin-banner.blade.php';
        $files[] = $base . '/components/madadgar-support.blade.php';
        $files[] = $base . '/components/whatsapp-support.blade.php';
        $files[] = $base . '/layouts/pos-app.blade.php';
        $files[] = $base . '/layouts/fbr-pos-app.blade.php';

        return $files;
    }

    /** Tokens that are legitimately Latin on every locale. */
    private const ALLOWLIST = [
        // Brands / products (incl. plan-tier badges shown verbatim, like PREMIUM)
        'TaxNest', 'NestPOS', 'Nest', 'Pra', 'Pos', 'POS', 'PRA', 'FBR', 'PWA', 'APK', 'Enterprise',
        'WhatsApp', 'Android', 'iOS', 'Chrome', 'Safari', 'Windows', 'Inter', 'Alpine',
        // Regulator / tech acronyms & on-screen badges
        'PRO', 'PREMIUM', 'KDS', 'KOT', 'PDF', 'CSV', 'NTN', 'CNIC', 'PIN', 'SMS',
        'API', 'URL', 'PKR', 'GST', 'HQ', 'exe', 'app', 'App',
        'IBAN', 'JazzCash', 'EasyPaisa', 'Madadgar',
        // Keyboard keys / shortcuts shown verbatim
        'Enter', 'Esc', 'Del', 'Alt', 'Ctrl', 'Tab', 'Shift', 'Space',
    ];

    /** Echo placeholders — inner Blade-echo expressions keyed by token id. */
    private array $echoes = [];

    /** Replace a span with whitespace, preserving its newlines (keeps line numbers true). */
    private static function blank(string $pattern, string $src): string
    {
        return preg_replace_callback($pattern, fn ($m) => str_repeat("\n", substr_count($m[0], "\n")) . ' ', $src);
    }

    /** Strip everything that is exempt from scanning; tokenize echoes for later inspection. */
    private function stripExempt(string $src): string
    {
        $this->echoes = [];
        // Blade comments
        $src = self::blank('/\{\{--.*?--\}\}/s', $src);
        // @php ... @endphp blocks (PHP, never user-visible directly)
        $src = self::blank('/@php\b.*?@endphp/s', $src);
        // Script and style blocks
        $src = self::blank('/<script\b.*?<\/script>/is', $src);
        $src = self::blank('/<style\b.*?<\/style>/is', $src);
        // Blade echoes — replaced by placeholder tokens so echoes sitting in
        // USER-FACING positions can still be inspected for literal English.
        $n = 0;
        $src = preg_replace_callback('/\{!!(.*?)!!\}|\{\{(.*?)\}\}/s', function ($m) use (&$n) {
            $inner = $m[2] ?? $m[1];
            if (($m[1] ?? '') !== '') {
                $inner = $m[1];
            }
            $id = 'TNECHO' . $n++ . 'X';
            $this->echoes[$id] = $inner;

            return str_repeat("\n", substr_count($m[0], "\n")) . ' ' . $id . ' ';
        }, $src);
        // Blade directives with (possibly nested) parenthesised arguments
        $src = $this->stripDirectives($src);

        return $src;
    }

    /**
     * Remove @directive(...) tokens with balanced-paren argument scanning
     * (a regex cannot handle nested parens in @if(in_array(..., [...]))).
     * Newlines inside the removed span are preserved so line numbers stay true.
     */
    private function stripDirectives(string $src): string
    {
        $out = '';
        $len = strlen($src);
        $i = 0;
        while ($i < $len) {
            if ($src[$i] === '@' && $i + 1 < $len && ctype_alpha($src[$i + 1])) {
                $j = $i + 1;
                while ($j < $len && ctype_alpha($src[$j])) {
                    $j++;
                }
                $k = $j;
                while ($k < $len && ($src[$k] === ' ' || $src[$k] === "\t")) {
                    $k++;
                }
                $end = $j;
                if ($k < $len && $src[$k] === '(') {
                    $close = $this->matchParen($src, $k);
                    $end = $close === null ? $j : $close + 1;
                }
                $out .= str_repeat("\n", substr_count($src, "\n", $i, $end - $i)) . ' ';
                $i = $end;
                continue;
            }
            $out .= $src[$i];
            $i++;
        }

        return $out;
    }

    /** Index of the ')' matching the '(' at $open, quote-aware; null if unbalanced. */
    private function matchParen(string $src, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        for ($p = $open, $len = strlen($src); $p < $len; $p++) {
            $ch = $src[$p];
            if ($quote !== null) {
                if ($ch === $quote && $src[$p - 1] !== '\\') {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * Extract user-facing candidate strings with line numbers.
     * Each candidate: [text, line, isExpression] — isExpression=true means the
     * text is code (bound attribute / echo) whose STRING LITERALS get scanned.
     */
    private function candidates(string $src): array
    {
        $out = [];

        // 1) Plain user-facing attributes — single OR double quoted.
        //    (?<![:\w.-]) excludes bound (:title) and data-* variants here.
        $attrRe = '/(?<![:\w.-])(?:title|placeholder|aria-label|alt)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';
        if (preg_match_all($attrRe, $src, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            for ($i = 0; $i < count($m[0]); $i++) {
                [$all, $off] = $m[0][$i];
                $text = $m[1][$i][0] ?? $m[2][$i][0] ?? '';
                $out[] = [$text, 1 + substr_count($src, "\n", 0, $off), false];
            }
        }

        // 2) Bound user-facing attributes — :title="expr" / x-bind:title='expr'.
        //    Literal strings inside the expression are user-visible text.
        $boundRe = '/(?::|x-bind:)(?:title|placeholder|aria-label|alt)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';
        if (preg_match_all($boundRe, $src, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            for ($i = 0; $i < count($m[0]); $i++) {
                [$all, $off] = $m[0][$i];
                $text = $m[1][$i][0] ?? $m[2][$i][0] ?? '';
                $out[] = [$text, 1 + substr_count($src, "\n", 0, $off), true];
            }
        }

        // Blank ALL remaining attribute values: Alpine expressions inside
        // x-init="() => isFs = …" contain '>' which would otherwise corrupt the
        // text-node scan, and class/x-data values are never user-visible text.
        $src = self::blank('/=\s*"[^"]*"/s', $src);
        $src = self::blank("/=\s*'[^']*'/s", $src);

        // 3) Text nodes between tags
        if (preg_match_all('/>([^<>]+)</', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$text, $off]) {
                $out[] = [$text, 1 + substr_count($src, "\n", 0, $off), false];
            }
        }

        return $out;
    }

    /** Remove __()/trans()/trans_choice()/@lang() calls (balanced parens) from code. */
    private function stripTranslationCalls(string $code): string
    {
        $out = '';
        $len = strlen($code);
        $i = 0;
        while ($i < $len) {
            if (preg_match('/\G(__|trans_choice|trans)\s*\(/', $code, $m, 0, $i)) {
                $open = $i + strlen($m[0]) - 1;
                $close = $this->matchParen($code, $open);
                if ($close !== null) {
                    $i = $close + 1;
                    $out .= ' ';
                    continue;
                }
            }
            $out .= $code[$i];
            $i++;
        }

        return $out;
    }

    /**
     * Extract user-visible text from a code expression (bound attribute or echo):
     * translation calls removed, remaining string literals inspected — but only
     * sentence-like ones (skip key/path/css-token literals like 'app.name',
     * 'pos.agent', 'purple', '/branches').
     */
    private function literalTextFromExpression(string $code): string
    {
        $code = $this->stripTranslationCalls($code);
        $text = '';
        if (preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/s', $code, $m, PREG_UNMATCHED_AS_NULL)) {
            for ($i = 0; $i < count($m[0]); $i++) {
                $lit = $m[1][$i] ?? $m[2][$i] ?? '';
                // Key-like / technical tokens: dotted keys, paths, css words,
                // identifiers — all-lowercase single tokens without spaces.
                if (preg_match('/^[a-z0-9_.\/:\-#?&=%,()\[\]]*$/', $lit)) {
                    continue;
                }
                $text .= ' ' . $lit;
            }
        }

        return $text;
    }

    /** Return offending English words in a candidate string, if any. */
    private function offendingWords(string $text, bool $isExpression): array
    {
        if ($isExpression) {
            $text = $this->literalTextFromExpression($text);
        }
        // Re-expand echo placeholders: an echo in a user-facing position is
        // scanned as an expression (only its non-translation string literals).
        $text = preg_replace_callback('/TNECHO\d+X/', function ($m) {
            return ' ' . $this->literalTextFromExpression($this->echoes[$m[0]] ?? '') . ' ';
        }, $text);
        // HTML entities
        $text = preg_replace('/&[a-zA-Z#0-9]+;/', ' ', $text);

        $allow = array_flip(array_map('strtolower', self::ALLOWLIST));
        $bad = [];
        preg_match_all("/[A-Za-z][A-Za-z'’]{2,}/u", $text, $m);
        foreach ($m[0] as $word) {
            $core = strtolower(preg_replace("/['’]s?$/u", '', $word));
            if (! isset($allow[$core]) && ! preg_match('/^f\d+$/i', $word)) {
                $bad[] = $word;
            }
        }

        return $bad;
    }

    /** Scan Blade source, return failure strings (empty = clean). */
    private function scanSource(string $raw, string $label): array
    {
        $src = $this->stripExempt($raw);
        $failures = [];
        foreach ($this->candidates($src) as [$text, $line, $isExpr]) {
            $bad = $this->offendingWords($text, $isExpr);
            if ($bad !== []) {
                $failures[] = sprintf(
                    '%s:%d — hardcoded English "%s" (words: %s) — wrap it in __() with en/rur/ur keys or add to the allowlist if it is a brand/acronym',
                    $label,
                    $line,
                    trim(preg_replace('/\s+/', ' ', $text)),
                    implode(', ', array_unique($bad))
                );
            }
        }

        return $failures;
    }

    public function test_shared_components_have_no_hardcoded_english(): void
    {
        $failures = [];
        foreach ($this->targetFiles() as $file) {
            $this->assertFileExists($file, "Shared component missing: {$file}");
            $failures = array_merge($failures, $this->scanSource((string) file_get_contents($file), basename($file)));
        }
        $this->assertSame(
            [],
            $failures,
            "Hardcoded English found in shared POS/FBR components (Task 462 guard):\n  " . implode("\n  ", $failures)
        );
    }

    public function test_targets_include_the_task_462_component_set(): void
    {
        // Guard the guard: if someone renames/deletes a Task-462 component this
        // test must complain rather than silently scanning nothing.
        $names = array_map('basename', $this->targetFiles());
        foreach ([
            'pwa-banner.blade.php', 'pwa-install.blade.php', 'pwa-install-menu-item.blade.php',
            'pwa-push.blade.php', 'branch-switcher.blade.php',
            'subscription-expiry-popup.blade.php', 'trial-lock-modal.blade.php',
            'trial-reminder-banner.blade.php', 'trial-restaurant-notice.blade.php',
            'payment-status-banner.blade.php', 'bio-unmapped-pin-banner.blade.php',
            'madadgar-support.blade.php', 'whatsapp-support.blade.php',
            'pos-app.blade.php', 'fbr-pos-app.blade.php',
        ] as $must) {
            $this->assertContains($must, $names, "{$must} dropped out of the purity scan set");
        }
    }

    /** Guard the guard: known bypass shapes MUST be caught. */
    public function test_guard_catches_common_bypasses(): void
    {
        $mustCatch = [
            'text node'              => '<div><span>Sneaky English Text</span></div>',
            'double-quoted title'    => '<button title="English Sneak Here">x</button>',
            'single-quoted title'    => "<button title='English Sneak Here'>x</button>",
            'single-quoted alt'      => "<img alt='Product Photo Missing'>",
            'placeholder attr'       => '<input placeholder="Type customer name">',
            'bound attr literal'     => '<button :title="open ? \'Close Panel Now\' : \'Open Panel Now\'">x</button>',
            'literal echo'           => '<div>{{ \'Echo Sneak Words\' }}</div>',
            'raw literal echo'       => '<div>{!! \'Raw Sneak Words\' !!}</div>',
            'echo ternary literal'   => '<div>{{ $ok ? \'All Good Today\' : __(\'pos.err\') }}</div>',
            'echo inside title attr' => '<button title="{{ \'Sneaky Tooltip Text\' }}">x</button>',
        ];
        foreach ($mustCatch as $name => $snippet) {
            $this->assertNotSame([], $this->scanSource($snippet, $name), "Bypass NOT caught: {$name}");
        }

        $mustPass = [
            'localized text node'    => '<div><span>{{ __(\'pos.new_sale\') }}</span></div>',
            'localized title'        => '<button title="{{ __(\'pos.dismiss\') }}">x</button>',
            'localized bound title'  => '<button :title="isFs ? \'{{ __(\'pos.a\') }}\' : \'{{ __(\'pos.b\') }}\'">x</button>',
            'brand badge'            => '<span>PREMIUM</span><span class="x">by TaxNest</span>',
            'class ternary echo'     => '<a class="{{ $on ? \'active text-white\' : \'text-white/90\' }}">{{ __(\'pos.k\') }}</a>',
            'key-like echo default'  => '<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}"><body data-theme="{{ $t ?? \'purple\' }}"></body></html>',
        ];
        foreach ($mustPass as $name => $snippet) {
            $this->assertSame([], $this->scanSource($snippet, $name), "False positive on: {$name}");
        }
    }
}
