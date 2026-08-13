<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NEVER-REPORTED BILLS DISPLAY-SET LOCK — Task 648 (13 Aug 2026).
 *
 * Guards the ZFC fix (task 642): exempt_internal bills (all-items-exempt,
 * NEVER reported to PRA) must follow the LOCAL receipt display set. The live
 * complaint: with the old rule ("any non-NULL pra_status = PRA set") these
 * bills ignored every Local-tab toggle and printed NTN/email against the
 * owner's wishes.
 *
 * Invariants under lock:
 *   1. posReceiptPrefsFor(): exempt_internal → LOCAL set; 'submitted'/'offline'
 *      (mode 'pra') → PRA set; NULL status + mode 'pra' (reporting-OFF final)
 *      and mode 'local' (provisional) → LOCAL set.
 *   2. Rendered 80mm AND 58mm receipts for an exempt_internal bill omit
 *      NTN / email / address when the Local toggles are off — even while the
 *      PRA set has them all on.
 *   3. Rendered receipts wrap the company email in <!--email_off--> comments
 *      (Cloudflare Scrape Shield guard).
 *   4. invoice-pdf header respects show_ntn / show_email / show_address of the
 *      per-transaction resolved set (it used to print them unconditionally),
 *      and also carries the email_off wrapper.
 *
 * Pattern follows PosReceiptOrderMatchLayoutTest: rendered-view with unsaved
 * shims wired via setRelation, sqlite :memory:.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosNeverReportedReceiptDisplayTest.php --testdox
 */
class PosNeverReportedReceiptDisplayTest extends TestCase
{
    private const COMPANY_ID = 601;
    private const TXN_ID     = 9101;

    private const NTN     = '1234567-8';
    private const EMAIL   = 'shop@example.pk';
    private const ADDRESS = '12-B Mall Road Lahore';

    private const TEMPLATES = ['pos.receipts.receipt_80mm', 'pos.receipts.receipt_58mm'];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Minimal restaurant_orders so the order-match hasColumn lookup is happy.
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number');
            $t->unsignedInteger('token_no')->nullable();
        });
    }

    // ── Fixture builders (mirrors PosController::receipt() variable set) ──

    /**
     * Company with a DIVERGENT pair of display sets:
     *   PRA set   → everything ON  (defaults)
     *   Local set → NTN / email / address OFF
     * so any regression that routes a never-reported bill to the PRA set
     * immediately leaks NTN/email/address into the rendered output.
     */
    private function makeCompany(): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'Never Reported Co';
        $company->ntn = self::NTN;
        $company->email = self::EMAIL;
        $company->address = self::ADDRESS;
        $company->order_match_style = 'off';
        $company->invoice_display_prefs = [
            // PRA set: all ON (explicit, mirrors defaults)
            'pos' => [
                'show_ntn' => true,
                'show_email' => true,
                'show_address' => true,
            ],
            // Local set: the owner's toggles — OFF
            'pos_local' => [
                'show_ntn' => false,
                'show_email' => false,
                'show_address' => false,
            ],
            // Suppress footer QR (needs unrelated tables) — orthogonal here.
            'pos_style' => ['show_menu_qr' => false, 'bold' => false],
        ];
        return $company;
    }

    private function makeTransaction(Company $company, array $attrs = []): PosTransaction
    {
        $txn = new PosTransaction(array_merge([
            'invoice_number' => 'L-000123',
            'order_type' => null,
            'payment_method' => 'cash',
            'invoice_mode' => 'pra',
            'pra_status' => 'exempt_internal',
            'pra_invoice_number' => null,
            'subtotal' => 500,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
        ], $attrs));
        $txn->id = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type' => 'product',
            'item_name' => 'Exempt Item',
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
            'is_tax_exempt' => true,
            'is_third_schedule' => false,
        ]);
        $item->id = 1;

        $txn->setRelation('items', collect([$item]));
        $txn->setRelation('payments', collect());
        $txn->setRelation('company', $company);
        $txn->setRelation('terminal', null);
        $txn->setRelation('creator', null);
        $txn->setRelation('rider', null);
        return $txn;
    }

    private function render(string $template, Company $company, PosTransaction $txn): string
    {
        return view($template, ['transaction' => $txn, 'company' => $company])->render();
    }

    /** Markup AFTER </head> — head/CSS never carries the header lines. */
    private function body(string $html): string
    {
        $pos = strpos($html, '</head>');
        $this->assertNotFalse($pos, 'rendered receipt has a </head>');
        return substr($html, $pos);
    }

    // ── 1. posReceiptPrefsFor resolution (the exact revert target) ────────

    public function test_exempt_internal_resolves_to_local_set(): void
    {
        $company = $this->makeCompany();
        $txn = $this->makeTransaction($company); // pra_status = exempt_internal

        $rp = $company->posReceiptPrefsFor($txn);

        $this->assertFalse($rp['show_ntn'],     'exempt_internal must use the LOCAL set (show_ntn off)');
        $this->assertFalse($rp['show_email'],   'exempt_internal must use the LOCAL set (show_email off)');
        $this->assertFalse($rp['show_address'], 'exempt_internal must use the LOCAL set (show_address off)');
        $this->assertEquals($company->posReceiptPrefs('local'), $rp,
            'exempt_internal bill must resolve to EXACTLY the local set');
    }

    public function test_reported_and_queued_bills_still_resolve_to_pra_set(): void
    {
        $company = $this->makeCompany();

        foreach (['submitted', 'offline', 'queued'] as $status) {
            $txn = $this->makeTransaction($company, ['pra_status' => $status]);
            $rp = $company->posReceiptPrefsFor($txn);
            $this->assertTrue($rp['show_ntn'], "pra_status={$status} must keep the PRA set (show_ntn on)");
            $this->assertTrue($rp['show_email'], "pra_status={$status} must keep the PRA set (show_email on)");
        }
    }

    public function test_reporting_off_finals_and_provisionals_resolve_to_local_set(): void
    {
        $company = $this->makeCompany();

        // Reporting-OFF final: mode 'pra' + NULL status
        $final = $this->makeTransaction($company, ['pra_status' => null]);
        // Deliberate provisional: mode 'local'
        $prov = $this->makeTransaction($company, ['invoice_mode' => 'local', 'pra_status' => null]);

        foreach (['final' => $final, 'provisional' => $prov] as $label => $txn) {
            $rp = $company->posReceiptPrefsFor($txn);
            $this->assertFalse($rp['show_ntn'], "{$label} must use the LOCAL set (show_ntn off)");
            $this->assertFalse($rp['show_email'], "{$label} must use the LOCAL set (show_email off)");
        }
    }

    // ── 2. Rendered thermal receipts: exempt_internal omits NTN/email ─────

    public function test_exempt_internal_receipt_omits_ntn_email_address_when_local_toggles_off(): void
    {
        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany();
            $txn = $this->makeTransaction($company);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringNotContainsString(self::NTN, $body, "NTN must not print ({$template})");
            $this->assertStringNotContainsString(self::EMAIL, $body, "email must not print ({$template})");
            $this->assertStringNotContainsString(self::ADDRESS, $body, "address must not print ({$template})");
        }
    }

    public function test_pra_fiscal_receipt_still_prints_ntn_and_email(): void
    {
        // Sanity guard: the fields aren't just missing from the template.
        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany();
            $txn = $this->makeTransaction($company, [
                'pra_status' => 'submitted',
                'pra_invoice_number' => '1234567890123456789012345',
                'tax_rate' => 16,
                'tax_amount' => 80,
                'total_amount' => 580,
            ]);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringContainsString(self::NTN, $body, "NTN prints on PRA bill ({$template})");
            $this->assertStringContainsString(self::EMAIL, $body, "email prints on PRA bill ({$template})");
        }
    }

    // ── 3. email_off Cloudflare wrappers on thermal receipts ──────────────

    public function test_receipt_email_is_wrapped_in_email_off_comments(): void
    {
        foreach (self::TEMPLATES as $template) {
            $company = $this->makeCompany();
            // Show email via the PRA set (fiscal bill) so the line renders.
            $txn = $this->makeTransaction($company, [
                'pra_status' => 'submitted',
                'pra_invoice_number' => '1234567890123456789012345',
            ]);

            $body = $this->body($this->render($template, $company, $txn));

            $this->assertStringContainsString(
                '<!--email_off-->' . self::EMAIL . '<!--/email_off-->',
                $body,
                "company email must ride inside email_off comments ({$template})"
            );
        }
    }

    // ── 4. invoice-pdf header respects the resolved display set ───────────

    public function test_invoice_pdf_header_omits_ntn_email_address_for_exempt_internal(): void
    {
        $company = $this->makeCompany();
        $txn = $this->makeTransaction($company);

        $body = $this->body($this->render('pos.invoice-pdf', $company, $txn));

        $this->assertStringNotContainsString(self::NTN, $body, 'PDF header must not print NTN');
        $this->assertStringNotContainsString(self::EMAIL, $body, 'PDF header must not print email');
        $this->assertStringNotContainsString(self::ADDRESS, $body, 'PDF header must not print address');
    }

    public function test_invoice_pdf_header_prints_and_email_off_wraps_on_pra_bill(): void
    {
        $company = $this->makeCompany();
        $txn = $this->makeTransaction($company, [
            'pra_status' => 'submitted',
            'pra_invoice_number' => '1234567890123456789012345',
        ]);

        $body = $this->body($this->render('pos.invoice-pdf', $company, $txn));

        $this->assertStringContainsString(self::NTN, $body, 'PDF header prints NTN on PRA bill');
        $this->assertStringContainsString(self::ADDRESS, $body, 'PDF header prints address on PRA bill');
        $this->assertStringContainsString(
            '<!--email_off-->' . self::EMAIL . '<!--/email_off-->',
            $body,
            'PDF header email must ride inside email_off comments'
        );
    }

    // ── 5. invoice-pdf FOOTER respects the resolved display set (Task 654:
    //      the footer + "Developed by" lines used to print unconditionally,
    //      ignoring show_footer / show_developed_by) ────────────────────────

    public function test_invoice_pdf_footer_omits_developed_by_and_footer_when_local_toggles_off(): void
    {
        $company = $this->makeCompany();
        $prefs = $company->invoice_display_prefs;
        $prefs['pos_local']['show_footer'] = false;
        $prefs['pos_local']['show_developed_by'] = false;
        $company->invoice_display_prefs = $prefs;
        $txn = $this->makeTransaction($company); // exempt_internal → Local set

        $body = $this->body($this->render('pos.invoice-pdf', $company, $txn));

        $this->assertStringNotContainsString(__('pos.brand_developed_by'), $body,
            'PDF footer must not print Developed-by when show_developed_by=false');
        $this->assertStringNotContainsString(__('pos.receipt_thank_purchase'), $body,
            'PDF footer must not print thank-you line when show_footer=false');
    }

    public function test_invoice_pdf_footer_prints_by_default_and_uses_custom_footer_text(): void
    {
        $company = $this->makeCompany();
        $prefs = $company->invoice_display_prefs;
        $prefs['pos_local']['footer_text'] = 'Shukriya - phir aayen';
        $company->invoice_display_prefs = $prefs;
        $txn = $this->makeTransaction($company); // Local set, footer defaults ON

        $body = $this->body($this->render('pos.invoice-pdf', $company, $txn));

        $this->assertStringContainsString(__('pos.brand_developed_by'), $body,
            'PDF footer prints Developed-by by default');
        $this->assertStringContainsString('Shukriya - phir aayen', $body,
            'PDF footer uses the Local set custom footer_text');
    }
}
