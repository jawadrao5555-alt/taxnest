<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR New Product page — tax-type default vs save-and-continue sticky (Task 1269).
 *
 * Locks the coexistence of two behaviors:
 *   - Task 1262: a FRESH visit to the New Product page pre-selects the
 *     Exempt tax card (taxType boots as 'exempt').
 *   - Task 1261: "Save & Next" (save_action=stay) redirects back to the form
 *     with a ONE-REQUEST session flash (fbr_product_sticky) carrying the last
 *     product's tax type forward for rapid consecutive entry.
 *
 * Scenarios:
 *   1. Fresh GET /fbr-pos/products/create → Exempt selected.
 *   2. POST a Taxable product with save_action=stay → redirect to create;
 *      the redirected page shows Taxable (sticky flash) and the product row
 *      is stored as taxable/18.
 *   3. The NEXT navigation to the page (flash consumed) → Exempt again.
 *   4. Sticky also carries a custom rate forward, and expires the same way.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosProductTaxTypeStickyTest.php
 */
class FbrPosProductTaxTypeStickyTest extends TestCase
{
    protected int $companyId;
    protected \App\Models\User $admin;

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
            $table->string('uom')->nullable();
            $table->decimal('default_price', 12, 2)->nullable();
            $table->decimal('mrp', 12, 2)->nullable(); // Task 1276: storeProduct persists MRP
            $table->string('schedule_type')->nullable();
            $table->decimal('default_tax_rate', 8, 2)->default(0);
            $table->string('tax_type')->default('taxable');
            $table->boolean('is_third_schedule')->default(false);
            $table->boolean('is_price_editable')->default(true);
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

        // Suppliers (Task 1261 supplier block; queried when inventory allowed)
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Inventory tables (applyProductStockFields paths; unused here but safe)
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

        // Plan gate tables — plan.limit:products middleware + layout components.
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->integer('max_products')->nullable(); // NULL = unlimited
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
            'name' => 'Sticky Tax Shop',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'company_status' => 'active',
            'fbr_pos_enabled' => true,
            'fbr_reporting_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Lifetime override: unlimited product cap (like live QA companies).
        DB::table('subscriptions')->insert([
            'company_id' => $this->companyId,
            'active' => true,
            'override_type' => 'lifetime',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Shop Admin',
            'email' => 'admin@stickytax.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $this->companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = \App\Models\User::find($adminId);
    }

    protected function tearDown(): void
    {
        \App\Services\PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    /** The Alpine boot literal rendered into the form: taxType: 'xxx'. */
    private function assertBootTaxType(string $expected, $response): void
    {
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(
            "taxType: '{$expected}'",
            $html,
            "New Product form should boot with taxType '{$expected}'."
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** 1. Fresh visit (no flash, no old input) → Exempt pre-selected. */
    public function test_fresh_new_product_page_defaults_to_exempt(): void
    {
        $res = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/products/create');
        $this->assertBootTaxType('exempt', $res);
    }

    /** 2+3. Save & Next a Taxable product → sticky Taxable once, then Exempt. */
    public function test_save_and_next_keeps_taxable_for_one_request_then_reverts_to_exempt(): void
    {
        $post = $this->actingAs($this->admin, 'fbrpos')->post('/fbr-pos/products', [
            'name' => 'Sugar 1kg',
            'default_price' => 190,
            'tax_type' => 'taxable',
            'save_action' => 'stay',
            'entry_mode' => 'single',
            'uom' => 'U',
            'is_price_editable' => 1,
        ]);
        $post->assertRedirect(route('fbrpos.products.create'));
        $post->assertSessionHas('fbr_product_sticky', function ($sticky) {
            return ($sticky['tax_type'] ?? null) === 'taxable';
        });

        // Product persisted with the submitted tax type (DB read — Eloquent
        // silently drops non-fillable writes).
        $row = DB::table('products')->where('name', 'Sugar 1kg')->first();
        $this->assertNotNull($row);
        $this->assertSame('taxable', $row->tax_type);
        $this->assertSame(18.0, (float) $row->default_tax_rate);

        // The redirect target request sees the sticky flash → Taxable.
        $again = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/products/create');
        $this->assertBootTaxType('taxable', $again);

        // Flash consumed: the NEXT navigation is a fresh page → Exempt.
        $fresh = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/products/create');
        $this->assertBootTaxType('exempt', $fresh);
    }

    /** 4. Sticky also carries the custom tax mode + rate, and expires too. */
    public function test_save_and_next_carries_custom_rate_then_expires(): void
    {
        $post = $this->actingAs($this->admin, 'fbrpos')->post('/fbr-pos/products', [
            'name' => 'Zarai Spray',
            'default_price' => 500,
            'tax_type' => 'custom',
            'default_tax_rate' => 5,
            'save_action' => 'stay',
            'entry_mode' => 'single',
            'uom' => 'U',
        ]);
        $post->assertRedirect(route('fbrpos.products.create'));

        $sticky = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/products/create');
        $this->assertBootTaxType('custom', $sticky);
        $this->assertMatchesRegularExpression('/taxRate:\s*[\'"]?5(?:\.0+)?[\'"]?/', $sticky->getContent());

        $fresh = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/products/create');
        $this->assertBootTaxType('exempt', $fresh);
    }
}
