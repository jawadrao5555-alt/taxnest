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
        'resources/views/pos/day-close-summary-thermal.blade.php',
        'resources/views/public/bill-details.blade.php',
        // (Khata upgrade Aug 2026) Wasooli ki rasid — manual-print thermal slip
        // (no auto_print), so JNN-gated here but intentionally NOT in the
        // auto-print list below.
        'resources/views/fbr-pos/wasooli-receipt.blade.php',
        // A4/roll barcode label sheets: not thermal, but they print product
        // names straight from the DB, so an Urdu-named product needs JNN here
        // too (manual print button — not in the auto-print list).
        'resources/views/pos/product-labels.blade.php',
        'resources/views/fbr-pos/product-labels.blade.php',
    ];

    /**
     * SCREEN templates that build their own <html> head instead of extending a
     * layout — each one must @include('partials.urdu-font') itself, or Urdu-script
     * users land on a page still rendered in the system Naskh. The panel layouts
     * are listed too so a refactor cannot silently drop the include.
     */
    private const JNN_SCREEN_TEMPLATES = [
        'resources/views/layouts/pos-app.blade.php',
        'resources/views/layouts/fbr-pos-app.blade.php',
        'resources/views/layouts/app.blade.php',
        'resources/views/layouts/admin-app.blade.php',
        'resources/views/layouts/guest.blade.php',
        // Standalone auth screens (own <head>): the FIRST page an اردو user sees.
        'resources/views/pos/auth/login.blade.php',
        'resources/views/pos/auth/register.blade.php',
        'resources/views/fbr-pos/auth/login.blade.php',
        'resources/views/fbr-pos/auth/register.blade.php',
        'resources/views/auth/forgot-password.blade.php',
        'resources/views/auth/reset-password.blade.php',
        'resources/views/auth/verify-otp.blade.php',
        // Viewer portals + the locked-restaurant notice: own <head>, POS locale.
        'resources/views/pos/archive/layout.blade.php',
        'resources/views/pos/local/layout.blade.php',
        'resources/views/pos/restaurant-locked.blade.php',
    ];

    /**
     * Standalone screens that intentionally stay off the Urdu UI font.
     * pos/track-public: stateless public link, no session locale — its copy is
     * hard-coded Roman Urdu + English, so there is no Urdu script to shape.
     */
    private const SCREEN_FONT_EXEMPT = [
        'resources/views/pos/track-public.blade.php',
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

    public function test_every_urdu_capable_screen_wires_the_ui_font_partial(): void
    {
        foreach (self::JNN_SCREEN_TEMPLATES as $rel) {
            $path = $this->root() . '/' . $rel;
            $this->assertFileExists($path, "$rel is gone — update the JNN screen list.");
            $this->assertStringContainsString(
                "@include('partials.urdu-font')",
                file_get_contents($path),
                "$rel must @include('partials.urdu-font') — the partial self-gates on locale 'ur', "
                . "so leaving it out means اردو users read that screen in the system Naskh."
            );
        }
    }

    public function test_sweep_no_standalone_screen_slips_in_without_jnn(): void
    {
        // Any blade that opens its own document (@php header allowed before it)
        // and renders translated text is a standalone SCREEN: it gets no font
        // wiring from a layout, so it must carry the include itself. Print/PDF
        // templates are excluded — they use the urdu-print-font partial instead.
        $missing = [];
        foreach (['pos', 'fbr-pos', 'auth', 'layouts'] as $dir) {
            $base = $this->root() . '/resources/views/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $name = $file->getFilename();
                if (preg_match('/(pdf|thermal|receipt|ticket|label|print|proof-bill)/i', $name)) {
                    continue;
                }
                $src = file_get_contents($file->getPathname());
                // Real document root only — not an HTML string built inside JS.
                if (!preg_match('/^(?:@php[\s\S]{0,200}?@endphp\s*)?<!DOCTYPE html>/i', ltrim($src))) {
                    continue;
                }
                if (!str_contains($src, '__(')) {
                    continue;
                }
                $rel = ltrim(str_replace($this->root(), '', $file->getPathname()), '/');
                if (in_array($rel, self::SCREEN_FONT_EXEMPT, true)) {
                    continue;
                }
                if (!str_contains($src, "@include('partials.urdu-font')")) {
                    $missing[] = $rel;
                }
            }
        }
        sort($missing);
        $this->assertSame(
            [],
            $missing,
            'Standalone Urdu-capable screen(s) with no UI font wiring: ' . implode(', ', $missing)
            . " — add @include('partials.urdu-font') to the <head> (or list it in SCREEN_FONT_EXEMPT with a reason)."
        );
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
