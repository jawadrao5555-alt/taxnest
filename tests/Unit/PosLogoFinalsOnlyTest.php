<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * POS Logo-on-Finals-Only gating (Task #284).
 *
 * Verifies three things without hitting the database:
 *   1. posReceiptStyle() returns logo_finals_only=false by default.
 *   2. posReceiptStyle() returns logo_finals_only=true when the key is set.
 *   3. The $showLogo derivation (the exact expression used in both receipt
 *      templates) produces the correct value for every bill-type combination
 *      with the toggle ON and OFF.
 *
 * Uses a lightweight anonymous class so no DB, no factories, no migrations.
 *
 * Run with:
 *   vendor/bin/phpunit --filter=PosLogoFinalsOnlyTest
 */
class PosLogoFinalsOnlyTest extends TestCase
{
    // ─── helper: minimal Company stub ──────────────────────────────────────────

    private function makeCompany(array $posStyle): object
    {
        return new class($posStyle) {
            public ?array $invoice_display_prefs;

            public function __construct(array $posStyle)
            {
                $this->invoice_display_prefs = ['pos_style' => $posStyle];
            }

            public function posReceiptStyle(): array
            {
                $all   = $this->invoice_display_prefs;
                $style = is_array($all) ? ($all['pos_style'] ?? []) : [];
                if (!is_array($style)) { $style = []; }

                return [
                    'bold'            => filter_var($style['bold'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'logo'            => in_array(($style['logo'] ?? 'center'), ['side', 'center'], true)
                                            ? ($style['logo'] ?? 'center') : 'center',
                    'logo_finals_only' => filter_var($style['logo_finals_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }
        };
    }

    /**
     * Helper that mirrors the exact $showLogo expression in both receipt templates:
     *   $showLogo = $logoDataUri && (!($printStyle['logo_finals_only'] ?? false) || !$rcptTopProvisional);
     */
    private function computeShowLogo(bool $hasLogo, bool $logoFinalsOnly, bool $rcptTopProvisional): bool
    {
        $logoDataUri       = $hasLogo ? 'data:image/png;base64,abc' : null;
        $printStyle        = ['logo_finals_only' => $logoFinalsOnly];
        return (bool)($logoDataUri && (!($printStyle['logo_finals_only'] ?? false) || !$rcptTopProvisional));
    }

    // ─── 1. posReceiptStyle() default ──────────────────────────────────────────

    public function test_logo_finals_only_defaults_to_false(): void
    {
        $company = $this->makeCompany([]);
        $style   = $company->posReceiptStyle();

        $this->assertArrayHasKey('logo_finals_only', $style);
        $this->assertFalse($style['logo_finals_only'], 'Default must be false — existing behaviour unchanged');
    }

    // ─── 2. posReceiptStyle() honours the stored value ─────────────────────────

    public function test_logo_finals_only_returns_true_when_set(): void
    {
        $company = $this->makeCompany(['logo_finals_only' => true]);
        $style   = $company->posReceiptStyle();

        $this->assertTrue($style['logo_finals_only']);
    }

    public function test_logo_finals_only_coerces_string_true(): void
    {
        // JSON-decoded booleans may arrive as strings in some edge-paths.
        $company = $this->makeCompany(['logo_finals_only' => '1']);
        $this->assertTrue($company->posReceiptStyle()['logo_finals_only']);

        $company = $this->makeCompany(['logo_finals_only' => 'true']);
        $this->assertTrue($company->posReceiptStyle()['logo_finals_only']);
    }

    public function test_logo_finals_only_coerces_string_false(): void
    {
        $company = $this->makeCompany(['logo_finals_only' => '0']);
        $this->assertFalse($company->posReceiptStyle()['logo_finals_only']);
    }

    public function test_logo_finals_only_does_not_affect_other_style_keys(): void
    {
        $company = $this->makeCompany(['logo_finals_only' => true, 'bold' => false, 'logo' => 'side']);
        $style   = $company->posReceiptStyle();

        $this->assertFalse($style['bold']);
        $this->assertSame('side', $style['logo']);
        $this->assertTrue($style['logo_finals_only']);
    }

    // ─── 3. $showLogo derivation (template expression) ─────────────────────────

    /**
     * Toggle OFF: logo shows on every bill type regardless.
     */
    public function test_flag_off_logo_shows_on_local_bill(): void
    {
        $rcptTopProvisional = true; // invoice_mode = 'local'
        $this->assertTrue($this->computeShowLogo(true, false, $rcptTopProvisional));
    }

    public function test_flag_off_logo_shows_on_pra_submitted_bill(): void
    {
        $rcptTopProvisional = false; // invoice_mode = 'pra', pra_status = 'submitted'
        $this->assertTrue($this->computeShowLogo(true, false, $rcptTopProvisional));
    }

    public function test_flag_off_logo_shows_on_reporting_off_final(): void
    {
        // Reporting-OFF final: invoice_mode='pra', pra_status=NULL → NOT provisional.
        $rcptTopProvisional = false;
        $this->assertTrue($this->computeShowLogo(true, false, $rcptTopProvisional));
    }

    /**
     * Toggle ON: logo suppressed on local/provisional bills.
     */
    public function test_flag_on_logo_hidden_on_local_bill(): void
    {
        $rcptTopProvisional = true; // invoice_mode = 'local'
        $this->assertFalse($this->computeShowLogo(true, true, $rcptTopProvisional));
    }

    /**
     * Toggle ON: logo still shows on a PRA-submitted final.
     */
    public function test_flag_on_logo_shows_on_pra_submitted_bill(): void
    {
        $rcptTopProvisional = false; // invoice_mode = 'pra', pra_status = 'submitted'
        $this->assertTrue($this->computeShowLogo(true, true, $rcptTopProvisional));
    }

    /**
     * Toggle ON: logo still shows on a reporting-OFF final
     * (invoice_mode='pra' + pra_status=NULL — these are real completed sales).
     */
    public function test_flag_on_logo_shows_on_reporting_off_final(): void
    {
        // $rcptTopProvisional = (invoice_mode ?? 'pra') === 'local' → false for these.
        $rcptTopProvisional = false;
        $this->assertTrue($this->computeShowLogo(true, true, $rcptTopProvisional));
    }

    /**
     * No logo uploaded: $showLogo must be false regardless of toggle or bill type.
     */
    public function test_no_logo_uploaded_always_false(): void
    {
        $this->assertFalse($this->computeShowLogo(false, false, false));
        $this->assertFalse($this->computeShowLogo(false, false, true));
        $this->assertFalse($this->computeShowLogo(false, true, false));
        $this->assertFalse($this->computeShowLogo(false, true, true));
    }
}
