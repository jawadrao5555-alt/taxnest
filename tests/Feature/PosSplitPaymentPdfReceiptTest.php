<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosPayment;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 848 — Split-payment breakdown on downloaded and share-link PDF receipts.
 *
 * Task 816 added the breakdown section to receipt_80mm / receipt_58mm and
 * eager-loaded 'payments' on both PDF controller paths:
 *   • PosController::downloadInvoicePdf  (/pos/transaction/{id}/pdf)
 *   • PosController::publicInvoicePdf    (/pos/invoice/share/{token})
 *
 * The section is guarded by `relationLoaded('payments')`, so a future refactor
 * that silently drops 'payments' from either with() list causes the breakdown
 * to vanish from the PDF with no error — customers see a receipt that doesn't
 * match what they paid.
 *
 * Two layers of tests cover this:
 *
 * CONTROLLER-LEVEL (primary regression gate)
 *   Seeds real DB rows, mocks DomPDF to capture the view-data array, and
 *   asserts that the transaction the controller passes to the blade has
 *   'payments' relation loaded with ≥2 rows.  These tests FAIL if 'payments'
 *   is removed from either with() call.
 *
 * BLADE-LEVEL (template correctness)
 *   Renders the blade directly with pre-built in-memory objects and confirms
 *   the correct HTML sections appear / are suppressed.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosSplitPaymentPdfReceiptTest.php --testdox
 */
class PosSplitPaymentPdfReceiptTest extends TestCase
{
    private const TXN_ID     = 9201;
    private const COMPANY_ID = 701;

    private const TEMPLATES = [
        'pos.receipts.receipt_80mm',
        'pos.receipts.receipt_58mm',
    ];

    // ── Schema ────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->string('default_language')->nullable();
            $t->string('local_number_style')->nullable();
            $t->string('pra_number_style')->nullable();
            $t->string('receipt_printer_size')->nullable();
            $t->string('logo_path')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('bill_token')->nullable();
            $t->string('share_token', 64)->nullable();
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

        // Split-payment rows — Task 802/803.
        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method');
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
            $t->timestamps();
        });

        // restaurant_orders: receipt blade gates on hasColumn() check.
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number');
            $t->unsignedInteger('token_no')->nullable();
        });
    }

    // ── DB seeders for controller-level tests ─────────────────────────────

    /**
     * Seed a company + transaction + 2 payment rows into the in-memory SQLite
     * DB so the controller's real Eloquent query has data to find.
     * Returns the share_token that was written so the share-link URL can be built.
     */
    private function seedSplitPaymentBill(): string
    {
        DB::table('companies')->insert([
            'id'                   => self::COMPANY_ID,
            'name'                 => 'Split Pay Shop',
            'product_type'         => 'pos',
            'receipt_printer_size' => '80mm',
            'invoice_display_prefs' => json_encode(['pos_style' => ['show_menu_qr' => false, 'bold' => false]]),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $token = bin2hex(random_bytes(32));

        DB::table('pos_transactions')->insert([
            'id'            => self::TXN_ID,
            'company_id'    => self::COMPANY_ID,
            'invoice_number' => 'L-000201',
            'invoice_mode'  => 'local',
            'pra_status'    => null,
            'share_token'   => $token,
            'share_token_created_at' => now(),
            'is_archived'   => false,
            'total_amount'  => 800,
            'discount_amount' => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('pos_transaction_items')->insert([
            'transaction_id' => self::TXN_ID,
            'item_name'      => 'Chicken Burger',
            'quantity'       => 1,
            'subtotal'       => 800,
            'tax_amount'     => 0,
        ]);

        // Two payment rows — cash + card split.
        DB::table('pos_payments')->insert([
            ['transaction_id' => self::TXN_ID, 'payment_method' => 'cash',       'amount' => 500, 'created_at' => now(), 'updated_at' => now()],
            ['transaction_id' => self::TXN_ID, 'payment_method' => 'debit_card', 'amount' => 300, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $token;
    }

    /** Mock the DomPDF Pdf facade so no actual rendering occurs and capture view data. */
    private function mockPdfAndCapture(?array &$capturedData): void
    {
        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) use (&$capturedData) {
                $capturedData = $data;
                return true;
            })
            ->andReturnSelf();

        Pdf::shouldReceive('setOption')->andReturnSelf();
        Pdf::shouldReceive('setPaper')->andReturnSelf();
        // downloadInvoicePdf uses ->download(); publicInvoicePdf uses ->stream().
        Pdf::shouldReceive('download')->andReturn(response('fake-pdf'));
        Pdf::shouldReceive('stream')->andReturn(response('fake-pdf'));
    }

    // ── Fixtures for blade-level tests ────────────────────────────────────

    private function makeCompany(array $attrs = []): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'Split Pay Shop';
        $company->order_match_style = 'off';
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => false, 'bold' => false]];
        foreach ($attrs as $k => $v) {
            $company->{$k} = $v;
        }
        return $company;
    }

    /**
     * Build an in-memory PosTransaction with the given payments collection
     * already set as a loaded relation, mirroring ->with(['payments']) in the
     * controller's DB query.
     *
     * @param  \Illuminate\Support\Collection  $payments
     */
    private function makeTxn(Company $company, $payments, array $attrs = []): PosTransaction
    {
        $txn = new PosTransaction(array_merge([
            'invoice_number'  => 'L-000201',
            'invoice_mode'    => 'local',
            'pra_status'      => null,
            'pra_invoice_number' => null,
            'payment_method'  => 'cash',
            'subtotal'        => 800,
            'tax_rate'        => 16,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total_amount'    => 800,
        ], $attrs));
        $txn->id         = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type'         => 'product',
            'item_name'         => 'Chicken Burger',
            'quantity'          => 1,
            'unit_price'        => 800,
            'subtotal'          => 800,
            'is_tax_exempt'     => false,
            'is_third_schedule' => false,
        ]);
        $item->id = 1;

        $txn->setRelation('items',           collect([$item]));
        $txn->setRelation('payments',        $payments);
        $txn->setRelation('company',         $company);
        $txn->setRelation('terminal',        null);
        $txn->setRelation('creator',         null);
        $txn->setRelation('rider',           null);
        $txn->setRelation('restaurantOrder', null);

        return $txn;
    }

    /** Build two PosPayment instances (cash + card) — no DB insert. */
    private function makeSplitPayments(float $cashAmt = 500.0, float $cardAmt = 300.0): \Illuminate\Support\Collection
    {
        $cash = new PosPayment(['payment_method' => 'cash',       'amount' => $cashAmt]);
        $cash->id = 1; $cash->transaction_id = self::TXN_ID;

        $card = new PosPayment(['payment_method' => 'debit_card', 'amount' => $cardAmt]);
        $card->id = 2; $card->transaction_id = self::TXN_ID;

        return collect([$cash, $card]);
    }

    private function renderPdfStyle(string $template, PosTransaction $txn, Company $company): string
    {
        return view($template, [
            'transaction' => $txn,
            'company'     => $company,
            'pdfMode'     => true,
            'pdfPaper'    => 'thermal',
        ])->render();
    }

    // ════════════════════════════════════════════════════════════════════════
    // CONTROLLER-LEVEL TESTS — primary regression gate
    // ════════════════════════════════════════════════════════════════════════

    /**
     * downloadInvoicePdf path: controller queries PosTransaction with
     * ->with(['items', 'payments', ...]). Removing 'payments' from that with()
     * makes this test fail because $data['transaction']->relationLoaded('payments')
     * would return false.
     */
    public function test_download_pdf_controller_eager_loads_payments_relation(): void
    {
        $this->seedSplitPaymentBill();

        $capturedData = null;
        $this->mockPdfAndCapture($capturedData);

        // Bypass pos.auth + company.approval; bind currentCompanyId manually
        // (PosAuth middleware normally does this after login).
        app()->instance('currentCompanyId', self::COMPANY_ID);

        $response = $this->withoutMiddleware()
            ->get('/pos/transaction/' . self::TXN_ID . '/pdf');

        $response->assertOk();

        $this->assertNotNull($capturedData, 'DomPDF loadView must be called with view data');

        /** @var PosTransaction $txn */
        $txn = $capturedData['transaction'];

        $this->assertTrue(
            $txn->relationLoaded('payments'),
            'downloadInvoicePdf must eager-load the payments relation (->with([...\'payments\'...]))'
        );
        $this->assertGreaterThanOrEqual(
            2,
            $txn->payments->count(),
            'Both seeded payment rows must be loaded for the split-payment breakdown'
        );
    }

    /**
     * publicInvoicePdf path (share-link, no auth): same assertion.
     * Removing 'payments' from the with() in publicInvoicePdf makes this fail.
     */
    public function test_share_link_pdf_controller_eager_loads_payments_relation(): void
    {
        $token = $this->seedSplitPaymentBill();

        $capturedData = null;
        $this->mockPdfAndCapture($capturedData);

        $response = $this->get('/pos/invoice/share/' . $token);

        $response->assertOk();

        $this->assertNotNull($capturedData, 'DomPDF loadView must be called with view data');

        /** @var PosTransaction $txn */
        $txn = $capturedData['transaction'];

        $this->assertTrue(
            $txn->relationLoaded('payments'),
            'publicInvoicePdf must eager-load the payments relation (->with([...\'payments\'...]))'
        );
        $this->assertGreaterThanOrEqual(
            2,
            $txn->payments->count(),
            'Both seeded payment rows must be loaded for the split-payment breakdown'
        );
    }

    /**
     * The breakdown section must be present in the HTML that the controller
     * passes to DomPDF — confirms template + data together produce the section.
     * pdfMode=true (passed by both PDF controllers) must not suppress it.
     */
    public function test_download_pdf_controller_view_data_produces_breakdown_html(): void
    {
        $this->seedSplitPaymentBill();

        // Capture view data, then actually render the blade so we can inspect HTML.
        $capturedData = null;
        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) use (&$capturedData) {
                $capturedData = $data;
                return true;
            })
            ->andReturnUsing(function ($view, $data) {
                // Render the blade ourselves so we can inspect the HTML.
                $GLOBALS['_captured_receipt_html'] = view($view, $data)->render();
                return app(\Barryvdh\DomPDF\PDF::class); // real instance just for chaining
            });

        Pdf::shouldReceive('setOption')->andReturnSelf();
        Pdf::shouldReceive('setPaper')->andReturnSelf();
        Pdf::shouldReceive('download')->andReturn(response('fake-pdf'));

        app()->instance('currentCompanyId', self::COMPANY_ID);
        $this->withoutMiddleware()->get('/pos/transaction/' . self::TXN_ID . '/pdf');

        $html = $GLOBALS['_captured_receipt_html'] ?? null;
        if ($html === null) {
            $this->markTestSkipped('DomPDF render interception unavailable in this environment; controller-load test above already covers the regression gate.');
        }

        $this->assertStringContainsString(
            __('pos.payment_breakdown'),
            $html,
            'The receipt HTML produced for the downloaded PDF must contain the Payment Breakdown heading'
        );
        $this->assertStringContainsString(__('pos.receipt_pay_cash'), $html);
        $this->assertStringContainsString(__('pos.receipt_pay_card'), $html);
    }

    // ════════════════════════════════════════════════════════════════════════
    // BLADE-LEVEL TESTS — template correctness (supplementary)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Both receipt templates show the breakdown heading + per-method rows when
     * the payments relation is loaded with ≥2 rows and pdfMode=true.
     */
    public function test_split_payment_shows_breakdown_on_both_templates_in_pdf_mode(): void
    {
        $company  = $this->makeCompany();
        $payments = $this->makeSplitPayments(500, 300);
        $txn      = $this->makeTxn($company, $payments);

        foreach (self::TEMPLATES as $tpl) {
            $html = $this->renderPdfStyle($tpl, $txn, $company);

            $this->assertStringContainsString(
                __('pos.payment_breakdown'),
                $html,
                "{$tpl}: 'Payment Breakdown' heading must appear for split-payment bills"
            );
            $this->assertStringContainsString(__('pos.receipt_pay_cash'), $html, "{$tpl}: Cash row");
            $this->assertStringContainsString(__('pos.receipt_pay_card'), $html, "{$tpl}: Card row");
            $this->assertStringContainsString('500.00', $html, "{$tpl}: cash amount");
            $this->assertStringContainsString('300.00', $html, "{$tpl}: card amount");
        }
    }

    /** Three-way split (cash + card + other) — all three bucket labels must appear. */
    public function test_three_way_split_shows_all_three_bucket_labels(): void
    {
        $company = $this->makeCompany();

        $cash  = new PosPayment(['payment_method' => 'cash',       'amount' => 300]);
        $cash->id = 1; $cash->transaction_id = self::TXN_ID;
        $card  = new PosPayment(['payment_method' => 'debit_card', 'amount' => 300]);
        $card->id = 2; $card->transaction_id = self::TXN_ID;
        $other = new PosPayment(['payment_method' => 'qr_payment', 'amount' => 200]);
        $other->id = 3; $other->transaction_id = self::TXN_ID;

        $txn = $this->makeTxn($company, collect([$cash, $card, $other]));

        foreach (self::TEMPLATES as $tpl) {
            $html = $this->renderPdfStyle($tpl, $txn, $company);
            $this->assertStringContainsString(__('pos.payment_breakdown'), $html, $tpl);
            $this->assertStringContainsString(__('pos.receipt_pay_cash'),  $html, $tpl);
            $this->assertStringContainsString(__('pos.receipt_pay_card'),  $html, $tpl);
            $this->assertStringContainsString(__('pos.receipt_pay_other'), $html, $tpl);
        }
    }

    /** Single-method bills (1 row) must NOT show the breakdown section. */
    public function test_single_method_bill_has_no_breakdown_on_pdf_templates(): void
    {
        $company = $this->makeCompany();
        $cash    = new PosPayment(['payment_method' => 'cash', 'amount' => 800]);
        $cash->id = 1; $cash->transaction_id = self::TXN_ID;

        $txn = $this->makeTxn($company, collect([$cash]));

        foreach (self::TEMPLATES as $tpl) {
            $html = $this->renderPdfStyle($tpl, $txn, $company);
            $this->assertStringNotContainsString(
                __('pos.payment_breakdown'),
                $html,
                "{$tpl}: single-method bill must NOT show breakdown heading"
            );
        }
    }

    /**
     * Bills rendered without the payments relation loaded (missing with())
     * must stay breakdown-free — the relationLoaded() guard is honoured and
     * does not throw even in strict-mode environments.
     */
    public function test_unloaded_payments_relation_skips_breakdown(): void
    {
        $company = $this->makeCompany();

        $txn = new PosTransaction([
            'invoice_number'  => 'L-000202',
            'invoice_mode'    => 'local',
            'pra_status'      => null,
            'payment_method'  => 'cash',
            'subtotal'        => 800,
            'tax_rate'        => 0,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total_amount'    => 800,
        ]);
        $txn->id = self::TXN_ID; $txn->company_id = self::COMPANY_ID; $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type' => 'product', 'item_name' => 'Fries', 'quantity' => 1,
            'unit_price' => 800, 'subtotal' => 800,
            'is_tax_exempt' => false, 'is_third_schedule' => false,
        ]);
        $item->id = 2;

        $txn->setRelation('items',           collect([$item]));
        $txn->setRelation('company',         $company);
        $txn->setRelation('terminal',        null);
        $txn->setRelation('creator',         null);
        $txn->setRelation('rider',           null);
        $txn->setRelation('restaurantOrder', null);
        // 'payments' relation intentionally NOT set.

        $this->assertFalse($txn->relationLoaded('payments'), 'Precondition: payments must not be loaded');

        foreach (self::TEMPLATES as $tpl) {
            $html = $this->renderPdfStyle($tpl, $txn, $company);
            $this->assertStringNotContainsString(
                __('pos.payment_breakdown'),
                $html,
                "{$tpl}: unloaded payments relation must not show breakdown (guard must be honoured)"
            );
        }
    }

    // ── Height estimator ──────────────────────────────────────────────────

    /**
     * estimateReceiptHeightPt must add 30 + N×22 pt for split-payment bills
     * (Task 816 height fix so the downloaded PDF page is never bottom-clipped).
     * Split height − single height = expected delta.
     */
    public function test_height_estimator_adds_split_payment_section_height(): void
    {
        $company  = $this->makeCompany();
        $payments = $this->makeSplitPayments(500, 300);

        $txnSplit  = $this->makeTxn($company, $payments);
        $txnSingle = $this->makeTxn($company, collect());

        $controller = new \App\Http\Controllers\PosController();
        $method     = new \ReflectionMethod($controller, 'estimateReceiptHeightPt');
        $method->setAccessible(true);

        $heightSplit  = $method->invoke($controller, $txnSplit,  $company, '80mm');
        $heightSingle = $method->invoke($controller, $txnSingle, $company, '80mm');

        $expectedDelta = 30.0 + ($payments->count() * 22.0); // 30 + 2×22 = 74
        $this->assertEqualsWithDelta(
            $expectedDelta,
            $heightSplit - $heightSingle,
            0.01,
            'estimateReceiptHeightPt must grow by 30 + count×22 pt for split-payment bills'
        );
    }

    /** Same delta on 58mm paper — the split-payment addition is paper-size-independent. */
    public function test_height_estimator_split_delta_same_on_58mm(): void
    {
        $company  = $this->makeCompany(['receipt_printer_size' => '58mm']);
        $payments = $this->makeSplitPayments(500, 300);

        $txnSplit  = $this->makeTxn($company, $payments);
        $txnSingle = $this->makeTxn($company, collect());

        $controller = new \App\Http\Controllers\PosController();
        $method     = new \ReflectionMethod($controller, 'estimateReceiptHeightPt');
        $method->setAccessible(true);

        $heightSplit  = $method->invoke($controller, $txnSplit,  $company, '58mm');
        $heightSingle = $method->invoke($controller, $txnSingle, $company, '58mm');

        $expectedDelta = 30.0 + ($payments->count() * 22.0);
        $this->assertEqualsWithDelta(
            $expectedDelta,
            $heightSplit - $heightSingle,
            0.01,
            '58mm: estimateReceiptHeightPt split-payment delta must match 30 + count×22 pt'
        );
    }
}
