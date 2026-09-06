<?php

namespace App\Support;

/**
 * Task 658 (Aug 2026): the sale screens used to bake the ENTIRE __('pos')
 * catalogue (~4,400 keys / ~245KB JSON) into the page as the window.TXT blob,
 * while the screen's JS actually reads only a few hundred keys. This helper
 * extracts the USED keys straight from the blade source (window.TXT.foo /
 * TXT.foo / TXT['foo']) so the baked blob shrinks to the real subset — the
 * extraction can never go stale because it reads the live blade file.
 *
 * Extraction rules:
 *  - Literal dot access:      window.TXT.key  /  TXT.key
 *  - Literal bracket access:  TXT['key'] / TXT["key"]
 *  - Forced extras:           a blade comment  @posI18nExtra: key1 key2 ...
 *    (use when a key is built dynamically — the QA check refuses dynamic
 *    TXT[expr] access unless every possible key is force-listed).
 *
 * QA: scripts/pos-i18n-check.php runs the SAME extractor (this class is
 * dependency-free below extractKeys/scanProblems) and fails the deploy
 * preflight when a referenced key is missing in ANY of en/rur/ur, or when a
 * dynamic TXT[<expr>] access appears.
 */
final class PosI18n
{
    /** @var array<string, array{mtime:int, keys:string[]}> per-process cache */
    private static array $keyCache = [];

    /**
     * Keys referenced by a blade source file. Pure (no Laravel) — usable from
     * the standalone QA script.
     *
     * @return string[] sorted unique key list
     */
    public static function extractKeys(string $bladePath): array
    {
        $mtime = @filemtime($bladePath) ?: 0;
        $hit = self::$keyCache[$bladePath] ?? null;
        if ($hit && $hit['mtime'] === $mtime) {
            return $hit['keys'];
        }

        $src = (string) @file_get_contents($bladePath);
        $keys = [];

        // window.TXT.key / TXT.key  and  TXT['key'] / TXT["key"]
        if (preg_match_all(
            '/(?<![A-Za-z0-9_$.])(?:window\.)?TXT(?:\.([A-Za-z_][A-Za-z0-9_]*)|\[\s*[\'"]([A-Za-z0-9_.]+)[\'"]\s*\])/',
            $src,
            $m
        )) {
            foreach (array_merge($m[1], $m[2]) as $k) {
                if ($k !== '') {
                    $keys[$k] = true;
                }
            }
        }

        // Forced extras: {{-- @posI18nExtra: key_a key_b --}}
        if (preg_match_all('/@posI18nExtra:\s*([A-Za-z0-9_.\s]+)/', $src, $m)) {
            foreach ($m[1] as $list) {
                foreach (preg_split('/\s+/', trim($list)) as $k) {
                    if ($k !== '') {
                        $keys[$k] = true;
                    }
                }
            }
        }

        $keys = array_keys($keys);
        sort($keys);
        self::$keyCache[$bladePath] = ['mtime' => $mtime, 'keys' => $keys];
        return $keys;
    }

    /**
     * QA scan: dynamic TXT[<expr>] accesses (non-literal subscript) that the
     * extractor cannot see. Returns "line: snippet" strings; empty = clean.
     *
     * @return string[]
     */
    public static function scanProblems(string $bladePath): array
    {
        $src = (string) @file_get_contents($bladePath);
        $problems = [];
        if (preg_match_all('/(?<![A-Za-z0-9_$.])(?:window\.)?TXT\[\s*(?![\'"])/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$txt, $off]) {
                $line = substr_count($src, "\n", 0, $off) + 1;
                $snippet = trim(substr($src, $off, 60));
                $problems[] = "line {$line}: dynamic TXT[expr] access — {$snippet}";
            }
        }
        return $problems;
    }

    /**
     * The baked i18n subset for a sale-screen view, in the CURRENT locale.
     * $view is relative to resources/views, without extension,
     * e.g. 'pos/universal' or 'fbr-pos/universal'.
     *
     * Missing keys fall back key-by-key via __('pos.key') so the en fallback
     * chain behaves exactly like a direct __() call (the old full-blob bake
     * had NO fallback for non-en locales — this is strictly safer).
     *
     * @return array<string, string>
     */
    public static function baked(string $view, array $replace = []): array
    {
        $bladePath = resource_path('views/' . $view . '.blade.php');
        $all = __('pos');
        if (!is_array($all)) {
            $all = [];
        }
        $out = [];
        foreach (self::extractKeys($bladePath) as $key) {
            if (array_key_exists($key, $all)) {
                $out[$key] = $all[$key];
            } else {
                // key-level fallback (en) — mirrors __('pos.key') semantics
                $v = __('pos.' . $key);
                if ($v !== 'pos.' . $key) {
                    $out[$key] = $v;
                }
            }
        }
        // Category vocabulary (Task 1582): fill ":item / :example ..." style
        // placeholders with the shop's own words so window.TXT never says
        // "burger" to a pharmacy. Same :key / :Key / :KEY semantics as __().
        // Only strings are touched; nested arrays are baked verbatim.
        if ($replace) {
            foreach ($out as $k => $v) {
                if (is_string($v) && str_contains($v, ':')) {
                    $out[$k] = self::fill($v, $replace);
                }
            }
        }
        return $out;
    }

    /** Laravel-style placeholder fill (":key", ":Key", ":KEY"), dependency-free. */
    public static function fill(string $line, array $replace): string
    {
        // Longer keys first so ":items" is never eaten by ":item".
        uksort($replace, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        foreach ($replace as $key => $value) {
            $value = (string) $value;
            $line = str_replace(
                [':' . $key, ':' . ucfirst($key), ':' . strtoupper($key)],
                [$value, ucfirst($value), strtoupper($value)],
                $line
            );
        }
        return $line;
    }

    /**
     * Fingerprint fragment for the boot fingerprint: the baked subset changes
     * when the blade changes (already covered by the view mtime in 's') OR
     * when a lang file changes with the blade untouched — cover the active
     * locale's pos.php (and the en fallback file) mtimes here.
     */
    public static function langRev(): string
    {
        $locale = app()->getLocale();
        $parts = [$locale];
        foreach (array_unique([$locale, 'en']) as $loc) {
            $f = base_path('lang/' . $loc . '/pos.php');
            $parts[] = is_file($f) ? (string) @filemtime($f) : '0';
        }
        return implode('.', $parts);
    }
}
