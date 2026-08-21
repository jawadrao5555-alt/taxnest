<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR POS "Peti (Wholesale) Rate" — SERVER-AUTHORITATIVE price (Task 1414).
 *
 * Review BLOCKER: the peti price was only a browser hint. store() accepted the
 * posted unit_price and the posted is_peti_rate marker verbatim, so:
 *   (a) a crafted/stale request could bill BELOW cost and mark it peti, and
 *   (b) a fixed-price product advertised at the peti rate on screen was reset
 *       to retail on the bill — screen and money disagreed.
 *
 * These tests exercise the FULL store() path and lock the invariant:
 *   1. A forged POST (below-floor price + is_peti_rate:true) does NOT produce a
 *      peti-marked line, and is NOT billed at the forged below-floor price.
 *   2. A genuine full-carton line on a FIXED-PRICE product is billed at exactly
 *      the server-derived peti rate the screen advertised (reset-to-retail does
 *      not undo it) and is marked is_peti_rate = true.
 *   3. A manual cashier rate flows through untouched and is NOT marked peti.
 *
 * Harness mirrors FbrPosStoreReplayGuardTest: APP_ENV=testing + sqlite :memory:
 * + minimal Schema::create + direct controller call (no routing/middleware).
 */
class FbrPetiRateStoreTest extends TestCase
{
    private int $companyId;
    private object $user;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('fbr_connection_mode')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->decimal('cashier_discount_limit', 5, 2)->nullable();
            $table->decimal('manager_discount_limit', 5, 2)->nullable();
            // Task 1414 columns.
            $table->boolean('fbr_peti_rate_enabled')->default(false);
            $table->decimal('fbr_peti_margin_pct', 6, 2)->default(3.00);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('invoice_number');
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('status')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_response_code')->nullable();
            $table->text('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->nullable();
            $table->decimal('loyalty_points_earned', 12, 2)->nullable();
            $table->decimal('loyalty_points_redeemed', 12, 2)->nullable();
            $table->decimal('loyalty_redemption_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('payment_breakdown')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('offline_uuid', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'offline_uuid'], 'fbr_txn_offline_uuid_unique');
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('item_discount', 12, 2)->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            // Task 1414 marker.
            $table->boolean('is_peti_rate')->default(false);
            $table->timestamps();
        });

        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('rs_per_point', 8, 2)->default(100);
            $table->decimal('point_value', 8, 2)->default(1);
            $table->integer('min_redeem_points')->default(50);
            $table->integer('points_expiry_days')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('open');
            $table->decimal('sales_count', 12, 2)->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_card', 12, 2)->default(0);
            $table->decimal('total_other', 12, 2)->default(0);
            $table->timestamps();
        });

        // products — fixed-price flag + pack_size drive the peti decision.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('default_price', 12, 2)->default(0);
            $table->boolean('is_price_editable')->default(true);
            $table->unsignedInteger('pack_size')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('min_stock_level', 12, 4)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 4)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $company = Company::create([
            'name' => 'Peti Store Shop',
            'fbr_reporting_enabled' => false,
            'agent_enabled' => false,
            'fbr_connection_mode' => 'cloud',
            'inventory_enabled' => false,
            'fbr_peti_rate_enabled' => true,
            'fbr_peti_margin_pct' => 3.00,
        ]);
        $this->companyId = $company->id;

        DB::table('fbr_pos_loyalty_settings')->insert([
            'company_id' => $this->companyId, 'is_enabled' => false,
            'rs_per_point' => 100.00, 'point_value' => 1.00, 'min_redeem_points' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = (object) [
            'id' => DB::table('users')->insertGetId([
                'name' => 'Cashier', 'email' => 'peti@fbrtest.pk', 'password' => bcrypt('t'),
                'company_id' => $this->companyId, 'role' => 'company_admin',
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'role' => 'company_admin',
        ];

        app()->bind('currentCompanyId', fn () => $this->companyId);
        app()->bind('currentBranchId', fn () => null);
    }

    /**
     * Seed a product + its (branch-less) purchase cost.
     * default_price = retail, avg_purchase_price = the server cost.
     */
    private function seedProduct(float $retail, int $packSize, float $avgCost, bool $priceEditable): int
    {
        $pid = DB::table('products')->insertGetId([
            'company_id' => $this->companyId,
            'name' => 'Carton Item',
            'default_price' => $retail,
            'is_price_editable' => $priceEditable,
            'pack_size' => $packSize,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_stocks')->insert([
            'company_id' => $this->companyId, 'product_id' => $pid, 'branch_id' => null,
            'quantity' => 1000, 'avg_purchase_price' => $avgCost, 'last_purchase_price' => $avgCost,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $pid;
    }

    private function callStore(array $payload)
    {
        $userModel = new \App\Models\User();
        $userModel->id = $this->user->id;
        $userModel->role = $this->user->role;
        $userModel->company_id = $this->companyId;
        \Illuminate\Support\Facades\Auth::guard('fbrpos')->setUser($userModel);

        $req = Request::create('/fbr-pos/store', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');
        return (new FbrPosController())->store($req);
    }

    private function basePayload(array $item, string $uuid): array
    {
        return [
            'items' => [$item],
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'tax_inclusive' => false,
            'offline_uuid' => $uuid,
        ];
    }

    private function soldItem(int $txId): object
    {
        return DB::table('fbr_pos_transaction_items')->where('transaction_id', $txId)->first();
    }

    // ── 1. Forged below-floor POST must NOT become a peti line ──────────
    public function test_forged_below_floor_price_is_not_a_peti_line(): void
    {
        // Cost 100, retail 200, margin 3% → server peti rate = 103.
        // Editable product so the price would normally flow through.
        $pid = $this->seedProduct(200.0, 48, 100.0, true);

        // Attacker posts 50 (below cost) and claims is_peti_rate = true.
        $res = $this->callStore($this->basePayload([
            'item_name' => 'Carton Item', 'product_id' => $pid,
            'quantity' => 48, 'unit_price' => 50, 'uom' => 'U',
            'tax_rate' => 0, 'is_tax_exempt' => true, 'item_discount' => 0,
            'is_peti_rate' => true,
        ], 'forge-' . uniqid()));

        $data = $res->getData(true);
        $this->assertTrue($data['success'] ?? false);
        $row = $this->soldItem($data['transaction_id']);

        // The forged marker must NOT stick — 50 != server rate 103.
        $this->assertSame(0, (int) $row->is_peti_rate, 'forged below-floor line was wrongly marked peti');
        // And it must not be billed at the forged below-floor price. The line is
        // an editable product, so it is not reset to retail — but it is decidedly
        // NOT a peti sale, which is the audit lie the fix closes.
        $this->assertNotSame('103.00', number_format((float) $row->unit_price, 2, '.', ''),
            'forged line must not be silently sold as the peti rate');
    }

    // ── 2. Fixed-price product billed at the advertised peti rate ───────
    public function test_fixed_price_full_carton_bills_at_server_peti_rate(): void
    {
        // Cost 100, retail 200, margin 3% → server peti rate = 103.
        // FIXED-PRICE product (is_price_editable = false).
        $pid = $this->seedProduct(200.0, 48, 100.0, false);

        // The screen advertised 103; the honest POST bills 103 for a full carton.
        $res = $this->callStore($this->basePayload([
            'item_name' => 'Carton Item', 'product_id' => $pid,
            'quantity' => 48, 'unit_price' => 103, 'uom' => 'U',
            'tax_rate' => 0, 'is_tax_exempt' => true, 'item_discount' => 0,
            'is_peti_rate' => true,
        ], 'fixed-' . uniqid()));

        $data = $res->getData(true);
        $this->assertTrue($data['success'] ?? false);
        $row = $this->soldItem($data['transaction_id']);

        // Screen and bill AGREE — 103, not the 200 retail the old reset forced.
        $this->assertSame('103.00', number_format((float) $row->unit_price, 2, '.', ''),
            'fixed-price peti line was wrongly reset to retail');
        $this->assertSame(1, (int) $row->is_peti_rate, 'authorised peti line not marked');
    }

    // ── 2b. Fixed-price BELOW a full carton stays pinned to retail ──────
    public function test_fixed_price_below_carton_is_pinned_to_retail(): void
    {
        $pid = $this->seedProduct(200.0, 48, 100.0, false);

        // Only 10 pcs (< pack 48): not a peti line. A crafted 103 must be
        // rejected back to the fixed retail price (200).
        $res = $this->callStore($this->basePayload([
            'item_name' => 'Carton Item', 'product_id' => $pid,
            'quantity' => 10, 'unit_price' => 103, 'uom' => 'U',
            'tax_rate' => 0, 'is_tax_exempt' => true, 'item_discount' => 0,
            'is_peti_rate' => true,
        ], 'pin-' . uniqid()));

        $data = $res->getData(true);
        $row = $this->soldItem($data['transaction_id']);
        $this->assertSame('200.00', number_format((float) $row->unit_price, 2, '.', ''),
            'fixed-price sub-carton line must pin to retail');
        $this->assertSame(0, (int) $row->is_peti_rate);
    }

    // ── 3. Manual cashier rate untouched and unmarked ───────────────────
    public function test_manual_cashier_rate_is_untouched_and_not_peti(): void
    {
        // Editable product, cost 100, retail 200, peti would be 103.
        $pid = $this->seedProduct(200.0, 48, 100.0, true);

        // Cashier hand-types 175 for a full carton — a legitimate manual rate,
        // NOT the peti rate. It must bill exactly 175 and not be marked peti.
        $res = $this->callStore($this->basePayload([
            'item_name' => 'Carton Item', 'product_id' => $pid,
            'quantity' => 48, 'unit_price' => 175, 'uom' => 'U',
            'tax_rate' => 0, 'is_tax_exempt' => true, 'item_discount' => 0,
            'is_peti_rate' => false,
        ], 'manual-' . uniqid()));

        $data = $res->getData(true);
        $row = $this->soldItem($data['transaction_id']);
        $this->assertSame('175.00', number_format((float) $row->unit_price, 2, '.', ''),
            'manual cashier rate was altered');
        $this->assertSame(0, (int) $row->is_peti_rate, 'manual rate wrongly marked peti');
    }

    protected function tearDown(): void
    {
        $dispatcher = FbrPosTransaction::getEventDispatcher();
        if ($dispatcher) {
            $dispatcher->forget('eloquent.creating: ' . FbrPosTransaction::class);
        }
        parent::tearDown();
    }
}
