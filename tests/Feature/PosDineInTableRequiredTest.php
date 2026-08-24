<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantPosController;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * DINE-IN TABLE-REQUIRED INVARIANT — owner voice note, 9 Aug 2026.
 *
 * A live shop's waiter punched a dine-in order WITHOUT selecting a table and
 * the KOT still printed. When the company actually manages tables (tables
 * feature ON), a dine-in punch without a table must be rejected server-side
 * on BOTH punch paths:
 *
 *   1. Waiter tablet   — RestaurantWaiterController::storeOrder
 *   2. Cashier screen  — RestaurantPosController::holdOrder
 *      (explicit Hold/KOT AND the internal billing_flow pass-through)
 *
 * And, critically, the rule must NOT fire where it would break real flows:
 *   - tables feature OFF  → dine-in without table stays possible (no tables
 *     exist to pick — e.g. KOT-only kitchens)
 *   - takeaway / delivery → never need a table
 *   - dine-in WITH table  → punches normally
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controllers invoked directly (same approach as PosWaiterMultiOrderPickerTest
 * / PosDayCloseOpenOrdersWarningTest).
 */
class PosDineInTableRequiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // PosFeatureService caches restaurantAllowed per company id in a
        // STATIC — earlier suites' verdicts leak into this one. Reset it.
        $prop = new \ReflectionProperty(\App\Services\PosFeatureService::class, 'restaurantAllowedCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->text('feature_flags')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('restaurant_floors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('floor_id')->nullable();
            $table->string('table_number');
            $table->integer('seats')->default(4);
            $table->string('status')->default('available');
            $table->timestamp('occupied_since')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number');
            $table->string('token_no')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->default('dine_in');
            $table->string('status')->default('held');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('estimated_cost', 12, 2)->default(0);
            $table->string('kitchen_notes')->nullable();
            $table->boolean('priority')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->string('source')->default('waiter');
            $table->timestamp('kot_sent_at')->nullable();
            $table->integer('kot_print_count')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->text('void_items')->nullable();
            $table->string('hold_uuid', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('item_type')->default('manual');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('special_notes')->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->string('item_discount_type')->nullable();
            $table->decimal('item_discount_value', 12, 2)->default(0);
            $table->decimal('item_discount_amount', 12, 2)->default(0);
            $table->timestamp('kot_printed_at')->nullable();
            $table->integer('kot_batch_no')->nullable();
            $table->timestamps();
        });

        // A completed hold prices its lines and costs its recipes, so the
        // takeaway/delivery park tests below need these two present too.
        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_needed', 12, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('stock_quantity', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    // ── Seed helpers ─────────────────────────────────────────────────────

    /** is_internal_account=true → restaurantAllowed() passes without a plan fixture. */
    private function makeCompany(array $flags): Company
    {
        $company = Company::create([
            'name' => 'Table Guard Co',
            'product_type' => 'pos',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            'feature_flags' => $flags,
        ]);
        app()->instance('currentCompanyId', null); // clear any earlier binding
        app()->bind('currentCompanyId', fn () => $company->id);
        return $company;
    }

    private function makeWaiter(Company $c): User
    {
        $u = User::create([
            'company_id' => $c->id, 'name' => 'Waiter W',
            'pos_role' => 'pos_waiter', 'is_active' => true,
        ]);
        Auth::guard('pos')->setUser($u);
        return $u;
    }

    private function makeTable(Company $c): int
    {
        $floorId = DB::table('restaurant_floors')->insertGetId([
            'company_id' => $c->id, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('restaurant_tables')->insertGetId([
            'company_id' => $c->id, 'floor_id' => $floorId,
            'table_number' => 'T-1', 'seats' => 4, 'status' => 'available',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function waiterPunch(array $payload)
    {
        $request = Request::create('/pos/waiter/orders', 'POST', $payload);
        return app(RestaurantWaiterController::class)->storeOrder($request);
    }

    private function cashierHold(array $payload)
    {
        $request = Request::create('/pos/restaurant/orders/hold', 'POST', $payload);
        return app(RestaurantPosController::class)->holdOrder($request);
    }

    private function items(): array
    {
        return [['name' => 'Bottle', 'quantity' => 1, 'unit_price' => 100]];
    }

    private function holdItems(): array
    {
        return [['item_type' => 'manual', 'item_name' => 'Bottle', 'unit_price' => 100, 'quantity' => 1]];
    }

    /**
     * A REAL product line — what the sale screen actually posts on Hold.
     * The manual fixture above is deliberately not used for the park tests:
     * the screen refuses to hold manual/deal carts, so a manual payload would
     * prove a path no cashier can reach.
     */
    private function productItems(Company $c): array
    {
        $id = DB::table('pos_products')->insertGetId([
            'company_id' => $c->id, 'name' => 'ZFC Sp Pizza L', 'price' => 1750,
            'cost_price' => 0, 'stock_quantity' => 100,
            'is_tax_exempt' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [['item_type' => 'product', 'item_id' => $id, 'quantity' => 1]];
    }

    // ── 1. Waiter punch path ─────────────────────────────────────────────

    public function test_waiter_dine_in_without_table_is_rejected_when_tables_on(): void
    {
        $c = $this->makeCompany(['tables' => true, 'kot' => true]);
        $this->makeWaiter($c);

        $res = $this->waiterPunch(['items' => $this->items(), 'order_type' => 'dine_in']);

        $this->assertSame(422, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(__('pos.dine_in_table_required'), $body['message']);
        $this->assertSame(0, DB::table('restaurant_orders')->count(), 'no order/KOT may be created');
    }

    public function test_waiter_default_type_dine_in_without_table_also_rejected(): void
    {
        // The reported bug: waiter sent NO order_type at all (server defaults
        // to dine_in) and no table — must be blocked the same way.
        $c = $this->makeCompany(['tables' => true, 'kot' => true]);
        $this->makeWaiter($c);

        $res = $this->waiterPunch(['items' => $this->items()]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(0, DB::table('restaurant_orders')->count());
    }

    public function test_waiter_dine_in_with_table_punches_normally(): void
    {
        $c = $this->makeCompany(['tables' => true, 'kot' => true]);
        $this->makeWaiter($c);
        $tableId = $this->makeTable($c);

        $res = $this->waiterPunch(['items' => $this->items(), 'order_type' => 'dine_in', 'table_id' => $tableId]);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('table_id', $tableId)->count());
    }

    public function test_waiter_takeaway_without_table_stays_allowed(): void
    {
        $c = $this->makeCompany(['tables' => true, 'kot' => true]);
        $this->makeWaiter($c);

        $res = $this->waiterPunch(['items' => $this->items(), 'order_type' => 'takeaway']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->whereNull('table_id')->count());
    }

    public function test_waiter_dine_in_without_table_allowed_when_tables_feature_off(): void
    {
        // KOT-only kitchen (no table management): there are no tables to pick,
        // so the punch must stay possible. NOTE: kot depends on kitchen
        // (PosFeatureService::DEPENDENCIES) — both must be ON.
        $c = $this->makeCompany(['tables' => false, 'kot' => true, 'kitchen' => true]);
        $this->makeWaiter($c);

        $res = $this->waiterPunch(['items' => $this->items(), 'order_type' => 'dine_in']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->whereNull('table_id')->count());
    }

    // ── 2. Cashier hold path ─────────────────────────────────────────────

    public function test_hold_dine_in_without_table_rejected_when_tables_on(): void
    {
        $c = $this->makeCompany(['tables' => true, 'kot' => true]);
        $this->makeWaiter($c); // any pos user works for the guard

        $res = $this->cashierHold(['items' => $this->holdItems(), 'order_type' => 'dine_in']);

        $this->assertSame(422, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame(__('pos.dine_in_table_required'), $body['message']);
        $this->assertSame(0, DB::table('restaurant_orders')->count());
    }

    public function test_hold_billing_flow_dine_in_without_table_also_rejected(): void
    {
        // The internal hold-then-pay pass-through must not bypass the invariant.
        $c = $this->makeCompany(['tables' => true, 'kot' => true]);
        $this->makeWaiter($c);

        $res = $this->cashierHold(['items' => $this->holdItems(), 'order_type' => 'dine_in', 'billing_flow' => true]);

        $this->assertSame(422, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame(__('pos.dine_in_table_required'), $body['message']);
    }

    /**
     * Owner (ZFC PIZZA POINT, 25 Aug 2026): parking is no longer a Dine-In-only
     * procedure — a counter taking phone orders must be able to set a half-built
     * Takeaway/Delivery cart aside and answer the next call. What must NOT
     * follow it: the table guard. These types own no table, so the dine-in
     * table requirement has to stay silent for them.
     */
    public function test_hold_takeaway_parks_and_never_hits_the_table_guard(): void
    {
        $c = $this->makeCompany(['tables' => true, 'kot' => true]);
        $this->makeWaiter($c);

        $res = $this->cashierHold(['items' => $this->productItems($c), 'order_type' => 'takeaway']);

        $this->assertSame(200, $res->getStatusCode(), 'takeaway must park: ' . $res->getContent());
        $body = json_decode($res->getContent(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(1, DB::table('restaurant_orders')->count());
        $this->assertSame('takeaway', DB::table('restaurant_orders')->value('order_type'));
        $this->assertNull(DB::table('restaurant_orders')->value('table_id'), 'takeaway parks without a table');
    }

    /** The ZFC case itself: a delivery cart parks while the next call comes in. */
    public function test_hold_delivery_parks_and_never_hits_the_table_guard(): void
    {
        $c = $this->makeCompany(['tables' => true, 'kot' => true, 'kitchen' => true, 'delivery' => true]);
        $this->makeWaiter($c);

        $res = $this->cashierHold(['items' => $this->productItems($c), 'order_type' => 'delivery']);

        $this->assertSame(200, $res->getStatusCode(), 'delivery must park: ' . $res->getContent());
        $body = json_decode($res->getContent(), true);
        $this->assertTrue($body['success']);
        $this->assertSame('delivery', DB::table('restaurant_orders')->value('order_type'));
        $this->assertNull(DB::table('restaurant_orders')->value('table_id'), 'delivery parks without a table');
        $this->assertSame('held', DB::table('restaurant_orders')->value('status'), 'a parked cart is not a sale');
    }
}
