<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantPosController;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RECALL-GHOST CANCELLATION GUARD — Task 508 (locks Task 506's fix).
 *
 * Recall + re-hold supersedes the old held order by setting status='cancelled'
 * WITHOUT cancelled_at/cancelled_by and with its items deleted. Task 506 made
 * two conventions that must never silently regress:
 *
 *   1. RestaurantPosController::holdOrder's supersede write MUST stamp
 *      superseded_at (system supersede, not a human cancel).
 *   2. Every cancelled-rows read goes through RestaurantOrder::genuineCancelled()
 *      which excludes superseded rows AND the legacy-ghost signature
 *      (NULL stamps + zero items).
 *
 * Otherwise recall ghosts reappear on the Cancelled Orders report / dashboard
 * tile → "maine cancel nahi kiya" customer disputes.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly (same as PosDineInTableRequiredTest).
 */
class PosRecallSupersedeGhostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // PosFeatureService caches restaurantAllowed per company id in a STATIC.
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
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
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
            $table->string('source')->default('cashier');
            $table->timestamp('kot_sent_at')->nullable();
            $table->integer('kot_print_count')->nullable();
            // Task 506 columns under test:
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('superseded_at')->nullable();
            // Task 1001: hold_uuid idempotency key — must match live schema.
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

        // Queried by holdOrder (estimated cost + tax rate) — empty is fine.
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_needed', 12, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    // ── Seed helpers ─────────────────────────────────────────────────────

    private function makeCompany(): Company
    {
        $company = Company::create([
            'name' => 'Recall Ghost Co',
            'product_type' => 'pos',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            'feature_flags' => ['tables' => true, 'kot' => true, 'kitchen' => true],
        ]);
        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => $company->id);
        return $company;
    }

    private function makeCashier(Company $c): User
    {
        $u = User::create([
            'company_id' => $c->id, 'name' => 'Cashier C',
            'pos_role' => 'pos_admin', 'is_active' => true,
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

    private function hold(array $payload)
    {
        // Each hold computes a dedup cache key from the cart — flush so a
        // recall re-hold of a similar cart never trips the 429 guard in tests.
        cache()->flush();
        $request = Request::create('/pos/restaurant/orders/hold', 'POST', $payload);
        return app(RestaurantPosController::class)->holdOrder($request);
    }

    private function items(string $name = 'Bottle'): array
    {
        return [['item_type' => 'manual', 'item_name' => $name, 'unit_price' => 100, 'quantity' => 1]];
    }

    // ── 1. Convention: supersede write stamps superseded_at ─────────────

    public function test_recall_rehold_stamps_superseded_at_and_hides_ghost_from_genuine_cancelled(): void
    {
        $c = $this->makeCompany();
        $this->makeCashier($c);
        $tableId = $this->makeTable($c);

        // Hold #1 — original order.
        $res1 = $this->hold(['items' => $this->items('Bottle'), 'order_type' => 'dine_in', 'table_id' => $tableId]);
        $this->assertSame(200, $res1->getStatusCode(), $res1->getContent());
        $oldId = json_decode($res1->getContent(), true)['order']['id'];

        // Hold #2 — recall + re-hold supersedes the original.
        $res2 = $this->hold([
            'items' => $this->items('Bottle Large'),
            'order_type' => 'dine_in',
            'table_id' => $tableId,
            'recalled_order_id' => $oldId,
        ]);
        $this->assertSame(200, $res2->getStatusCode(), $res2->getContent());
        $newId = json_decode($res2->getContent(), true)['order']['id'];
        $this->assertNotSame($oldId, $newId);

        $old = RestaurantOrder::find($oldId);
        $this->assertSame('cancelled', $old->status, 'superseded order stays status=cancelled (blacklist queries leak-safe)');
        $this->assertNotNull($old->superseded_at, 'holdOrder supersede write MUST stamp superseded_at (Task 506)');
        $this->assertNull($old->cancelled_at, 'system supersede is not a human cancel');
        $this->assertNull($old->cancelled_by);
        $this->assertSame(0, DB::table('restaurant_order_items')->where('order_id', $oldId)->count(), 'old items deleted');

        // The ghost must NOT surface as a genuine cancel.
        $this->assertSame(
            0,
            RestaurantOrder::where('company_id', $c->id)->genuineCancelled()->where('id', $oldId)->count(),
            'superseded ghost must be invisible to genuineCancelled()'
        );

        // New order is a live held order.
        $this->assertSame('held', RestaurantOrder::find($newId)->status);
    }

    // ── 2. Convention: genuineCancelled() visibility rules ──────────────

    public function test_genuine_cancels_visible_and_ghost_signatures_hidden(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeCashier($c);

        // (a) Genuine cancel — deleteOrder-style stamps, items KEPT.
        $genuine = RestaurantOrder::create([
            'company_id' => $c->id, 'order_number' => 'ORD-GEN-1',
            'order_type' => 'dine_in', 'status' => 'cancelled',
            'cancelled_at' => now(), 'cancelled_by' => $u->id,
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id' => $genuine->id, 'item_type' => 'manual',
            'item_name' => 'Kept Item', 'quantity' => 1, 'unit_price' => 50,
            'subtotal' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // (b) Legacy ghost — pre-Task-506 supersede: NULL stamps, zero items,
        //     superseded_at also NULL (row written before the column existed).
        RestaurantOrder::create([
            'company_id' => $c->id, 'order_number' => 'ORD-GHOST-1',
            'order_type' => 'dine_in', 'status' => 'cancelled',
        ]);

        // (c) New-style supersede ghost — superseded_at set. Give it an item
        //     stamp-free profile that would otherwise LOOK genuine (items kept)
        //     to prove superseded_at alone excludes it.
        $sup = RestaurantOrder::create([
            'company_id' => $c->id, 'order_number' => 'ORD-SUP-1',
            'order_type' => 'dine_in', 'status' => 'cancelled',
            'superseded_at' => now(), 'cancelled_at' => now(), 'cancelled_by' => $u->id,
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id' => $sup->id, 'item_type' => 'manual',
            'item_name' => 'Superseded Item', 'quantity' => 1, 'unit_price' => 10,
            'subtotal' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // (d) Edge: NULL stamps but items KEPT and no superseded_at — treat as
        //     genuine (only the zero-items signature marks a legacy ghost).
        $stampless = RestaurantOrder::create([
            'company_id' => $c->id, 'order_number' => 'ORD-STAMPLESS-1',
            'order_type' => 'dine_in', 'status' => 'cancelled',
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id' => $stampless->id, 'item_type' => 'manual',
            'item_name' => 'Orphan Item', 'quantity' => 1, 'unit_price' => 20,
            'subtotal' => 20, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // (e) A live held order — never a cancel at all.
        RestaurantOrder::create([
            'company_id' => $c->id, 'order_number' => 'ORD-HELD-1',
            'order_type' => 'dine_in', 'status' => 'held',
        ]);

        $visible = RestaurantOrder::where('company_id', $c->id)
            ->genuineCancelled()->pluck('order_number')->sort()->values()->all();

        $this->assertSame(['ORD-GEN-1', 'ORD-STAMPLESS-1'], $visible);
    }
}
