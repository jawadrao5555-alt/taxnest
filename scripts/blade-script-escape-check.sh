#!/usr/bin/env bash
#
# Blade <script> escape check  (deploy preflight, static — no server needed)
# ---------------------------------------------------------------------------
# WHY THIS EXISTS
#
# Inside a <script> block the browser never decodes HTML entities. So an
# ESCAPED Blade interpolation that emits JSON:
#
#     pageDraftIds: {{ json_encode($ids) }},        <-- WRONG inside <script>
#
# reaches the browser as:
#
#     pageDraftIds: [&quot;6625&quot;,&quot;6612&quot;],
#
# which is a JavaScript SYNTAX ERROR. The whole script block fails to parse, so
# every function and Alpine component defined in it silently stops existing:
# buttons do nothing, x-cloak elements never un-hide, and the page looks exactly
# like the feature was never built. Nothing errors server-side; tests that hit
# the endpoint directly still pass.
#
# Aug 2026: this hid the invoice bulk-submit bar from a distributor with 5,959
# drafts. The feature was built, deployed and tested — he filed them one at a
# time because his browser could never run the component.
#
# THE FIX in the Blade:
#     inside <script> ......... @json($ids)   or   {!! json_encode($ids) !!}
#     inside an ATTRIBUTE ..... {{ json_encode($ids) }}   <-- escaping REQUIRED
#     (x-data="...", data-x="...", @click="..." are decoded before Alpine runs)
#
# Exit codes: 0 = clean, 1 = offending interpolation(s) found.
# ---------------------------------------------------------------------------
set -uo pipefail
cd "$(dirname "$0")/.."

php <<'PHP'
<?php
$roots = ['resources/views'];
$hits = [];
$files = 0;

foreach ($roots as $root) {
    if (!is_dir($root)) continue;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isDir() || !str_ends_with($f->getFilename(), '.blade.php')) continue;
        $files++;
        $src = file_get_contents($f->getPathname());

        // Every real <script> block (skip templates that are not executed as JS).
        if (!preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $src, $m, PREG_OFFSET_CAPTURE)) continue;

        foreach ($m[2] as $idx => [$code, $off]) {
            $attrs = $m[1][$idx][0];
            if (preg_match('/type\s*=\s*["\'](?!text\/javascript|module|application\/javascript)/i', $attrs)) {
                continue; // text/template, application/ld+json, etc.
            }
            if (!preg_match_all('/\{\{(?!--)(.*?)\}\}/s', $code, $in, PREG_OFFSET_CAPTURE)) continue;

            foreach ($in[1] as $i) {
                // Only the JSON-emitting class: those always carry double quotes.
                if (!preg_match('/json_encode|->toJson\s*\(/i', $i[0])) continue;
                $line = substr_count(substr($src, 0, $off + $i[1]), "\n") + 1;
                $hits[] = sprintf('%s:%d  {{ %s }}', $f->getPathname(), $line,
                    trim(preg_replace('/\s+/', ' ', $i[0])));
            }
        }
    }
}

if ($hits) {
    echo "BLADE SCRIPT-ESCAPE CHECK: FAIL\n\n";
    echo "Escaped JSON inside a <script> block — the browser will not decode the\n";
    echo "entities, so that script dies with a syntax error and every component in\n";
    echo "it stops working. Use @json(...) instead:\n\n";
    foreach ($hits as $h) echo "  $h\n";
    echo "\n(scanned {$files} blade files)\n";
    exit(1);
}

echo "Blade script-escape check: PASS — no escaped JSON inside inline <script> ({$files} blade files).\n";
exit(0);
PHP
