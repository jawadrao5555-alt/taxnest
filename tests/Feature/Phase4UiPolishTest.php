<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PHASE 4 — UI Polish Verification
 *
 * Asserts each sale screen has:
 *   1. submitting: false  Alpine state declared
 *   2. Double-submit guard on form/handler
 *   3. :disabled="submitting" binding on submit button(s)
 *   4. animate-spin spinner SVG that toggles via x-show
 *   5. Zero console.log statements
 *
 * Approach: source-file assertions (no DB / no auth required).
 * Run with:
 *   APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *     php artisan test --filter=Phase4UiPolishTest
 */
class Phase4UiPolishTest extends TestCase
{
    private function di(): string
    {
        return file_get_contents(resource_path('views/invoice/create.blade.php'));
    }

    private function fbr(): string
    {
        return file_get_contents(resource_path('views/fbr-pos/create.blade.php'));
    }

    private function pra(): string
    {
        return file_get_contents(resource_path('views/pos/create-invoice.blade.php'));
    }

    // ─────────────────────────  DI (Digital Invoice)  ─────────────────────────

    public function test_di_has_submitting_state_declared(): void
    {
        $this->assertStringContainsString('submitting: false', $this->di(),
            'DI invoiceForm() must declare submitting: false');
    }

    public function test_di_form_has_double_submit_guard(): void
    {
        $src = $this->di();
        $this->assertStringContainsString('@submit="if (submitting)', $src,
            'DI <form> must guard double-submit at @submit');
        $this->assertStringContainsString('submitting = true', $src);
    }

    public function test_di_save_draft_and_submit_methods_guard_double_submit(): void
    {
        $src = $this->di();
        $this->assertMatchesRegularExpression(
            '/saveDraft\(\)\s*\{\s*if\s*\(this\.submitting\)\s*return;/',
            $src,
            'DI saveDraft() must early-return when submitting'
        );
        $this->assertMatchesRegularExpression(
            '/submitInvoice\(\)\s*\{\s*if\s*\(this\.submitting\)\s*return;/',
            $src,
            'DI submitInvoice() must early-return when submitting'
        );
    }

    public function test_di_submit_buttons_disabled_and_show_spinner(): void
    {
        $src = $this->di();
        // Both Create Invoice + Save Invoice buttons present.
        // The sticky bottom-bar button lives outside the form's Alpine scope,
        // so it binds via `form.submitting` instead of bare `submitting`.
        $this->assertEquals(
            2,
            substr_count($src, ':disabled="submitting"') + substr_count($src, ':disabled="form.submitting"'),
            'DI must have a submitting-bound :disabled on both submit buttons'
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($src, 'animate-spin'),
            'DI must have spinner SVG (animate-spin) on both submit buttons'
        );
    }

    // ─────────────────────────  FBR POS  ─────────────────────────

    public function test_fbr_pos_has_submitting_state_declared(): void
    {
        $this->assertStringContainsString('submitting: false', $this->fbr(),
            'FBR POS fbrPosInvoice() must declare submitting: false');
    }

    public function test_fbr_pos_finalize_guards_double_submit(): void
    {
        $src = $this->fbr();
        $this->assertMatchesRegularExpression(
            '/finalizeAndSubmit\(ev\)\s*\{[\s\S]{0,200}if\s*\(this\.submitting\)\s*return;/',
            $src,
            'FBR POS finalizeAndSubmit() must early-return when already submitting'
        );
        $this->assertStringContainsString('this.submitting = true;', $src,
            'FBR POS must flip submitting=true before native form.submit()');
    }

    public function test_fbr_pos_complete_button_disabled_and_shows_spinner(): void
    {
        $src = $this->fbr();
        $this->assertStringContainsString(':disabled="!isOnline || submitting"', $src,
            'FBR POS complete button must be disabled when offline OR submitting');
        $this->assertStringContainsString('SUBMITTING TO FBR', $src,
            'FBR POS button must show submitting label');
        $this->assertStringContainsString('animate-spin', $src,
            'FBR POS button must include spinner SVG');
    }

    // ─────────────────────────  PRA POS regression (PHASE 1 work)  ─────────────────────────

    public function test_pra_pos_still_has_submitting_polish(): void
    {
        $src = $this->pra();
        $this->assertStringContainsString('submitting', $src,
            'PRA POS regression: submitting flag must still be present from PHASE 1');
    }

    // ─────────────────────────  Console hygiene  ─────────────────────────

    public function test_zero_console_logs_in_sale_screens(): void
    {
        foreach (['di' => $this->di(), 'fbr' => $this->fbr(), 'pra' => $this->pra()] as $label => $src) {
            $this->assertEquals(
                0,
                preg_match_all('/console\.log\s*\(/', $src),
                "$label sale screen must have zero console.log() calls (PHASE 4 hygiene)"
            );
        }
    }
}
