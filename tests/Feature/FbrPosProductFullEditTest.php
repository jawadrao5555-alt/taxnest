<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR POS full product edit (Task 1276).
 *
 * Locks the edit-form parity + stock-adjustment invariants:
 *   1. A full-field PUT persists EVERY model-backed field (DB read — Eloquent
 *      silently drops non-fillable writes), including schedule_type.
 *   2. Third Schedule without a positive MRP is rejected on EDIT and the
 *      product row stays untouched.
 *   3. Third Schedule without MRP is rejected on single CREATE (no row), and
 *      accepted with MRP.
 *   4. Multi-row create with the shared Third Schedule checkbox requires a
 *      per-row MRP.
 *   5. Edit-form "add stock" books a purchase movement and moves the quantity.
 *   6. Edit-form "correct quantity" books ONLY the delta as an adjustment.
 *   7. A forged/cross-company supplier id fails the request BEFORE any write —
 *      product metadata must NOT change (review fix: supplier resolved first,
 *      whole edit in one transaction).
 *   8. A non-admin (cashier) is blocked from the edit endpoints.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosProductFullEditTest.php
 */
class FbrPosProductFullEditTest extends TestCase
{
    protected int $companyId;
    protected \App\Models\User $admin;
    protected int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->boolean('fbr_reporting_enabled')->default(false);
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
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('hs_code')->nullable();
            $table->string('pct_code')->nullable();
            $table->string('sro_reference')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('schedule_type')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('default_price', 12, 2)->nullable();
            $table->decimal('mrp', 12, 2)->nullable();
            $table->decimal('default_tax_rate', 8, 2)->default(0);
            $table->string('tax_type')->default('taxable');
            $table->boolean('is_third_schedule')->default(false);
            $table->boolean('is_price_editable')->default(true);
            $table->boolean('show_on_sale')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('min_stock_level', 12, 4)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('type');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->decimal('balance_after', 12, 4)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->integer('max_products')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(false);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->timestamps();
        });

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Full Edit Shop',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'company_status' => 'active',
            'fbr_pos_enabled' => true,
            'fbr_reporting_enabled' => false,
            'inventory_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $this->companyId,
            'active' => true,
            'override_type' => 'lifetime',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Shop Admin',
            'email' => 'admin@fulledit.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $this->companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = \App\Models\User::find($adminId);

        $this->productId = (int) DB::table('products')->insertGetId([
            'company_id' => $this->companyId,
            'name' => 'Lux Soap',
            'default_price' => 120,
            'tax_type' => 'exempt',
            'default_tax_rate' => 0,
            'is_third_schedule' => false,
            'is_price_editable' => true,
            'show_on_sale' => true,
            'is_active' => true,
            'uom' => 'PCS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        \App\Services\PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    /** Baseline valid PUT payload — tests override just what they probe. */
    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lux Soap 90g',
            'default_price' => 150,
            'is_price_editable' => 1,
            'hs_code' => '3401.1100',
            'uom' => 'PCS',
            'barcode' => '8964000112233',
            'sku' => 'LUX-90',
            'tax_type' => 'exempt',
            'default_tax_rate' => 0,
            'is_third_schedule' => 1,
            'mrp' => 165.50,
            'pct_code' => '3401.1100',
            'sro_reference' => 'SRO 1125(I)/2011',
            'serial_number' => '12',
            'schedule_type' => '3rd_schedule',
            'show_on_sale' => 0,
            'is_active' => 1,
            'min_stock_level' => 6,
            'stock_action' => 'none',
        ], $overrides);
    }

    private function putProduct(array $payload)
    {
        return $this->actingAs($this->admin, 'fbrpos')
            ->put('/fbr-pos/products/' . $this->productId, $payload);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** 1. Full-field PUT persists every model-backed field (verified in DB). */
    public function test_full_field_edit_persists_everything(): void
    {
        $res = $this->putProduct($this->fullPayload());
        $res->assertRedirect(route('fbrpos.products'));

        $row = DB::table('products')->find($this->productId);
        $this->assertSame('Lux Soap 90g', $row->name);
        $this->assertSame(150.0, (float) $row->default_price);
        $this->assertSame('3401.1100', $row->hs_code);
        $this->assertSame('PCS', $row->uom);
        $this->assertSame('8964000112233', $row->barcode);
        $this->assertSame('LUX-90', $row->sku);
        $this->assertSame('exempt', $row->tax_type);
        $this->assertSame(0.0, (float) $row->default_tax_rate);
        $this->assertSame(1, (int) $row->is_third_schedule);
        $this->assertSame(165.5, (float) $row->mrp);
        $this->assertSame('3401.1100', $row->pct_code);
        $this->assertSame('SRO 1125(I)/2011', $row->sro_reference);
        $this->assertSame('12', $row->serial_number);
        $this->assertSame('3rd_schedule', $row->schedule_type);
        $this->assertSame(0, (int) $row->show_on_sale);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertSame(1, (int) $row->is_price_editable);

        // min_stock_level lands on the stock row.
        $stock = DB::table('inventory_stocks')
            ->where('product_id', $this->productId)->whereNull('branch_id')->first();
        $this->assertNotNull($stock);
        $this->assertSame(6.0, (float) $stock->min_stock_level);
    }

    /** 2. Third Schedule without MRP → rejected on edit, row untouched. */
    public function test_edit_third_schedule_requires_mrp(): void
    {
        $res = $this->putProduct($this->fullPayload(['mrp' => '']));
        $res->assertSessionHasErrors('mrp');

        $row = DB::table('products')->find($this->productId);
        $this->assertSame('Lux Soap', $row->name, 'Nothing may persist when MRP validation fails.');
        $this->assertSame(0, (int) $row->is_third_schedule);
    }

    /** 3. Single create: Third Schedule without MRP rejected; with MRP saved. */
    public function test_create_third_schedule_requires_mrp(): void
    {
        $bad = $this->actingAs($this->admin, 'fbrpos')->post('/fbr-pos/products', [
            'name' => 'Pepsi 1.5L',
            'default_price' => 220,
            'tax_type' => 'exempt',
            'is_third_schedule' => 1,
            'entry_mode' => 'single',
            'uom' => 'U',
        ]);
        $bad->assertSessionHasErrors('mrp');
        $this->assertNull(DB::table('products')->where('name', 'Pepsi 1.5L')->first());

        $good = $this->actingAs($this->admin, 'fbrpos')->post('/fbr-pos/products', [
            'name' => 'Pepsi 1.5L',
            'default_price' => 220,
            'tax_type' => 'exempt',
            'is_third_schedule' => 1,
            'mrp' => 230,
            'entry_mode' => 'single',
            'uom' => 'U',
        ]);
        $good->assertSessionHasNoErrors();
        $row = DB::table('products')->where('name', 'Pepsi 1.5L')->first();
        $this->assertNotNull($row);
        $this->assertSame(230.0, (float) $row->mrp);
        $this->assertSame(1, (int) $row->is_third_schedule);
    }

    /** 4. Multi create: shared Third Schedule checkbox demands per-row MRP. */
    public function test_multi_create_third_schedule_requires_per_row_mrp(): void
    {
        $bad = $this->actingAs($this->admin, 'fbrpos')->post('/fbr-pos/products', [
            'entry_mode' => 'multi',
            'tax_type' => 'exempt',
            'is_third_schedule' => 1,
            'rows' => [
                ['name' => 'Coke 1L', 'default_price' => 180, 'mrp' => 190],
                ['name' => 'Sprite 1L', 'default_price' => 180], // ← missing MRP
            ],
        ]);
        $bad->assertSessionHasErrors('rows.1.mrp');
        $this->assertNull(DB::table('products')->where('name', 'Coke 1L')->first(),
            'No row may be created when any row fails validation.');

        $good = $this->actingAs($this->admin, 'fbrpos')->post('/fbr-pos/products', [
            'entry_mode' => 'multi',
            'tax_type' => 'exempt',
            'is_third_schedule' => 1,
            'rows' => [
                ['name' => 'Coke 1L', 'default_price' => 180, 'mrp' => 190],
                ['name' => 'Sprite 1L', 'default_price' => 180, 'mrp' => 190],
            ],
        ]);
        $good->assertSessionHasNoErrors();
        $coke = DB::table('products')->where('name', 'Coke 1L')->first();
        $this->assertNotNull($coke);
        $this->assertSame(190.0, (float) $coke->mrp);
        $this->assertSame(1, (int) $coke->is_third_schedule);
    }

    /** 5. Add stock (no supplier) → purchase movement + quantity moves. */
    public function test_edit_add_stock_books_purchase_movement(): void
    {
        $res = $this->putProduct($this->fullPayload([
            'stock_action' => 'add',
            'add_qty' => 12,
            'add_unit_cost' => 95,
        ]));
        $res->assertSessionHasNoErrors();

        $stock = DB::table('inventory_stocks')
            ->where('product_id', $this->productId)->whereNull('branch_id')->first();
        $this->assertSame(12.0, (float) $stock->quantity);

        $move = DB::table('inventory_movements')
            ->where('product_id', $this->productId)->orderByDesc('id')->first();
        $this->assertSame('purchase', $move->type);
        $this->assertSame(12.0, (float) $move->quantity);
        $this->assertSame(95.0, (float) $move->unit_price);
    }

    /** 6. Correct quantity → ONLY the delta is booked as an adjustment. */
    public function test_edit_correct_quantity_books_delta_adjustment(): void
    {
        // Seed stock at 10 through the add path first.
        $this->putProduct($this->fullPayload(['stock_action' => 'add', 'add_qty' => 10]))
            ->assertSessionHasNoErrors();

        $res = $this->putProduct($this->fullPayload([
            'stock_action' => 'correct',
            'new_qty' => 7,
            'qty_reason' => 'ginti ka farq',
        ]));
        $res->assertSessionHasNoErrors();

        $stock = DB::table('inventory_stocks')
            ->where('product_id', $this->productId)->whereNull('branch_id')->first();
        $this->assertSame(7.0, (float) $stock->quantity);

        $move = DB::table('inventory_movements')
            ->where('product_id', $this->productId)->orderByDesc('id')->first();
        $this->assertSame('adjustment_out', $move->type);
        $this->assertSame(3.0, (float) $move->quantity, 'Only the delta is booked, never the raw total.');
        $this->assertStringContainsString('ginti ka farq', (string) $move->notes);
    }

    /** 7. Forged supplier id fails BEFORE any write — metadata untouched. */
    public function test_invalid_supplier_blocks_whole_edit(): void
    {
        // Supplier of ANOTHER company — must never be usable here.
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'name' => 'Other Shop', 'product_type' => 'fbrpos',
            'status' => 'active', 'company_status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignSupplier = (int) DB::table('suppliers')->insertGetId([
            'company_id' => $otherCompany, 'name' => 'Foreign Supplier',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->putProduct($this->fullPayload([
            'stock_action' => 'add',
            'add_qty' => 5,
            'supplier_id' => $foreignSupplier,
        ]));
        $res->assertSessionHasErrors('supplier_id');

        $row = DB::table('products')->find($this->productId);
        $this->assertSame('Lux Soap', $row->name,
            'Product metadata must NOT change when the supplier id is invalid.');
        $this->assertSame(0, (int) DB::table('inventory_movements')
            ->where('product_id', $this->productId)->count());
    }

    /** 7b. schedule_type '3rd_schedule' demands the fiscal Third Schedule flag. */
    public function test_schedule_type_third_requires_fiscal_flag(): void
    {
        $res = $this->putProduct($this->fullPayload([
            'is_third_schedule' => 0,       // checkbox OFF …
            'schedule_type' => '3rd_schedule', // … but MRP-based reporting type ON
        ]));
        $res->assertSessionHasErrors('schedule_type');

        $row = DB::table('products')->find($this->productId);
        $this->assertSame('Lux Soap', $row->name, 'Nothing may persist on the coupling violation.');
        $this->assertNull($row->schedule_type);
    }

    /** 7c. Bulk third_on skips products without a positive MRP. */
    public function test_bulk_third_on_skips_products_without_mrp(): void
    {
        $withMrp = (int) DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Shampoo 200ml',
            'default_price' => 450, 'mrp' => 480, 'tax_type' => 'taxable',
            'default_tax_rate' => 18, 'uom' => 'U',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // $this->productId has NO MRP → must be skipped.

        $res = $this->actingAs($this->admin, 'fbrpos')->post('/fbr-pos/products/bulk', [
            'action' => 'third_on',
            'ids' => [$withMrp, $this->productId],
        ]);
        $res->assertSessionHas('success');

        $flagged = DB::table('products')->find($withMrp);
        $this->assertSame(1, (int) $flagged->is_third_schedule);
        $this->assertSame('exempt', $flagged->tax_type);
        $this->assertSame(0.0, (float) $flagged->default_tax_rate);

        $skipped = DB::table('products')->find($this->productId);
        $this->assertSame(0, (int) $skipped->is_third_schedule, 'No-MRP product must be skipped by bulk third_on.');
        $this->assertSame('exempt', $skipped->tax_type, 'Skipped product keeps its original tax fields.');

        // All-skipped selection → error flash, nothing flipped.
        $res2 = $this->actingAs($this->admin, 'fbrpos')->post('/fbr-pos/products/bulk', [
            'action' => 'third_on',
            'ids' => [$this->productId],
        ]);
        $res2->assertSessionHas('error');
        $this->assertSame(0, (int) DB::table('products')->find($this->productId)->is_third_schedule);
    }

    /** 8. A cashier (non-admin) is blocked from the edit endpoints. */
    public function test_cashier_cannot_edit_products(): void
    {
        $cashierId = DB::table('users')->insertGetId([
            'name' => 'Cashier', 'email' => 'cashier@fulledit.test',
            'password' => bcrypt('Secret@12345'), 'company_id' => $this->companyId,
            'role' => 'cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cashier = \App\Models\User::find($cashierId);

        $this->actingAs($cashier, 'fbrpos')
            ->get('/fbr-pos/products/' . $this->productId . '/edit')->assertForbidden();
        $this->actingAs($cashier, 'fbrpos')
            ->put('/fbr-pos/products/' . $this->productId, $this->fullPayload())->assertForbidden();

        $this->assertSame('Lux Soap', DB::table('products')->find($this->productId)->name);
    }
}
