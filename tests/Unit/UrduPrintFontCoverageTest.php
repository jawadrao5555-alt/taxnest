<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Task 1287 — Jameel Noori Nastaleeq (JNN) print coverage guard.
 *
 * Owner decision (Aug 2026): ALL Urdu-script ('ur') output — including thermal
 * receipts/KOTs — renders in JNN. Two invariants proved fragile during rollout
 * (templates merged by parallel tasks shipped without them), so this static
 * sweep pins them:
 *
 *  1. Every thermal print template that can carry Urdu text must include the
 *     shared urdu-print-font @font-face partial inside its own $urduScript gate.
 *  2. Every AUTO-print path (window.print fired without a user click) must gate
 *     the print on document.fonts.load('… Jameel Noori Nastaleeq …') with the
 *     bounded 8s failsafe — window.onload does NOT wait for CSS font downloads,
 *     so a cold cache would otherwise rasterize the Naskh/Courier fallback.
 *
 * Static source assertions on purpose: they run with no DB/models and catch an
 * incomplete template sweep at unit-test speed. If a template legitimately
 * stops auto-printing, update the lists below.
 */
class UrduPrintFontCoverageTest extends TestCase
{
    /** Thermal templates that must carry the JNN print @font-face + gate. */
    private const JNN_PRINT_TEMPLATES = [
        'resources/views/pos/receipts/receipt_80mm.blade.php',
        'resources/views/pos/receipts/receipt_58mm.blade.php',
        'resources/views/pos/restaurant/kitchen-ticket.blade.php',
        'resources/views/pos/restaurant/proof-bill.blade.php',
        'resources/views/fbr-pos/receipt.blade.php',
        'resources/views/fbr-pos/kitchen-ticket.blade.php',
        'resources/views/pos/day-close-thermal.blade.php',
        'resources/views/fbr-pos/day-close-thermal.blade.php',
        'resources/views/public/bill-details.blade.php',
        // (Khata upgrade Aug 2026) Wasooli ki rasid — manual-print thermal slip
        // (no auto_print), so JNN-gated here but intentionally NOT in the
        // auto-print list below.
        'resources/views/fbr-pos/wasooli-receipt.blade.php',
    ];

    /** Templates with an automatic print trigger that must font-gate it. */
    private const AUTO_PRINT_TEMPLATES = [
        'resources/views/pos/receipts/receipt_80mm.blade.php',
        'resources/views/pos/receipts/receipt_58mm.blade.php',
        'resources/views/pos/restaurant/kitchen-ticket.blade.php',
        'resources/views/pos/restaurant/proof-bill.blade.php',
        'resources/views/fbr-pos/receipt.blade.php',
        'resources/views/fbr-pos/kitchen-ticket.blade.php',
        // Sale screens: client-built OFFLINE interim slip prints from a hidden
        // iframe — the gate lives around fr.contentWindow.print().
        'resources/views/pos/universal.blade.php',
        'resources/views/fbr-pos/universal.blade.php',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_every_thermal_template_wires_the_jnn_print_font(): void
    {
        foreach (self::JNN_PRINT_TEMPLATES as $rel) {
            $path = $this->root() . '/' . $rel;
            $this->assertFileExists($path, "$rel is gone — update the JNN coverage list.");
            $src = file_get_contents($path);

            $this->assertStringContainsString(
                'urdu-print-font',
                $src,
                "$rel must @include the shared urdu-print-font partial (Task 1287: JNN on all Urdu thermal output)."
            );
            $this->assertStringContainsString(
                'Jameel Noori Nastaleeq',
                $src,
                "$rel must put 'Jameel Noori Nastaleeq' first in its Urdu font stack."
            );
            $this->assertMatchesRegularExpression(
                '/\$urduScript\s*=/',
                $src,
                "$rel must compute the \$urduScript gate — the font block/partial must never leak into en/rur output."
            );
        }
    }

    public function test_every_auto_print_path_waits_for_the_font_with_a_bounded_failsafe(): void
    {
        foreach (self::AUTO_PRINT_TEMPLATES as $rel) {
            $path = $this->root() . '/' . $rel;
            $this->assertFileExists($path, "$rel is gone — update the auto-print gate list.");
            $src = file_get_contents($path);

            $this->assertMatchesRegularExpression(
                // Server templates use document.fonts; the sale screens' offline
                // slip reaches the iframe's face via fr.contentDocument.fonts.
                '/(?:document|contentDocument)\.fonts[\s\S]{0,160}\.load\(\s*"16px \'Jameel Noori Nastaleeq\'"/',
                $src,
                "$rel auto-print must request the JNN face via document.fonts.load before window.print (cold cache prints the fallback otherwise)."
            );
            $this->assertStringContainsString(
                '8000',
                $src,
                "$rel must keep the bounded 8s failsafe — the print must ALWAYS eventually fire (a fallback slip beats a lost slip)."
            );
        }
    }

    public function test_sweep_no_thermal_template_slips_in_without_jnn(): void
    {
        // Any blade that declares a thermal @page size and calls window.print
        // is a print slip; if it can render Urdu (__() translations) it must be
        // in the JNN list above. Catches the "parallel task ships a new ticket
        // hard-coded to Arial" failure mode.
        $root = $this->root() . '/resources/views';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $missing = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if (!preg_match('/@page\s*\{[^}]*size:\s*(80|58)mm/', $src)) {
                continue;
            }
            if (!str_contains($src, 'window.print') || !str_contains($src, '__(')) {
                continue;
            }
            $rel = ltrim(str_replace($this->root(), '', $file->getPathname()), '/');
            if (!in_array($rel, self::JNN_PRINT_TEMPLATES, true)) {
                $missing[] = $rel;
            }
        }
        $this->assertSame(
            [],
            $missing,
            'New Urdu-capable thermal print template(s) without JNN wiring: ' . implode(', ', $missing)
            . ' — add the urdu-print-font block (see receipt_80mm) and list them in UrduPrintFontCoverageTest.'
        );
    }

    public function test_panel_font_partial_is_locale_gated(): void
    {
        // en/rur pages must NEVER download the ~5.5 MB font.
        $src = file_get_contents($this->root() . '/resources/views/partials/urdu-font.blade.php');
        $this->assertStringContainsString('URDU_SCRIPT', $src, 'urdu-font partial must self-gate on the ur locale.');
        $this->assertMatchesRegularExpression(
            '/@if[\s\S]{0,200}URDU_SCRIPT/',
            $src,
            'urdu-font partial must bail before emitting the @font-face when the locale is not ur.'
        );
    }
}
