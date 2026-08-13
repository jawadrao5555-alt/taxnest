<?php
/**
 * Task 658 — sale-screen i18n subset guard (deploy preflight).
 *
 * The sale screens bake only the TXT.* keys they actually use (see
 * app/Support/PosI18n.php). This check makes that extraction safe:
 *   1. Every referenced key must exist in ALL THREE locales (en/rur/ur) —
 *      a missing key means a silent English fallback or an undefined JS label.
 *   2. No dynamic TXT[<expr>] access — the extractor can't see those; use a
 *      {{-- @posI18nExtra: key1 key2 --}} blade comment to force-bake keys.
 *
 * Standalone (composer autoload only, no Laravel boot). Exit 0 = OK, 1 = FAIL.
 */

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$views = [
    'PRA sale screen' => $root . '/resources/views/pos/universal.blade.php',
    'FBR sale screen' => $root . '/resources/views/fbr-pos/universal.blade.php',
];
$locales = ['en', 'rur', 'ur'];

$lang = [];
foreach ($locales as $loc) {
    $file = $root . '/lang/' . $loc . '/pos.php';
    if (!is_file($file)) {
        fwrite(STDERR, "FAIL: lang file missing: $file\n");
        exit(1);
    }
    $lang[$loc] = require $file;
}

$fail = false;
foreach ($views as $label => $blade) {
    if (!is_file($blade)) {
        fwrite(STDERR, "FAIL: blade missing: $blade\n");
        $fail = true;
        continue;
    }

    $problems = \App\Support\PosI18n::scanProblems($blade);
    foreach ($problems as $p) {
        fwrite(STDERR, "FAIL [$label] $p\n");
        fwrite(STDERR, "      (extractor cannot see dynamic keys — add a {{-- @posI18nExtra: ... --}} comment listing every possible key, or use a literal access)\n");
        $fail = true;
    }

    $keys = \App\Support\PosI18n::extractKeys($blade);
    $missingAny = 0;
    foreach ($keys as $key) {
        $missingIn = [];
        foreach ($locales as $loc) {
            if (!array_key_exists($key, $lang[$loc])) {
                $missingIn[] = $loc;
            }
        }
        if ($missingIn) {
            fwrite(STDERR, "FAIL [$label] key '$key' missing in: " . implode(', ', $missingIn) . "\n");
            $fail = true;
            $missingAny++;
        }
    }
    echo sprintf("%s: %d baked keys, %d missing, %d dynamic-access problems\n",
        $label, count($keys), $missingAny, count($problems));
}

if ($fail) {
    fwrite(STDERR, "\npos-i18n-check FAILED — fix the keys above before deploying.\n");
    exit(1);
}
echo "pos-i18n-check OK.\n";
exit(0);
