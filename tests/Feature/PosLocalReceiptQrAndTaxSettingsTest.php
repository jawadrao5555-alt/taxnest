<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Support\QrImage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1377 (owner voice notes, 21 Aug 2026) — "Local bill par QR aur tax
 * settings chalein".
 *
 * Two complaints, one root cause: the Receipt Settings page was runtime-cached
 * by the service worker, so a shop could be editing an OUTDATED copy of the
 * form. Saving it (a) silently ignored the Menu-QR untick (no
 * rp_pos_style_present marker → the handler preserved the stored true) and
 * (b) wiped the whole Local display set to false (no lp_* fields → the
 * wholesale rewrite stored all-false), which is why the local bill lost its
 * tax line although nobody had turned it off.
 *
 * Locked here:
 *   1. show_menu_qr=false suppresses the QR on EVERY local bill kind
 *      (deliberate provisional, reporting-OFF final, exempt_internal), on both
 *      paper templates, on the plain render (sale-screen auto-print, receipt
 *      popup reprint, Desktop Agent silent print) AND on the pdfMode render
 *      (PDF download). Nothing is minted either.
 *   2. show_menu_qr=false NEVER touches the PRA fiscal (Sahulat) QR.
 *   3. The Local tab's own show_tax drives the local bill's Subtotal + Tax
 *      lines — ON prints rate + amount (with "incl." on a tax-inclusive bill),
 *      OFF prints the grand total only — independently of the PRA set.
 *   4. Every print path renders the same two blades: no path may bypass the
 *      per-bill-type resolver.
 *   5. /pos/receipt-settings is never runtime-cached by the service worker, and
 *      the page carries a presence marker per display set.
 *   6. The Receipt Settings live preview reads the OPEN tab's fields (lp_ on
 *      the Local tab), so a tick there changes the preview immediately.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/PosLocalReceiptQrAndTaxSettingsTest.php --testdox
 */
class PosLocalReceiptQrAndTaxSettingsTest extends TestCase
{
    private const TXN_ID = 7301;
    private const COMPANY_ID = 631;
    private const TEMPLATES = ['pos.receipts.receipt_80mm', 'pos.receipts.receipt_58mm'];

    /** Every bill kind that must follow the LOCAL display set. */
    private const LOCAL_BILL_KINDS = [
        'deliberate provisional'  => ['invoice_mode' => 'local', 'pra_status' => null],
        'reporting-OFF final'     => ['invoice_mode' => 'pra',   'pra_status' => null],
        'exempt_internal'         => ['invoice_mode' => 'pra',   'pra_status' => 'exempt_internal'],
    ];

    /** Plain render = auto-print / popup reprint / Desktop Agent. pdfMode = PDF download. */
    private const RENDER_MODES = [
        'screen/agent' => [],
        'pdf download' => ['pdfMode' => true, 'pdfPaper' => 'thermal'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Blades gate on hasColumn('restaurant_orders','token_no').
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number');
            $t->unsignedInteger('token_no')->nullable();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->unsignedInteger('bill_token')->nullable();
            $t->string('share_token')->nullable();
            $t->timestamp('share_token_created_at')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_name');
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
        });

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('default_language')->nullable();
            $t->string('local_number_style')->nullable();
            $t->string('pra_number_style')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method');
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        QrImage::resetFake();
        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param bool|null $localShowTax null = never customized (Local set mirrors PRA)
     */
    private function makeCompany(bool $menuQr, ?bool $localShowTax, bool $praShowTax = true): Company
    {
        $prefs = ['pos_style' => ['show_menu_qr' => $menuQr, 'bold' => false, 'show_logo' => false]];
        if ($localShowTax !== null) {
            $prefs['pos_local'] = ['show_tax' => $localShowTax];
        }

        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'Local Receipt Co';
        $company->order_match_style = 'off';
        $company->pos_receipt_show_tax = $praShowTax;
        $company->invoice_display_prefs = $prefs;

        return $company;
    }

    private function makeTxn(Company $company, array $attrs = []): PosTransaction
    {
        $txn = new PosTransaction(array_merge([
            'invoice_number' => 'L-000131',
            'invoice_mode' => 'local',
            'pra_status' => null,
            'pra_invoice_number' => null,
            'payment_method' => 'cash',
            'subtotal' => 1000,
            'tax_rate' => 17,
            'tax_amount' => 170,
            'discount_amount' => 0,
            'total_amount' => 1170,
            'tax_inclusive' => false,
        ], $attrs));
        $txn->id = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type' => 'product',
            'item_name' => 'Zinger Burger',
            'quantity' => 1,
            'unit_price' => 1000,
            'subtotal' => 1000,
            'is_tax_exempt' => false,
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

    /** Fresh DB row (share_token NULL) so publicBillToken()'s mint UPDATE has a target. */
    private function insertTxnRow(array $attrs = []): void
    {
        PosTransaction::withoutGlobalScope('hide_archived')->where('id', self::TXN_ID)->delete();
        PosTransaction::withoutGlobalScope('hide_archived')->insert(array_merge([
            'id' => self::TXN_ID,
            'company_id' => self::COMPANY_ID,
            'invoice_number' => 'L-000131',
            'invoice_mode' => 'local',
            'pra_status' => null,
            'share_token' => null,
            'total_amount' => 1170,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function storedShareToken(): ?string
    {
        return PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID)?->share_token;
    }

    // ── 1. Menu QR OFF on every local bill kind / template / print path ───

    public function test_menu_qr_off_suppresses_qr_on_every_local_bill_and_print_path(): void
    {
        foreach (self::LOCAL_BILL_KINDS as $kind => $billAttrs) {
            foreach (self::RENDER_MODES as $mode => $extra) {
                foreach (self::TEMPLATES as $tpl) {
                    $where = "$kind / $mode / $tpl";
                    $this->insertTxnRow($billAttrs);
                    QrImage::fake();

                    $company = $this->makeCompany(menuQr: false, localShowTax: true);
                    $txn = $this->makeTxn($company, $billAttrs);
                    $html = view($tpl, array_merge(['transaction' => $txn, 'company' => $company], $extra))->render();

                    $this->assertStringNotContainsString(__('pos.receipt_scan_bill'), $html, $where);
                    $this->assertStringNotContainsString(__('pos.receipt_scan_menu'), $html, $where);
                    $this->assertStringNotContainsString('data:image/png;base64,', $html,
                        "QR image must not be embedded — $where");
                    $this->assertEmpty(QrImage::recorded(),
                        "QrImage::dataUri() must not be called when Menu QR is off — $where");
                    $this->assertNull($this->storedShareToken(),
                        "A suppressed QR must not mint a share_token — $where");

                    QrImage::resetFake();
                }
            }
        }
    }

    /** Control: the very same bills DO print a QR while the switch is on. */
    public function test_menu_qr_on_still_prints_the_qr_on_every_local_bill_kind(): void
    {
        foreach (self::LOCAL_BILL_KINDS as $kind => $billAttrs) {
            foreach (self::TEMPLATES as $tpl) {
                $where = "$kind / $tpl";
                $this->insertTxnRow($billAttrs);
                QrImage::fake();

                $company = $this->makeCompany(menuQr: true, localShowTax: true);
                $txn = $this->makeTxn($company, $billAttrs);
                view($tpl, ['transaction' => $txn, 'company' => $company])->render();

                $this->assertCount(1, QrImage::recorded(),
                    "Menu QR on must still encode exactly one QR — $where");
                $this->assertNotEmpty($this->storedShareToken(), $where);

                QrImage::resetFake();
            }
        }
    }

    // ── 2. PRA fiscal (Sahulat) QR is never gated ─────────────────────────

    public function test_menu_qr_off_never_touches_the_pra_fiscal_qr(): void
    {
        $fiscal = ['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-9911'];

        foreach (self::TEMPLATES as $tpl) {
            $this->insertTxnRow($fiscal);
            QrImage::fake();

            $company = $this->makeCompany(menuQr: false, localShowTax: true);
            $txn = $this->makeTxn($company, $fiscal);
            view($tpl, ['transaction' => $txn, 'company' => $company])->render();

            $this->assertCount(1, QrImage::recorded(),
                "PRA fiscal QR must always render, Menu-QR switch or not — $tpl");
            $this->assertStringContainsString('PRA-9911', QrImage::recorded()[0],
                "The rendered QR must be the fiscal Sahulat payload — $tpl");

            QrImage::resetFake();
        }
    }

    // ── 3. Local tab's own show_tax drives the local bill's tax lines ─────

    public function test_local_show_tax_on_prints_rate_and_amount_on_local_bills(): void
    {
        foreach (self::LOCAL_BILL_KINDS as $kind => $billAttrs) {
            foreach (self::TEMPLATES as $tpl) {
                $where = "$kind / $tpl";
                $this->insertTxnRow($billAttrs);
                QrImage::fake();

                $company = $this->makeCompany(menuQr: false, localShowTax: true);
                $txn = $this->makeTxn($company, $billAttrs);
                $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();

                $this->assertStringContainsString(__('pos.receipt_tax') . ' (17%)', $html,
                    "Local tax line (rate) must print when the Local tab's show_tax is on — $where");
                $this->assertStringContainsString('170.00', $html,
                    "Local tax AMOUNT must print — $where");
                $this->assertStringContainsString(__('pos.receipt_subtotal'), $html,
                    "Subtotal must print alongside the tax line — $where");

                QrImage::resetFake();
            }
        }
    }

    public function test_local_show_tax_off_prints_grand_total_only(): void
    {
        foreach (self::LOCAL_BILL_KINDS as $kind => $billAttrs) {
            foreach (self::TEMPLATES as $tpl) {
                $where = "$kind / $tpl";
                $this->insertTxnRow($billAttrs);
                QrImage::fake();

                $company = $this->makeCompany(menuQr: false, localShowTax: false);
                $txn = $this->makeTxn($company, $billAttrs);
                $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();

                $this->assertStringNotContainsString(__('pos.receipt_tax') . ' (', $html,
                    "Tax line must be hidden when the Local tab's show_tax is off — $where");
                $this->assertStringNotContainsString(__('pos.receipt_subtotal'), $html,
                    "Subtotal must be hidden too (it would expose the tax gap) — $where");
                $this->assertStringContainsString(__('pos.receipt_total_caps'), $html,
                    "Grand total must still print — $where");

                QrImage::resetFake();
            }
        }
    }

    /** Tax-inclusive shop: the local bill gets the same "incl." breakdown the PRA bill shows. */
    public function test_local_tax_line_says_inclusive_on_a_tax_inclusive_bill(): void
    {
        $inclusive = ['invoice_mode' => 'pra', 'pra_status' => null, 'tax_inclusive' => true,
                      'subtotal' => 1000, 'tax_rate' => 17, 'tax_amount' => 170, 'total_amount' => 1170];

        foreach (self::TEMPLATES as $tpl) {
            $this->insertTxnRow(['invoice_mode' => 'pra', 'pra_status' => null]);
            QrImage::fake();

            $company = $this->makeCompany(menuQr: false, localShowTax: true);
            $txn = $this->makeTxn($company, $inclusive);
            $html = view($tpl, ['transaction' => $txn, 'company' => $company])->render();

            $this->assertStringContainsString(__('pos.receipt_tax') . ' (17% incl.)', $html,
                "Tax-inclusive local bill must label the tax line 'incl.' — $tpl");
            // Inclusive Subtotal re-adds the included tax so the lines sum to it.
            $this->assertStringContainsString('1,170.00', $html, $tpl);

            QrImage::resetFake();
        }
    }

    /** The two sets are independent: PRA tax ON + Local tax OFF is a valid combination. */
    public function test_pra_and_local_tax_switches_are_independent(): void
    {
        $tpl = 'pos.receipts.receipt_80mm';
        $fiscal = ['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'PRA-9911'];

        $this->insertTxnRow($fiscal);
        QrImage::fake();
        $company = $this->makeCompany(menuQr: true, localShowTax: false, praShowTax: true);

        $praHtml = view($tpl, ['transaction' => $this->makeTxn($company, $fiscal), 'company' => $company])->render();
        $this->assertStringContainsString(__('pos.receipt_tax') . ' (17%)', $praHtml,
            'PRA receipt keeps its own tax line when the PRA column is on');

        $this->insertTxnRow();
        $localHtml = view($tpl, ['transaction' => $this->makeTxn($company), 'company' => $company])->render();
        $this->assertStringNotContainsString(__('pos.receipt_tax') . ' (', $localHtml,
            'Local receipt follows the Local set, not the PRA column');
    }

    /** A company that never opened the Local tab keeps mirroring the PRA set. */
    public function test_uncustomized_local_set_still_mirrors_the_pra_set(): void
    {
        $company = $this->makeCompany(menuQr: true, localShowTax: null, praShowTax: true);
        $this->assertTrue($company->posReceiptPrefs('local')['show_tax'],
            'Never-customized Local set must mirror the PRA set (no behaviour change)');

        $company = $this->makeCompany(menuQr: true, localShowTax: null, praShowTax: false);
        $this->assertFalse($company->posReceiptPrefs('local')['show_tax']);
    }

    // ── 4. No print path may bypass the per-bill-type resolver ────────────

    public function test_every_pos_print_path_renders_the_same_two_receipt_blades(): void
    {
        $controllers = [
            'app/Http/Controllers/PosController.php',
            'app/Http/Controllers/RestaurantPosController.php',
            'app/Http/Controllers/AgentController.php',
        ];
        $found = [];
        foreach ($controllers as $file) {
            preg_match_all("/'(pos\.receipts\.[a-z0-9_]+)'/i", file_get_contents(base_path($file)), $m);
            $found = array_merge($found, $m[1]);
        }
        $this->assertNotEmpty($found, 'Receipt view names must be resolvable from the controllers');
        $found = array_values(array_unique($found));
        $expected = self::TEMPLATES;
        sort($found);
        sort($expected);
        $this->assertSame($expected, $found,
            'A new receipt blade would bypass the QR / local-tax gates — add it to this test and gate it');

        // Both blades must resolve the display set PER TRANSACTION and gate the QR.
        foreach (self::TEMPLATES as $tpl) {
            $src = file_get_contents(base_path('resources/views/' . str_replace('.', '/', $tpl) . '.blade.php'));
            $this->assertStringContainsString('posReceiptPrefsFor($transaction)', $src,
                "$tpl must resolve the display set from the transaction, not a global set");
            $this->assertStringContainsString('$showReceiptQr', $src, "$tpl must gate the QR");
        }
    }

    // ── 5. Settings page: never served stale, always carries its markers ──

    public function test_service_worker_never_runtime_caches_the_receipt_settings_page(): void
    {
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("'/pos/receipt-settings'", $sw,
            'A runtime-cached Receipt Settings page POSTs an outdated field set — it must be in skipPatterns');
        $this->assertStringContainsString("'/fbr-pos/receipt-settings'", $sw);
    }

    public function test_receipt_settings_form_carries_a_presence_marker_per_display_set(): void
    {
        $src = file_get_contents(resource_path('views/pos/receipt-settings.blade.php'));
        foreach (['rp_pos_style_present', 'rp_present', 'lp_present'] as $marker) {
            $this->assertStringContainsString('name="' . $marker . '"', $src,
                "Missing $marker — without it a stale POST silently rewrites that whole set");
        }
    }

    // ── 6. Live preview follows the OPEN tab ──────────────────────────────

    public function test_receipt_settings_preview_reads_the_open_tabs_fields(): void
    {
        $src = file_get_contents(resource_path('views/pos/partials/receipt-theme-preview.blade.php'));
        $this->assertStringContainsString("this.tab === 'local' ? 'lp_' : 'rp_'", $src,
            'The preview must read the open tab\'s field prefix, otherwise it always shows the PRA set');
        $this->assertStringContainsString("\$watch('tab'", $src,
            'Switching tabs must re-sync the preview');
        $this->assertStringContainsString("return this.tab === 'local' ? !!this.p.menuQr : true;", $src,
            'Preview QR must follow the Menu-QR tick on the Local tab and stay on for PRA');
    }
}
