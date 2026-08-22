<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * QUICK CASH → PAY MODAL WHEN CASH-RECEIVED BOX IS ON (owner, Aug 2026).
 *
 * Sibling of PosCashReceivedToggleTest (which locks the server toggle). This
 * one locks the SALE-SCREEN contract in resources/views/pos/universal.blade.php
 * for the prominent CASH shortcut + the Alt+1 chord:
 *
 *   • companies.pos_cash_received_enabled ON  → both must start a FRESH normal
 *     checkout and open the EXISTING Pay modal preselected to Cash, so the
 *     Cash Received / Change (Wapsi) fields are shown before the cashier
 *     confirms. They must NOT finalize directly.
 *   • companies.pos_cash_received_enabled OFF → both preserve today's direct
 *     one-tap finalize (processPayment('cash') without opening the modal).
 *
 * The init is centralized in ONE Alpine method (quickCashPay). Card (Alt+2 /
 * CARD button) stays a direct one-tap and is NEVER routed through it.
 *
 * CRITICAL INVARIANT: processPayment() is a shared pipeline (held / provisional
 * / restaurant flows all call it). It must NOT be globally re-gated on the
 * cash-received flag — the branch lives only in quickCashPay.
 *
 * Pure source-contract test (grep the blade), same style as
 * PosCounterKotGuardTest::test_printer_settings_blade_renders_counter_kot_fields.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosQuickCashPayModalTest.php --testdox
 */
class PosQuickCashPayModalTest extends TestCase
{
    private function saleBlade(): string
    {
        return file_get_contents(resource_path('views/pos/universal.blade.php'));
    }

    /** The centralized init method exists and is a real Alpine method. */
    public function test_quick_cash_pay_method_defined(): void
    {
        $blade = $this->saleBlade();
        $this->assertStringContainsString('quickCashPay()', $blade);
    }

    /** The flag is exposed to Alpine from the company column (rides posConfigRev). */
    public function test_cash_received_flag_exposed_to_alpine(): void
    {
        $blade = $this->saleBlade();
        $this->assertMatchesRegularExpression(
            "/cashReceivedEnabled:\s*\{\{[^}]*pos_cash_received_enabled/",
            $blade,
            'cashReceivedEnabled must mirror companies.pos_cash_received_enabled in x-data'
        );
    }

    /**
     * quickCashPay branches on the flag: ON opens the Pay modal preselected to
     * cash (payPreselect = 0 + showPayModal = true); OFF finalizes directly.
     */
    public function test_quick_cash_pay_branches_on_flag(): void
    {
        $blade = $this->saleBlade();
        $start = strpos($blade, 'quickCashPay()');
        $this->assertNotFalse($start, 'quickCashPay() not found');
        // Bound the body to the next method (processPayment) so we only assert
        // on quickCashPay's own logic.
        $end = strpos($blade, 'async processPayment(method)', $start);
        $this->assertNotFalse($end, 'processPayment boundary not found after quickCashPay');
        $body = substr($blade, $start, $end - $start);

        // ON path: guarded by the flag, opens the modal preselected to cash.
        $this->assertStringContainsString('this.cashReceivedEnabled', $body);
        $this->assertStringContainsString('this.payPreselect = 0', $body);
        $this->assertStringContainsString('this.showPayModal = true', $body);
        // OFF path: still a direct cash finalize.
        $this->assertStringContainsString("this.processPayment('cash')", $body);
        // Fresh normal checkout — never inherits a held/provisional route.
        $this->assertStringContainsString('this.payingHeldOrderId = null', $body);
        $this->assertStringContainsString('this.saveAsProvisional = false', $body);
    }

    /** The prominent on-screen CASH button routes through quickCashPay. */
    public function test_cash_button_uses_quick_cash_pay(): void
    {
        $blade = $this->saleBlade();
        $this->assertMatchesRegularExpression(
            '/@click="quickCashPay\(\)"[^>]*bg-purple-600/',
            $blade,
            'The prominent CASH button must call quickCashPay()'
        );
    }

    /** Alt+1 (cash chord) routes through quickCashPay; Alt+2 (card) stays direct. */
    public function test_alt1_uses_quick_cash_pay_and_card_stays_direct(): void
    {
        $blade = $this->saleBlade();
        // Locate the Alt+1/Alt+2 handler block.
        $start = strpos($blade, "e.code === 'Digit1'");
        $this->assertNotFalse($start, 'Alt+1/Alt+2 handler not found');
        $body = substr($blade, $start, 1600);

        // Cash chord delegates to the centralized init.
        $this->assertMatchesRegularExpression(
            '/if\s*\(!oneTapCard\)\s*\{\s*this\.quickCashPay\(\);\s*return;\s*\}/',
            $body,
            'Alt+1 (cash) must delegate to quickCashPay()'
        );
        // Card chord is still a direct one-tap finalize.
        $this->assertStringContainsString("this.processPayment('card')", $body);
    }

    /**
     * processPayment must NOT be globally gated on the cash-received flag — held
     * / provisional / restaurant flows depend on the untouched pipeline. The
     * ONLY reference to cashReceivedEnabled inside processPayment's body would be
     * a regression, so assert the flag appears exactly once (in quickCashPay).
     */
    public function test_process_payment_not_globally_regated(): void
    {
        $blade = $this->saleBlade();
        $refs = substr_count($blade, 'cashReceivedEnabled');
        // 1 = x-data declaration + comment mentions do not use the identifier
        // with `this.`; the only executable use is inside quickCashPay.
        $execRefs = substr_count($blade, 'this.cashReceivedEnabled');
        $this->assertSame(
            1,
            $execRefs,
            'this.cashReceivedEnabled must be referenced exactly once (in quickCashPay); '
            . 'processPayment must stay flag-agnostic. Found ' . $execRefs
        );
        $this->assertGreaterThanOrEqual(2, $refs, 'flag should appear in x-data + quickCashPay');
    }
}
