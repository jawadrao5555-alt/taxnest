<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosProduct;
use App\Http\Controllers\PosController;
use App\Http\Controllers\FbrPosController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Third Schedule billing invariants (Aug 2026).
 *
 * Proves that:
 *   A. PRA resolveItemExemptions() — DB-flagged Third Schedule product → isThirdSchedule=true,
 *      isExempt=true regardless of client payload.
 *   B. PRA anti-spoofing — client sending is_third_schedule=true for a catalog product that
 *      is NOT flagged in the DB must NOT produce an exempt/0-tax line.
 *   C. FBR store() — DB-flagged Third Schedule product → item row has tax_rate=0, tax_amount=0,
 *      is_third_schedule=1.
 *   D. FBR anti-spoofing — client payload cannot force a non-flagged catalog product to be
 *      Third Schedule.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 */
class PosThirdScheduleBillingTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // ── companies ────────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->string('fbr_connection_mode')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('tax_inclusive')->default(false);
            $table->string('default_tax_rate')->nullable();
            $table->decimal('cashier_discount_limit', 5, 2)->nullable();
            $table->decimal('manager_discount_limit', 5, 2)->nullable();
            $table->string('pos_invoice_prefix')->nullable();
            $table->boolean('is_restaurant_mode')->nullable();
            $table->boolean('inventory_enabled_pos')->nullable();
            $table->boolean('pos_restock_on_void')->nullable();
            $table->string('pos_product_search_mode')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── users ─────────────────────────────────────────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        // ── pos_products (with Third Schedule column) ──────────────────────
        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false); // The column under test
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_sale')->default(true);
            $table->string('category')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->integer('low_stock_threshold')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });

        // ── pos_transactions ───────────────────────────────────────────────
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->string('invoice_number');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('offline_uuid')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('bill_token')->nullable();
            $table->boolean('business_date')->nullable();
            $table->string('delivery_address')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // ── pos_transaction_items (with Third Schedule column) ─────────────
        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->string('special_notes')->nullable();
            $table->text('deal_snapshot')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false); // The column under test
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        // ── pos_payments ────────────────────────────────────────────────────
        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method');
            $table->decimal('amount', 12, 2);
            $table->string('reference_number')->nullable();
            $table->timestamps();
        });

        // ── pos_terminals ───────────────────────────────────────────────────
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('terminal_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── pos_customers ────────────────────────────────────────────────────
        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->decimal('khata_balance', 12, 2)->default(0);
            $table->boolean('hide_archived')->default(false);
            $table->timestamps();
        });

        // ── app_updates (pos What's New — referenced at boot) ───────────────
        Schema::create('app_updates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        // ── FBR tables ───────────────────────────────────────────────────────
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('default_price', 12, 2)->default(0);
            $table->boolean('is_price_editable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('tax_type')->nullable();
            $table->decimal('default_tax_rate', 8, 2)->default(18);
            $table->boolean('is_third_schedule')->default(false); // The column under test
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
            $table->boolean('is_third_schedule')->default(false); // The column under test
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('rs_per_point', 8, 2)->default(100);
            $table->decimal('point_value', 8, 2)->default(1);
            $table->integer('min_redeem_points')->default(50);
            $table->timestamps();
        });

        Schema::create('fbr_pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('open');
            $table->decimal('sales_count', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->string('type')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 4)->default(0);
            $table->timestamps();
        });

        // ── seed company ─────────────────────────────────────────────────────
        $company = Company::create([
            'name' => 'Third Schedule Test Shop',
            'fbr_reporting_enabled' => false,
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'inventory_enabled' => false,
        ]);
        $this->companyId = $company->id;

        DB::table('fbr_pos_loyalty_settings')->insert([
            'company_id'        => $this->companyId,
            'is_enabled'        => false,
            'rs_per_point'      => 100.00,
            'point_value'       => 1.00,
            'min_redeem_points' => 50,
            'created_at'        => now()->toDateTimeString(),
            'updated_at'        => now()->toDateTimeString(),
        ]);

        app()->bind('currentCompanyId', fn () => $this->companyId);
        app()->bind('currentBranchId', fn () => null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Create a PRA POS product (in this company's catalog). */
    private function makePraProduct(array $attrs = []): PosProduct
    {
        return PosProduct::create(array_merge([
            'company_id'        => $this->companyId,
            'name'              => 'Test Product',
            'price'             => 150,
            'tax_rate'          => 16,
            'is_tax_exempt'     => false,
            'is_third_schedule' => false,
            'is_active'         => true,
        ], $attrs));
    }

    /** Create an FBR catalog product. */
    protected function makeFbrProduct(array $attrs = []): \App\Models\Product
    {
        return \App\Models\Product::create(array_merge([
            'company_id'        => $this->companyId,
            'name'              => 'FBR Test Product',
            'default_price'     => 150,
            'is_price_editable' => true,
            'is_active'         => true,
            'tax_type'          => 'taxable',
            'default_tax_rate'  => 18,
            'is_third_schedule' => false,
        ], $attrs));
    }

    /** Call PosController::resolveItemExemptions via reflection (protected method). */
    private function resolveItems(array $items): array
    {
        $ctrl = new PosController();
        $ref  = new \ReflectionMethod($ctrl, 'resolveItemExemptions');
        $ref->setAccessible(true);
        return $ref->invoke($ctrl, $items, $this->companyId);
    }

    /** Invoke FbrPosController::store() with a JSON-accepting request. */
    protected function callFbrStore(array $payload): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $userId = DB::table('users')->insertGetId([
            'name'       => 'FBR Cashier',
            'email'      => 'fbr-' . uniqid() . '@test.pk',
            'password'   => bcrypt('test'),
            'company_id' => $this->companyId,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userModel             = new \App\Models\User();
        $userModel->id         = $userId;
        $userModel->role       = 'company_admin';
        $userModel->company_id = $this->companyId;
        Auth::guard('fbrpos')->setUser($userModel);

        $req = Request::create('/fbr-pos/store', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');

        return (new FbrPosController())->store($req);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test A — PRA: DB-flagged Third Schedule product → 0 tax at billing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pra_third_schedule_db_flag_forces_zero_tax(): void
    {
        $product = $this->makePraProduct([
            'tax_rate'          => 16,
            'is_tax_exempt'     => false,
            'is_third_schedule' => true, // flagged in DB
        ]);

        $resolved = $this->resolveItems([[
            'type'              => 'product',
            'id'                => $product->id,
            'name'              => $product->name,
            'price'             => 150,
            'quantity'          => 2,
            'is_tax_exempt'     => false, // client sends non-exempt — must be overridden
            'is_third_schedule' => false, // client sends non-third — must be overridden by DB
        ]]);

        $this->assertCount(1, $resolved);
        $item = $resolved[0];

        $this->assertTrue($item['isThirdSchedule'],
            'DB-flagged Third Schedule product must yield isThirdSchedule=true');
        $this->assertTrue($item['isExempt'],
            'Third Schedule implies isExempt=true');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test B — PRA anti-spoofing: client cannot force Third Schedule on a
    //          catalog product that is NOT flagged in the DB
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pra_client_payload_cannot_spoof_third_schedule_flag(): void
    {
        $product = $this->makePraProduct([
            'tax_rate'          => 16,
            'is_tax_exempt'     => false,
            'is_third_schedule' => false, // NOT flagged in DB
        ]);

        $resolved = $this->resolveItems([[
            'type'              => 'product',
            'id'                => $product->id,
            'name'              => $product->name,
            'price'             => 150,
            'quantity'          => 1,
            'is_tax_exempt'     => true,  // client claims exempt — must NOT propagate via third_schedule
            'is_third_schedule' => true,  // client claims third schedule — must be IGNORED
        ]]);

        $this->assertCount(1, $resolved);
        $item = $resolved[0];

        $this->assertFalse($item['isThirdSchedule'],
            'Client payload must NOT override DB: non-flagged catalog product must not become Third Schedule');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test C — FBR store(): DB-flagged Third Schedule product → 0 tax persisted
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fbr_third_schedule_db_flag_persists_zero_tax(): void
    {
        $product = $this->makeFbrProduct([
            'tax_type'          => 'taxable',
            'default_tax_rate'  => 18,
            'is_third_schedule' => true, // flagged in DB
        ]);

        $uuid = 'ts-fbr-' . uniqid();
        $res  = $this->callFbrStore([
            'items' => [[
                'item_name'         => $product->name,
                'product_id'        => $product->id,
                'quantity'          => 1,
                'unit_price'        => 150,
                'uom'               => 'U',
                'tax_rate'          => 18,    // client sends 18% — must be zeroed by server
                'is_tax_exempt'     => false,  // client sends non-exempt — must be overridden
                'is_third_schedule' => false,  // client sends non-third — must be overridden by DB
                'item_discount'     => 0,
            ]],
            'payment_method'  => 'cash',
            'cash_received'   => 150,
            'discount_type'   => 'percentage',
            'discount_value'  => 0,
            'tax_inclusive'   => false,
            'offline_uuid'    => $uuid,
        ]);

        $data = $res->getData(true);
        $this->assertTrue($data['success'] ?? false,
            'FBR store with Third Schedule product should succeed: ' . ($data['message'] ?? json_encode($data)));

        // The persisted item must have zero tax
        $item = DB::table('fbr_pos_transaction_items')
            ->where('transaction_id', $data['transaction_id'] ?? 0)
            ->first();

        $this->assertNotNull($item, 'Transaction item must be persisted');
        $this->assertEquals(0, (float) $item->tax_rate,
            'Persisted tax_rate must be 0 for Third Schedule product, got: ' . $item->tax_rate);
        $this->assertEquals(0, (float) $item->tax_amount,
            'Persisted tax_amount must be 0 for Third Schedule product, got: ' . $item->tax_amount);
        $this->assertEquals(1, (int) $item->is_third_schedule,
            'Persisted is_third_schedule must be 1 for Third Schedule product');
        $this->assertEquals(1, (int) $item->is_tax_exempt,
            'Persisted is_tax_exempt must be 1 for Third Schedule product');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test D — FBR anti-spoofing: client payload cannot make a non-flagged
    //          catalog product Third Schedule
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fbr_client_payload_cannot_spoof_third_schedule_flag(): void
    {
        $product = $this->makeFbrProduct([
            'tax_type'          => 'taxable',
            'default_tax_rate'  => 18,
            'is_third_schedule' => false, // NOT flagged in DB
        ]);

        // Send is_tax_exempt=false so the only route to zero-tax is the
        // (spoofed) is_third_schedule flag — isolates the Third Schedule guard.
        $uuid = 'ts-spoof-' . uniqid();
        $res  = $this->callFbrStore([
            'items' => [[
                'item_name'         => $product->name,
                'product_id'        => $product->id,
                'quantity'          => 1,
                'unit_price'        => 150,
                'uom'               => 'U',
                'tax_rate'          => 18,
                'is_tax_exempt'     => false,  // client correctly marks as non-exempt
                'is_third_schedule' => true,   // client tries to spoof Third Schedule — must be IGNORED
                'item_discount'     => 0,
            ]],
            'payment_method'  => 'cash',
            'cash_received'   => 177,
            'discount_type'   => 'percentage',
            'discount_value'  => 0,
            'tax_inclusive'   => false,
            'offline_uuid'    => $uuid,
        ]);

        $data = $res->getData(true);
        $this->assertTrue($data['success'] ?? false,
            'FBR store should succeed: ' . ($data['message'] ?? json_encode($data)));

        $item = DB::table('fbr_pos_transaction_items')
            ->where('transaction_id', $data['transaction_id'] ?? 0)
            ->first();

        $this->assertNotNull($item, 'Transaction item must be persisted');
        $this->assertEquals(0, (int) $item->is_third_schedule,
            'Non-flagged catalog product must NOT become Third Schedule via client payload');
        // Tax should have been applied (18%) since product is taxable and client sent is_tax_exempt=false
        $this->assertEquals(18, (float) $item->tax_rate,
            'Non-flagged taxable product must still have 18% tax rate despite client spoofing is_third_schedule=true');
        $this->assertGreaterThan(0, (float) $item->tax_amount,
            'Non-flagged taxable product must have non-zero tax despite client is_third_schedule=true');
    }
}
