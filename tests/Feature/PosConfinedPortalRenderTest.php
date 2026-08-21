<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every role-only POS portal must actually RENDER for the one role locked to it
 * (Task 1339 follow-up).
 *
 * The archive_viewer account signed in and was redirected correctly, but its
 * ONLY page 500'd (archived_at reached ->format() as a raw string) — so the
 * account was silently locked out of the whole product and NOTHING caught it.
 * The existing role tests (PosTutorialsRoleAccessTest) only check the PosAuth
 * redirect/confinement DECISION, never that the destination page renders.
 *
 * Each confined POS role is locked to a single home screen:
 *   archive_viewer → /pos/archive        local_viewer → /pos/local-bills
 *   pos_kitchen    → /pos/restaurant/kds  pos_rider    → /pos/rider
 *   pos_delivery   → /pos/deliveries      pos_waiter   → /pos/waiter
 *
 * This suite signs in as each role and GETs its home, asserting a 200 render
 * (not just the redirect target). The fixture seeds one realistic row per
 * portal (archived bill, local bill, open kitchen order, rider delivery, …) so
 * an empty-state render cannot hide a broken cell — the test fails loudly the
 * moment any portal starts throwing the way the archive list did.
 *
 * Pattern: sqlite :memory: + minimal Schema::create + actingAs($user, 'pos')
 * + real view render (mirrors PosArchivePortalRenderTest / PosKotCenterNoticeTest;
 * the latter already drives a full <x-pos-layout> page over HTTP).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/PosConfinedPortalRenderTest.php --testdox
 */
class PosConfinedPortalRenderTest extends TestCase
{
    /** Raw DB string exactly as MySQL/sqlite hand a timestamp column back. */
    private const TS = '2026-08-14 21:45:30';

    private Company $company;
    private int $riderRecordId;

    protected function setUp(): void
    {
        parent::setUp();

        User::flushScopeColumnCache();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        $this->seedShop();
    }

    // ── archive_viewer → /pos/archive ────────────────────────────────────────

    public function test_archive_viewer_home_renders(): void
    {
        $res = $this->actingAs($this->makeUser('archive_viewer'), 'pos')->get('/pos/archive');

        $res->assertOk();
        $res->assertSee('ARCH-0001'); // seeded archived bill's number must print
    }

    // ── local_viewer → /pos/local-bills ──────────────────────────────────────

    public function test_local_viewer_home_renders(): void
    {
        $res = $this->actingAs($this->makeUser('local_viewer'), 'pos')->get('/pos/local-bills');

        $res->assertOk();
        $res->assertSee('LOCAL-0001'); // seeded local bill's number must print
    }

    // ── pos_kitchen → /pos/restaurant/kds ────────────────────────────────────

    public function test_kitchen_home_renders(): void
    {
        $res = $this->actingAs($this->makeUser('pos_kitchen'), 'pos')->get('/pos/restaurant/kds');

        $res->assertOk();
        $res->assertSee('Chicken Karahi'); // seeded held order's item must print
    }

    // ── pos_rider → /pos/rider ────────────────────────────────────────────────

    public function test_rider_home_renders(): void
    {
        $res = $this->actingAs($this->riderUser, 'pos')->get('/pos/rider');

        $res->assertOk();
        $res->assertSee('RIDE-0001'); // seeded delivery bill's number must print
    }

    // ── pos_delivery → /pos/deliveries ───────────────────────────────────────

    public function test_delivery_manager_home_renders(): void
    {
        $res = $this->actingAs($this->makeUser('pos_delivery'), 'pos')->get('/pos/deliveries');

        $res->assertOk();
    }

    // ── pos_waiter → /pos/waiter ──────────────────────────────────────────────

    public function test_waiter_home_renders(): void
    {
        $res = $this->actingAs($this->makeUser('pos_waiter'), 'pos')->get('/pos/waiter');

        $res->assertOk();
        $res->assertSee('Chicken Karahi'); // seeded product must reach the tablet grid
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private User $riderUser;

    private function makeUser(string $posRole): User
    {
        return User::create([
            'name'       => 'U-' . $posRole,
            'email'      => $posRole . '@confined.test',
            'password'   => bcrypt('secret'),
            'company_id' => $this->company->id,
            'role'       => 'pos_user',
            'pos_role'   => $posRole,
            'is_active'  => true,
        ]);
    }

    private function seedShop(): void
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'product_type' => 'pos',
            'is_trial' => false, 'invoice_limit' => -1,
            'restaurant_enabled' => true, 'riders_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'name' => 'Confined Portals Shop', 'product_type' => 'pos',
            'status' => 'active', 'company_status' => 'active',
            'is_internal_account' => false,
            'pra_reporting_enabled' => false,
            'inventory_enabled' => false,
            'pos_setup_completed' => true,
            // Kitchen + delivery features ON so KDS/waiter/deliveries are live.
            'feature_flags' => ['kot' => true, 'kitchen' => true, 'tables' => true, 'delivery' => true],
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $this->company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cashier = User::create([
            'name' => 'Cashier', 'email' => 'cashier@confined.test',
            'password' => bcrypt('secret'), 'company_id' => $this->company->id,
            'role' => 'pos_user', 'pos_role' => 'pos_cashier', 'is_active' => true,
        ]);

        // The rider LOGIN account + its linked PosRider record (portal joins the two).
        $this->riderUser = $this->makeUser('pos_rider');
        $this->riderRecordId = (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $this->company->id, 'name' => 'Asgar',
            'user_id' => $this->riderUser->id, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 1. Archived bill (archive portal). Written with a plain-string
        //    archived_at, exactly like a bill washed at day-close.
        DB::table('pos_transactions')->insert([
            'company_id' => $this->company->id, 'invoice_number' => 'ARCH-0001',
            'invoice_mode' => 'pra', 'customer_name' => 'Walk-in', 'customer_phone' => '03001234567',
            'subtotal' => 500, 'total_amount' => 500, 'payment_method' => 'cash',
            'status' => 'completed', 'pra_status' => 'local', 'created_by' => $cashier->id,
            'business_date' => '2026-08-14', 'is_archived' => true,
            'archived_at' => self::TS, 'archived_by_report_id' => null,
            'created_at' => self::TS, 'updated_at' => self::TS,
        ]);

        // 2. Local (non-PRA) bill (local-bills portal).
        DB::table('pos_transactions')->insert([
            'company_id' => $this->company->id, 'invoice_number' => 'LOCAL-0001',
            'invoice_mode' => 'local', 'customer_name' => 'Walk-in',
            'subtotal' => 300, 'total_amount' => 300, 'payment_method' => 'cash',
            'status' => 'completed', 'created_by' => $cashier->id,
            'business_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 3. Rider delivery bill (rider portal + deliveries board). Today's,
        //    assigned to our rider, cash-on-delivery so the khata banner adds up.
        DB::table('pos_transactions')->insert([
            'company_id' => $this->company->id, 'invoice_number' => 'RIDE-0001',
            'invoice_mode' => 'pra', 'customer_name' => 'Bilal', 'customer_phone' => '03007654321',
            'delivery_address' => 'St 5, Model Town', 'order_type' => 'delivery',
            'subtotal' => 800, 'total_amount' => 800, 'payment_method' => 'cash',
            'status' => 'completed', 'pra_status' => 'completed', 'created_by' => $cashier->id,
            'rider_id' => $this->riderRecordId, 'delivery_status' => 'assigned',
            'rider_assigned_at' => now(), 'business_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 4. Product (waiter tablet grid + KOT item name).
        $productId = (int) DB::table('pos_products')->insertGetId([
            'company_id' => $this->company->id, 'name' => 'Chicken Karahi',
            'price' => 1200, 'category' => 'Main', 'is_active' => true, 'show_on_sale' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 5. Dine-in table + open (held) kitchen order with one item (KDS board).
        $tableId = (int) DB::table('restaurant_tables')->insertGetId([
            'company_id' => $this->company->id, 'table_number' => 'T1', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = (int) DB::table('restaurant_orders')->insertGetId([
            'company_id' => $this->company->id, 'table_id' => $tableId,
            'order_number' => 'K-0001', 'status' => 'held', 'kitchen_status' => 'new',
            'created_by' => $cashier->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'item_name' => 'Chicken Karahi',
            'quantity' => 1, 'unit_price' => 1200, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A day-close report so the archive/local cashier+report filters have a row.
        DB::table('pos_day_close_reports')->insert([
            'company_id' => $this->company->id, 'report_number' => 'Z-0001',
            'report_date' => '2026-08-14', 'created_at' => self::TS, 'updated_at' => self::TS,
        ]);

        app()->instance('currentCompanyId', $this->company->id);
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->text('feature_flags')->nullable();
            $t->string('default_language')->nullable();
            $t->boolean('pos_setup_completed')->default(false);
            $t->boolean('pos_tax_inclusive')->default(false);
            $t->string('pos_product_search_mode')->nullable();
            $t->boolean('pos_kds_auto_print')->default(false);
            $t->boolean('pos_waiter_cancel_enabled')->default(false);
            $t->boolean('pos_waiter_takeaway_enabled')->default(true);
            $t->text('pos_printer_settings')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->string('pos_billing_scope', 10)->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('default_branch_id')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('restaurant_enabled')->default(false);
            $t->boolean('riders_enabled')->default(true);
            $t->integer('invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->decimal('price', 12, 2)->default(0);
            $t->string('category')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('show_on_sale')->default(true);
            $t->boolean('is_tax_exempt')->default(false);
            $t->boolean('is_third_schedule')->default(false);
            $t->string('barcode')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->string('order_type')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->string('status');
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('business_date')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('archived_by_report_id')->nullable();
            $t->unsignedBigInteger('rider_id')->nullable();
            $t->string('delivery_status')->nullable();
            $t->unsignedBigInteger('rider_settlement_id')->nullable();
            $t->decimal('rider_partial_paid', 12, 2)->nullable();
            $t->timestamp('rider_assigned_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->boolean('tax_inclusive')->default(false);
            $t->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_name')->nullable();
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_rider_settlements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('rider_id');
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('report_number')->nullable();
            $t->date('report_date')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('payment_method');
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('pos_tax_rules')->insert([
            ['payment_method' => 'cash', 'tax_rate' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('table_number');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('table_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('order_number')->nullable();
            $t->string('status')->default('held');
            $t->string('kitchen_status')->nullable();
            $t->string('kitchen_notes')->nullable();
            $t->boolean('priority')->default(false);
            $t->timestamp('kitchen_cleared_at')->nullable();
            $t->timestamp('kot_sent_at')->nullable();
            $t->text('void_items')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('item_name')->nullable();
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->string('special_notes')->nullable();
            $t->timestamp('kot_printed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_user_item_prefs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('product_id');
            $t->boolean('visible')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_stations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->text('categories')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('pos_user_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->timestamp('login_at')->nullable();
            $t->timestamp('logout_at')->nullable();
            $t->timestamp('last_activity_at')->nullable();
            $t->timestamps();
        });
    }
}
