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
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('kitchen_notes')->nullable();
            $table->boolean('priority')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('assigned_cashier_id')->nullable();
            $table->string('source')->default('waiter');
            $table->timestamp('kot_sent_at')->nullable();
            $table->integer('kot_print_count')->nullable();
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
            $table->timestamp('kot_printed_at')->nullable();
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

    public function test_hold_takeaway_still_hits_type_flow_gate_not_table_guard(): void
    {
        // Explicit hold of takeaway is rejected by the PRE-EXISTING flow gate
        // (dine-in-only hold) — the new table guard must not change that message.
        $c = $this->makeCompany(['tables' => true, 'kot' => true]);
        $this->makeWaiter($c);

        $res = $this->cashierHold(['items' => $this->holdItems(), 'order_type' => 'takeaway']);

        $this->assertSame(422, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertNotSame(__('pos.dine_in_table_required'), $body['message']);
        $this->assertStringContainsString('Dine-In', $body['message']);
    }
}
