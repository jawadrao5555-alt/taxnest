<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentController;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Services\PraIntegrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRA zero-rated exempt items (Task 760, owner decision 15 Aug 2026).
 *
 * Exempt-marked items are no longer STRIPPED from the PRA payload — they are
 * reported at TaxRate 0 / TaxCharged 0 (competitor parity: LinksXpert at ZFC).
 * Locks the new invariants:
 *   1. Exempt-only bill: full payload with every line at TaxRate 0,
 *      TotalBillAmount = stored whole-rupee total → bill gets fiscal # + QR.
 *   2. Mixed bill: ALL lines present (exempt at 0%, taxable at its rate);
 *      header totals now match the printed receipt grand total, including the
 *      whole-rupee paisa-absorption (into a TAXABLE line's TaxCharged).
 *   3. Tax-inclusive bills: exempt menu money carries NO embedded tax — the
 *      exempt line is NEVER divided out (classic or card-save/menu-rate mode).
 *   4. sendInvoice no longer stamps 'exempt_internal' — an all-exempt bill in
 *      fiscal-device mode queues 'pending' for the agent like any other bill.
 *   5. Historical 'exempt_internal' rows are never retro-submitted.
 *   6. AgentController::pendingInvoices hands the agent all-exempt bills
 *      (old skip-and-stamp mirror is gone).
 *   7. Reporting-OFF bills still stay local (isEnabled guard unchanged).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (mirrors PosPraReturnFlowTest).
 */
class PosPraExemptZeroRatedTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->text('pra_production_token')->nullable();
            $table->string('pra_proxy_url')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->text('pra_error_message')->nullable();
            $table->string('submission_hash')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Exempt Shop',
            'pra_reporting_enabled' => 1,
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $items */
    private function makeBill(array $header, array $items): PosTransaction
    {
        $txnId = DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'INV-' . uniqid(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ], $header));

        foreach ($items as $item) {
            DB::table('pos_transaction_items')->insert(array_merge([
                'transaction_id' => $txnId,
                'item_type' => 'product',
                'quantity' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $item));
        }

        return PosTransaction::withoutGlobalScope('hide_archived')->findOrFail($txnId);
    }

    private function service(): PraIntegrationService
    {
        return new PraIntegrationService(Company::find($this->companyId));
    }

    private function exemptLine(array $payload): array
    {
        foreach ($payload['Items'] as $line) {
            if ((float) $line['TaxRate'] === 0.0) {
                return $line;
            }
        }
        $this->fail('no zero-rated line found in payload');
    }

    // ── 1. exempt-only bill ──────────────────────────────────────────────────

    public function test_exempt_only_bill_payload_is_zero_rated_full_bill(): void
    {
        // One Rs. 80 bottle, exempt (the ZFC case).
        $txn = $this->makeBill(
            ['subtotal' => 80, 'tax_rate' => 16, 'tax_amount' => 0, 'exempt_amount' => 80, 'total_amount' => 80],
            [['item_name' => 'Water Bottle', 'item_id' => 5, 'unit_price' => 80, 'subtotal' => 80, 'is_tax_exempt' => 1, 'tax_rate' => 0]]
        );

        $payload = $this->service()->generatePayload($txn);

        $this->assertCount(1, $payload['Items'], 'exempt line must be INCLUDED (zero-rated), not stripped');
        $line = $payload['Items'][0];
        $this->assertSame(0.0, (float) $line['TaxRate']);
        $this->assertSame(0.0, (float) $line['TaxCharged']);
        $this->assertSame(80.0, (float) $line['SaleValue']);
        $this->assertSame(80.0, (float) $line['TotalAmount']);
        $this->assertSame(80.0, (float) $payload['TotalSaleValue']);
        $this->assertSame(0.0, (float) $payload['TotalTaxCharged']);
        $this->assertSame(80.0, (float) $payload['TotalBillAmount'], 'reported bill = stored receipt total');
        $this->assertSame(1.0, (float) $payload['TotalQuantity']);
    }

    // ── 2. mixed bill ────────────────────────────────────────────────────────

    public function test_mixed_bill_reports_all_lines_and_matches_stored_total(): void
    {
        // Taxable 100 @16 (tax 16) + exempt 80 → stored whole-rupee total 196.
        $txn = $this->makeBill(
            ['subtotal' => 180, 'tax_rate' => 16, 'tax_amount' => 16, 'exempt_amount' => 80, 'total_amount' => 196],
            [
                ['item_name' => 'Burger', 'item_id' => 1, 'unit_price' => 100, 'subtotal' => 100, 'is_tax_exempt' => 0, 'tax_rate' => 16],
                ['item_name' => 'Water Bottle', 'item_id' => 2, 'unit_price' => 80, 'subtotal' => 80, 'is_tax_exempt' => 1, 'tax_rate' => 0],
            ]
        );

        $payload = $this->service()->generatePayload($txn);

        $this->assertCount(2, $payload['Items'], 'mixed bill must report ALL lines');
        $exempt = $this->exemptLine($payload);
        $this->assertSame(0.0, (float) $exempt['TaxCharged']);
        $this->assertSame(80.0, (float) $exempt['SaleValue']);
        $this->assertSame(180.0, (float) $payload['TotalSaleValue']);
        $this->assertSame(16.0, (float) $payload['TotalTaxCharged']);
        $this->assertSame(196.0, (float) $payload['TotalBillAmount'], 'PRA-verified total = receipt grand total');
        $this->assertSame(2.0, (float) $payload['TotalQuantity'], 'TotalQuantity includes exempt lines');
    }

    public function test_mixed_bill_paisa_drift_absorbed_into_taxable_line(): void
    {
        // Taxable 580.07 @16 → tax 92.81 (2dp), exempt 80. Stored whole-rupee
        // total = round(580.07 + 92.8112 + 80) = 753. Raw line sums give 752.88;
        // the 0.12 drift must land in the TAXABLE line's TaxCharged, never on
        // the exempt line (its TaxCharged must stay exactly 0).
        $txn = $this->makeBill(
            ['subtotal' => 660.07, 'tax_rate' => 16, 'tax_amount' => 92.93, 'exempt_amount' => 80, 'total_amount' => 753],
            [
                ['item_name' => 'Deal', 'item_id' => 1, 'unit_price' => 580.07, 'subtotal' => 580.07, 'is_tax_exempt' => 0, 'tax_rate' => 16],
                ['item_name' => 'Water Bottle', 'item_id' => 2, 'unit_price' => 80, 'subtotal' => 80, 'is_tax_exempt' => 1, 'tax_rate' => 0],
            ]
        );

        $payload = $this->service()->generatePayload($txn);

        $this->assertSame(753.0, (float) $payload['TotalBillAmount'], 'payload mirrors stored whole-rupee total');
        $exempt = $this->exemptLine($payload);
        $this->assertSame(0.0, (float) $exempt['TaxCharged'], 'drift never absorbed into an exempt line');
        $this->assertSame(80.0, (float) $exempt['TotalAmount']);
        $sum = round(array_sum(array_column($payload['Items'], 'TotalAmount')), 2);
        $this->assertSame(753.0, $sum, 'Items still sum exactly to TotalBillAmount');
    }

    // ── 3. tax-inclusive bills ───────────────────────────────────────────────

    public function test_inclusive_bill_exempt_line_is_not_divided_out(): void
    {
        // Menu prices: taxable 116 (16% inside) + exempt bottle 80 (NO tax inside).
        $txn = $this->makeBill(
            ['subtotal' => 180, 'tax_rate' => 16, 'tax_amount' => 16, 'exempt_amount' => 80, 'total_amount' => 196, 'tax_inclusive' => 1],
            [
                ['item_name' => 'Burger', 'item_id' => 1, 'unit_price' => 116, 'subtotal' => 116, 'is_tax_exempt' => 0, 'tax_rate' => 16],
                ['item_name' => 'Water Bottle', 'item_id' => 2, 'unit_price' => 80, 'subtotal' => 80, 'is_tax_exempt' => 1, 'tax_rate' => 0],
            ]
        );

        $payload = $this->service()->generatePayload($txn);

        $exempt = $this->exemptLine($payload);
        $this->assertSame(80.0, (float) $exempt['SaleValue'], 'exempt menu money has no embedded tax — never divided');
        $this->assertSame(0.0, (float) $exempt['TaxCharged']);
        $this->assertSame(80.0, (float) $exempt['TotalAmount']);
        $this->assertSame(196.0, (float) $payload['TotalBillAmount']);
        $this->assertSame(16.0, (float) $payload['TotalTaxCharged']);
    }

    public function test_inclusive_card_save_menu_rate_bill_keeps_exempt_line_whole(): void
    {
        // Card-save mode: menu rate 16, bill's own (card) rate 5. Taxable menu
        // line 116 → SaleValue 100, TaxCharged 5, TotalAmount 105. The exempt
        // line must NOT take the divide-out branch: stays 80 / 0 / 80.
        // Stored total = 105 + 80 = 185.
        $txn = $this->makeBill(
            [
                'subtotal' => 180, 'tax_rate' => 5, 'tax_amount' => 5, 'exempt_amount' => 80,
                'total_amount' => 185, 'tax_inclusive' => 1, 'tax_menu_rate' => 16,
                'payment_method' => 'debit_card',
            ],
            [
                ['item_name' => 'Burger', 'item_id' => 1, 'unit_price' => 116, 'subtotal' => 116, 'is_tax_exempt' => 0, 'tax_rate' => 5],
                ['item_name' => 'Water Bottle', 'item_id' => 2, 'unit_price' => 80, 'subtotal' => 80, 'is_tax_exempt' => 1, 'tax_rate' => 0],
            ]
        );

        $payload = $this->service()->generatePayload($txn);

        $exempt = $this->exemptLine($payload);
        $this->assertSame(80.0, (float) $exempt['SaleValue'], 'card-save divide-out must skip exempt lines');
        $this->assertSame(0.0, (float) $exempt['TaxCharged']);
        $this->assertSame(80.0, (float) $exempt['TotalAmount']);
        $this->assertSame(185.0, (float) $payload['TotalBillAmount'], 'bill sums to what the card customer pays');
    }

    // ── 4./5. sendInvoice semantics ──────────────────────────────────────────

    public function test_all_exempt_bill_submits_normally_no_exempt_internal_stamp(): void
    {
        // fiscal_device mode: sendInvoice queues for the agent — no network.
        DB::table('companies')->where('id', $this->companyId)
            ->update(['pra_connection_mode' => 'fiscal_device']);

        $txn = $this->makeBill(
            ['subtotal' => 80, 'tax_amount' => 0, 'exempt_amount' => 80, 'total_amount' => 80],
            [['item_name' => 'Water Bottle', 'item_id' => 5, 'unit_price' => 80, 'subtotal' => 80, 'is_tax_exempt' => 1, 'tax_rate' => 0]]
        );

        $result = $this->service()->sendInvoice($txn);

        $this->assertTrue((bool) ($result['queued_for_agent'] ?? false),
            'all-exempt bill must enter the normal submission pipeline');
        $fresh = DB::table('pos_transactions')->where('id', $txn->id)->first();
        $this->assertSame('pending', $fresh->pra_status,
            "all-exempt bill must queue 'pending' — the exempt_internal short-circuit is gone (Task 760)");
    }

    public function test_historical_exempt_internal_bill_is_never_resubmitted(): void
    {
        $txn = $this->makeBill(
            ['subtotal' => 80, 'exempt_amount' => 80, 'total_amount' => 80, 'pra_status' => 'exempt_internal'],
            [['item_name' => 'Water Bottle', 'item_id' => 5, 'unit_price' => 80, 'subtotal' => 80, 'is_tax_exempt' => 1, 'tax_rate' => 0]]
        );

        $result = $this->service()->sendInvoice($txn);

        $this->assertFalse((bool) ($result['success'] ?? true));
        $fresh = DB::table('pos_transactions')->where('id', $txn->id)->first();
        $this->assertSame('exempt_internal', $fresh->pra_status, 'historical row stays untouched (no retro-submission)');
        $this->assertNull($fresh->pra_invoice_number);
    }

    // ── 6. Desktop Agent path ────────────────────────────────────────────────

    public function test_agent_pending_invoices_hands_over_all_exempt_bill_zero_rated(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'pra_connection_mode' => 'fiscal_device',
            'agent_enabled' => 1,
            'agent_submits_pra' => 1,
        ]);

        $txn = $this->makeBill(
            ['subtotal' => 80, 'tax_amount' => 0, 'exempt_amount' => 80, 'total_amount' => 80, 'pra_status' => 'pending'],
            [['item_name' => 'Water Bottle', 'item_id' => 5, 'unit_price' => 80, 'subtotal' => 80, 'is_tax_exempt' => 1, 'tax_rate' => 0]]
        );

        $request = Request::create('/api/agent/pending-invoices', 'GET');
        $request->attributes->set('agent_company', Company::find($this->companyId));

        $response = (new AgentController())->pendingInvoices($request);
        $data = $response->getData(true);

        $this->assertSame(1, $data['count'], 'agent must receive the all-exempt bill (old skip is gone)');
        $line = $data['invoices'][0]['payload']['Items'][0];
        $this->assertSame(0.0, (float) $line['TaxRate']);
        $this->assertSame(0.0, (float) $line['TaxCharged']);
        $this->assertSame(80.0, (float) $data['invoices'][0]['payload']['TotalBillAmount']);

        $fresh = DB::table('pos_transactions')->where('id', $txn->id)->first();
        $this->assertSame('pending', $fresh->pra_status,
            'agent poll must NOT stamp exempt_internal anymore');
    }

    // ── 7. reporting-OFF unchanged ───────────────────────────────────────────

    public function test_reporting_off_all_exempt_bill_stays_local(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['pra_reporting_enabled' => 0]);

        $txn = $this->makeBill(
            ['subtotal' => 80, 'exempt_amount' => 80, 'total_amount' => 80],
            [['item_name' => 'Water Bottle', 'item_id' => 5, 'unit_price' => 80, 'subtotal' => 80, 'is_tax_exempt' => 1, 'tax_rate' => 0]]
        );

        $result = $this->service()->sendInvoice($txn);

        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertStringContainsString('disabled', strtolower($result['message'] ?? ''));
        $fresh = DB::table('pos_transactions')->where('id', $txn->id)->first();
        $this->assertNull($fresh->pra_status, 'reporting-OFF final keeps NULL status (local category)');
        $this->assertNull($fresh->pra_invoice_number);
    }
}
