<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 874 — Split-payment breakdown on FBR POS downloaded PDF.
 *
 * Task 816 added the split-payment breakdown to the PRA receipt blades.
 * FBR POS has its own A4 PDF template (fbr-pos.invoice-pdf) that previously
 * omitted the section entirely.  Task 874 adds the breakdown to that template
 * and guards it with tests so a future edit that removes the section is
 * immediately caught.
 *
 * Architecture difference from PRA:
 *   PRA stores split payments in a separate pos_payments table (hasMany).
 *   FBR POS stores them in a JSON column: payment_breakdown (array of
 *   ['method' => string, 'amount' => float]).  No with() change is needed
 *   in the controller because it is a column, not a relation.
 *
 * Two test layers:
 *
 * BLADE-LEVEL (primary correctness gate)
 *   Renders fbr-pos.invoice-pdf with in-memory model objects so no HTTP stack
 *   is involved.  Confirms the breakdown heading + per-method rows appear for
 *   split bills and are absent for single-method bills.
 *
 * CONTROLLER-LEVEL (regression gate against with() / query changes)
 *   Seeds real SQLite rows, mocks DomPDF to capture view data, hits
 *   /fbr-pos/transaction/{id}/pdf and asserts that the transaction passed to
 *   the blade has a payment_breakdown array with ≥2 entries.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrPosSplitPaymentPdfTest.php --testdox
 */
class FbrPosSplitPaymentPdfTest extends TestCase
{
    private const TXN_ID     = 9401;
    private const COMPANY_ID = 741;

    // ── Schema ────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->string('default_language')->nullable();
            $t->string('receipt_printer_size')->nullable();
            $t->string('logo_path')->nullable();
            $t->string('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('ntn')->nullable();
            $t->string('fbr_pos_id')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->text('receipt_footer_note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->index();
            $t->string('invoice_mode')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('customer_ntn')->nullable();
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 10, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('fbr_service_charge', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->string('payment_method')->default('cash');
            $t->json('payment_breakdown')->nullable();
            $t->decimal('cash_received', 15, 2)->default(0);
            $t->decimal('change_due', 15, 2)->default(0);
            $t->string('status')->default('completed');
            $t->string('fbr_invoice_number')->nullable();
            $t->string('fbr_status')->default('pending');
            $t->string('fbr_response_code')->nullable();
            $t->json('fbr_response')->nullable();
            $t->string('fbr_submission_hash')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('share_token', 64)->nullable();
            $t->timestamp('share_token_created_at')->nullable();
            $t->string('order_type')->nullable();
            $t->string('delivery_address')->nullable();
            $t->unsignedBigInteger('rider_id')->nullable();
            $t->string('offline_uuid')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_name');
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('unit_price', 15, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->boolean('is_tax_exempt')->default(false);
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->string('uom')->nullable();
            $t->timestamps();
        });
    }

    // ── DB seeders for controller-level tests ─────────────────────────────

    private function seedSplitPaymentBill(): void
    {
        DB::table('companies')->insert([
            'id'          => self::COMPANY_ID,
            'name'        => 'FBR Split Shop',
            'product_type' => 'fbrpos',
            'invoice_display_prefs' => json_encode([]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('fbr_pos_transactions')->insert([
            'id'               => self::TXN_ID,
            'company_id'       => self::COMPANY_ID,
            'invoice_number'   => 'F-000401',
            'invoice_mode'     => 'fbr',
            'subtotal'         => 1000,
            'tax_rate'         => 18,
            'tax_amount'       => 180,
            'total_amount'     => 1180,
            'payment_method'   => 'cash',
            // Two-row split: cash 800 + card 380
            'payment_breakdown' => json_encode([
                ['method' => 'cash',       'amount' => 800],
                ['method' => 'debit_card', 'amount' => 380],
            ]),
            'fbr_status'       => 'submitted',
            'fbr_invoice_number' => 'FBR-000401',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        DB::table('fbr_pos_transaction_items')->insert([
            'transaction_id' => self::TXN_ID,
            'item_name'      => 'Premium Widget',
            'quantity'       => 2,
            'unit_price'     => 500,
            'tax_rate'       => 18,
            'is_tax_exempt'  => false,
            'subtotal'       => 1000,
            'total'          => 1180,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /** Mock the DomPDF facade and capture the view data passed to loadView. */
    private function mockPdfAndCapture(?array &$capturedData): void
    {
        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) use (&$capturedData) {
                $capturedData = $data;
                return true;
            })
            ->andReturnSelf();

        Pdf::shouldReceive('setPaper')->andReturnSelf();
        Pdf::shouldReceive('setOption')->andReturnSelf();
        // downloadPdf uses ->download(); previewPdf uses ->stream().
        Pdf::shouldReceive('download')->andReturn(response('fake-pdf'));
        Pdf::shouldReceive('stream')->andReturn(response('fake-pdf'));
    }

    // ── Fixtures for blade-level tests ────────────────────────────────────

    private function makeCompany(array $attrs = []): Company
    {
        $company = new Company();
        $company->id   = self::COMPANY_ID;
        $company->name = 'FBR Split Shop';
        $company->invoice_display_prefs = [];
        foreach ($attrs as $k => $v) {
            $company->{$k} = $v;
        }
        return $company;
    }

    /**
     * Build an in-memory FbrPosTransaction with the given payment_breakdown
     * array already set (mirrors what the DB column delivers after the cast).
     */
    private function makeTxn(Company $company, ?array $breakdown, array $attrs = []): FbrPosTransaction
    {
        $txn = new FbrPosTransaction(array_merge([
            'invoice_number'   => 'F-000401',
            'invoice_mode'     => 'fbr',
            'payment_method'   => 'cash',
            'subtotal'         => 1000,
            'tax_rate'         => 18,
            'tax_amount'       => 180,
            'discount_amount'  => 0,
            'fbr_service_charge' => 0,
            'total_amount'     => 1180,
            'fbr_status'       => 'submitted',
            'fbr_invoice_number' => 'FBR-000401',
            'payment_breakdown' => $breakdown,
        ], $attrs));
        $txn->id         = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $item = new FbrPosTransactionItem([
            'item_name'    => 'Premium Widget',
            'quantity'     => 2,
            'unit_price'   => 500,
            'tax_rate'     => 18,
            'is_tax_exempt' => false,
            'subtotal'     => 1000,
            'total'        => 1180,
        ]);
        $item->id = 1;

        $txn->setRelation('items',   collect([$item]));
        $txn->setRelation('creator', null);
        $txn->setRelation('rider',   null);

        return $txn;
    }

    private function renderBlade(FbrPosTransaction $txn, Company $company): string
    {
        return view('fbr-pos.invoice-pdf', [
            'transaction' => $txn,
            'company'     => $company,
        ])->render();
    }

    // ════════════════════════════════════════════════════════════════════════
    // CONTROLLER-LEVEL TESTS — primary regression gate
    // ════════════════════════════════════════════════════════════════════════

    /**
     * downloadPdf path: the transaction the controller passes to loadView must
     * have payment_breakdown as an array with ≥2 entries for split bills.
     * If payment_breakdown is dropped from the query or the column definition
     * is removed, this test fails.
     */
    public function test_download_pdf_controller_passes_payment_breakdown_to_blade(): void
    {
        $this->seedSplitPaymentBill();

        $capturedData = null;
        $this->mockPdfAndCapture($capturedData);

        app()->instance('currentCompanyId', self::COMPANY_ID);

        $response = $this->withoutMiddleware()
            ->get('/fbr-pos/transaction/' . self::TXN_ID . '/pdf');

        $response->assertOk();

        $this->assertNotNull($capturedData, 'DomPDF loadView must be called with view data');

        /** @var FbrPosTransaction $txn */
        $txn = $capturedData['transaction'];

        $this->assertIsArray(
            $txn->payment_breakdown,
            'payment_breakdown must be an array (JSON cast must be applied)'
        );
        $this->assertGreaterThanOrEqual(
            2,
            count($txn->payment_breakdown),
            'Both seeded payment rows must be present in payment_breakdown'
        );
    }

    /**
     * previewPdf path: same assertion as downloadPdf.
     */
    public function test_preview_pdf_controller_passes_payment_breakdown_to_blade(): void
    {
        $this->seedSplitPaymentBill();

        $capturedData = null;
        $this->mockPdfAndCapture($capturedData);

        app()->instance('currentCompanyId', self::COMPANY_ID);

        $response = $this->withoutMiddleware()
            ->get('/fbr-pos/transaction/' . self::TXN_ID . '/pdf-preview');

        $response->assertOk();

        $this->assertNotNull($capturedData, 'DomPDF loadView must be called with view data');

        /** @var FbrPosTransaction $txn */
        $txn = $capturedData['transaction'];

        $this->assertIsArray($txn->payment_breakdown);
        $this->assertGreaterThanOrEqual(2, count($txn->payment_breakdown));
    }

    // ════════════════════════════════════════════════════════════════════════
    // BLADE-LEVEL TESTS — template correctness
    // ════════════════════════════════════════════════════════════════════════

    /**
     * A two-row split (cash + card) must produce the breakdown heading plus
     * labelled rows in the rendered HTML.
     */
    public function test_split_payment_shows_breakdown_heading_and_rows(): void
    {
        $company = $this->makeCompany();
        $txn     = $this->makeTxn($company, [
            ['method' => 'cash',       'amount' => 800],
            ['method' => 'debit_card', 'amount' => 380],
        ]);

        $html = $this->renderBlade($txn, $company);

        $this->assertStringContainsString(
            __('pos.payment_breakdown'),
            $html,
            'Split-payment bill must show the Payment Breakdown heading'
        );
        $this->assertStringContainsString(__('pos.receipt_pay_cash'), $html, 'Cash row must appear');
        $this->assertStringContainsString(__('pos.receipt_pay_card'), $html, 'Card row must appear');
        $this->assertStringContainsString('800.00', $html, 'Cash amount must appear');
        $this->assertStringContainsString('380.00', $html, 'Card amount must appear');
    }

    /**
     * A three-way split (cash + card + online) must show all three bucket labels.
     */
    public function test_three_way_split_shows_all_three_bucket_labels(): void
    {
        $company = $this->makeCompany();
        $txn     = $this->makeTxn($company, [
            ['method' => 'cash',       'amount' => 400],
            ['method' => 'debit_card', 'amount' => 400],
            ['method' => 'qr_payment', 'amount' => 380],
        ]);

        $html = $this->renderBlade($txn, $company);

        $this->assertStringContainsString(__('pos.payment_breakdown'), $html);
        $this->assertStringContainsString(__('pos.receipt_pay_cash'),  $html, 'Cash bucket');
        $this->assertStringContainsString(__('pos.receipt_pay_card'),  $html, 'Card bucket');
        $this->assertStringContainsString(__('pos.receipt_pay_other'), $html, 'Other bucket');
    }

    /**
     * Legacy 'card' (plain string, no debit/credit prefix) must collapse into
     * the Card bucket along with debit_card / credit_card.
     */
    public function test_legacy_plain_card_collapses_into_card_bucket(): void
    {
        $company = $this->makeCompany();
        $txn     = $this->makeTxn($company, [
            ['method' => 'cash', 'amount' => 600],
            ['method' => 'card', 'amount' => 580],   // legacy alias
        ]);

        $html = $this->renderBlade($txn, $company);

        $this->assertStringContainsString(__('pos.payment_breakdown'), $html);
        $this->assertStringContainsString(__('pos.receipt_pay_card'),  $html, 'Legacy card alias must map to Card bucket');
        // Should NOT show an 'other' row for 'card'
        $this->assertStringNotContainsString(__('pos.receipt_pay_other'), $html, 'card must not fall through to Other');
    }

    /**
     * A single-method bill (1 breakdown entry) must NOT show the breakdown
     * section — it adds no value and wastes paper.
     */
    public function test_single_method_bill_has_no_breakdown(): void
    {
        $company = $this->makeCompany();
        $txn     = $this->makeTxn($company, [
            ['method' => 'cash', 'amount' => 1180],
        ]);

        $html = $this->renderBlade($txn, $company);

        $this->assertStringNotContainsString(
            __('pos.payment_breakdown'),
            $html,
            'Single-method bill must not show Payment Breakdown heading'
        );
    }

    /**
     * null payment_breakdown (old bills created before the column existed) must
     * not show the breakdown section and must not throw.
     */
    public function test_null_payment_breakdown_skips_breakdown_section(): void
    {
        $company = $this->makeCompany();
        $txn     = $this->makeTxn($company, null);

        $html = $this->renderBlade($txn, $company);

        $this->assertStringNotContainsString(
            __('pos.payment_breakdown'),
            $html,
            'null payment_breakdown must not produce a breakdown section'
        );
    }

    /**
     * Empty array payment_breakdown must also skip the section gracefully.
     */
    public function test_empty_payment_breakdown_skips_breakdown_section(): void
    {
        $company = $this->makeCompany();
        $txn     = $this->makeTxn($company, []);

        $html = $this->renderBlade($txn, $company);

        $this->assertStringNotContainsString(__('pos.payment_breakdown'), $html);
    }

    /**
     * Two raw rows where both aliases collapse to the same display bucket
     * (debit_card + credit_card → Card) must still show the breakdown section
     * because there were ≥2 raw payment rows — the guard is on the raw count,
     * not the aggregated bucket count.  The heading and one summed Card row
     * must appear.  Mirrors the PRA receipt_80mm / receipt_58mm behavior.
     */
    public function test_two_card_alias_rows_show_breakdown_with_one_summed_card_row(): void
    {
        $company = $this->makeCompany();
        $txn     = $this->makeTxn($company, [
            ['method' => 'debit_card',  'amount' => 600],
            ['method' => 'credit_card', 'amount' => 580],
        ]);

        $html = $this->renderBlade($txn, $company);

        // ≥2 raw rows → breakdown heading must appear even though both collapse
        // into the same Card bucket.
        $this->assertStringContainsString(
            __('pos.payment_breakdown'),
            $html,
            'Two card-alias raw rows must trigger the breakdown heading'
        );
        $this->assertStringContainsString(
            __('pos.receipt_pay_card'),
            $html,
            'One summed Card row must appear'
        );
        // Summed amount 600 + 580 = 1,180.00
        $this->assertStringContainsString(
            '1,180.00',
            $html,
            'debit_card + credit_card amounts must be summed into one Card row'
        );
        // No Other row and no Cash row in the breakdown.
        $this->assertStringNotContainsString(__('pos.receipt_pay_other'), $html);
    }

    /**
     * cash + debit_card + credit_card → 2 output buckets (Cash + Card).
     * The Card row must appear ONCE (debit + credit collapsed) and show the
     * summed amount; an 'Other' row must not appear.
     */
    public function test_mixed_cash_and_card_aliases_collapse_into_two_buckets(): void
    {
        $company = $this->makeCompany();
        $txn     = $this->makeTxn($company, [
            ['method' => 'cash',        'amount' => 400],
            ['method' => 'debit_card',  'amount' => 500],
            ['method' => 'credit_card', 'amount' => 280],
        ]);

        $html = $this->renderBlade($txn, $company);

        // Breakdown section must appear (2 output buckets: Cash + Card).
        $this->assertStringContainsString(__('pos.payment_breakdown'), $html);
        $this->assertStringContainsString(__('pos.receipt_pay_cash'),  $html, 'Cash bucket must appear');
        $this->assertStringContainsString(__('pos.receipt_pay_card'),  $html, 'Card bucket must appear');
        $this->assertStringNotContainsString(__('pos.receipt_pay_other'), $html, 'No other bucket expected');

        // Summed card amount (500 + 280 = 780) must appear in the breakdown row.
        $this->assertStringContainsString('780.00', $html, 'debit_card + credit_card must be summed into one Card row');
    }
}
