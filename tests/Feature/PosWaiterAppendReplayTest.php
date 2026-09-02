<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\RestaurantWaiterController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waiter "Add Items" replay guard.
 *
 * WHY: the waiter screen aborts a request that outlives its 12s timeout, so on a
 * bad line the FIRST append can commit server-side while the tablet believes it
 * failed. Before this guard, the waiter's retry inserted the same lines a second
 * time and the kitchen printed another delta ticket for food already on the pan —
 * the bill grew too. One uuid per append ATTEMPT, resent by every retry of it,
 * makes the second arrival a no-op.
 *
 * The uuid is per ATTEMPT, not per item: one attempt legitimately writes several
 * rows that share it, which is why the column is indexed and not unique.
 */
class PosWaiterAppendReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // PosFeatureService caches restaurantAllowed per company id in a STATIC —
        // earlier suites' verdicts leak into this one. Reset it.
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
            $table->boolean('pos_waiter_takeaway_enabled')->nullable();
            $table->string('order_match_style')->nullable();
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
            $table->string('append_uuid', 64)->nullable();
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

    private function makeCompany(): Company
    {
        $company = Company::create([
            'name' => 'Replay Co',
            'product_type' => 'pos',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            'feature_flags' => ['tables' => false, 'kot' => true, 'kitchen' => true],
        ]);
        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => $company->id);

        return $company;
    }

    private function makeWaiter(Company $c): User
    {
        $u = User::create([
            'company_id' => $c->id, 'name' => 'Waiter',
            'pos_role' => 'waiter', 'is_active' => true,
        ]);
        Auth::guard('pos')->setUser($u);

        return $u;
    }

    private function makeHeldOrder(Company $c, User $u): int
    {
        return (int) DB::table('restaurant_orders')->insertGetId([
            'company_id' => $c->id,
            'order_number' => 'W-1',
            'order_type' => 'dine_in',
            'status' => 'held',
            'subtotal' => 100,
            'total_amount' => 100,
            'created_by' => $u->id,
            'source' => 'waiter',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int,array<string,mixed>>|null $items */
    private function append(int $orderId, ?string $uuid, ?array $items = null)
    {
        $payload = ['items' => $items ?? [['name' => 'Extra Roti', 'quantity' => 2, 'unit_price' => 30]]];
        if ($uuid !== null) {
            $payload['append_uuid'] = $uuid;
        }
        $request = Request::create('/pos/waiter/orders/' . $orderId . '/items', 'POST', $payload);

        return app(RestaurantWaiterController::class)->appendItems($request, $orderId);
    }

    // ── Tests ────────────────────────────────────────────────────────────

    public function test_same_append_uuid_twice_adds_the_items_only_once(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeWaiter($c);
        $id = $this->makeHeldOrder($c, $u);

        $first = $this->append($id, 'attempt-A');
        $this->assertSame(200, $first->getStatusCode());
        $this->assertTrue($first->getData(true)['success']);

        // The tablet never saw the first response and retried the SAME attempt.
        $second = $this->append($id, 'attempt-A');
        $this->assertSame(200, $second->getStatusCode(), 'a replay must not look like a failure to the waiter');
        $body = $second->getData(true);
        $this->assertTrue($body['success']);
        $this->assertTrue($body['replayed'] ?? false, 'the replay must be reported as such');

        $this->assertSame(
            1,
            DB::table('restaurant_order_items')->where('order_id', $id)->count(),
            'the retry must not insert the line a second time'
        );
    }

    public function test_replay_does_not_inflate_the_order_total(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeWaiter($c);
        $id = $this->makeHeldOrder($c, $u);

        $this->append($id, 'attempt-B');
        $afterFirst = (float) DB::table('restaurant_orders')->where('id', $id)->value('subtotal');

        $this->append($id, 'attempt-B');
        $afterReplay = (float) DB::table('restaurant_orders')->where('id', $id)->value('subtotal');

        $this->assertSame(160.0, $afterFirst, '100 held + 2 × 30 added');
        $this->assertSame($afterFirst, $afterReplay, 'a replay must never charge the customer twice');
    }

    public function test_a_genuinely_new_attempt_still_adds_items(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeWaiter($c);
        $id = $this->makeHeldOrder($c, $u);

        $this->append($id, 'attempt-C');
        // Waiter really does want more food on the same order — new attempt, new uuid.
        $this->append($id, 'attempt-D', [['name' => 'Coke', 'quantity' => 1, 'unit_price' => 80]]);

        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $id)->count());
        $this->assertSame(240.0, (float) DB::table('restaurant_orders')->where('id', $id)->value('subtotal'));
    }

    public function test_one_attempt_writing_several_lines_is_stored_and_deduped_as_one(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeWaiter($c);
        $id = $this->makeHeldOrder($c, $u);

        $lines = [
            ['name' => 'Karahi', 'quantity' => 1, 'unit_price' => 900],
            ['name' => 'Naan', 'quantity' => 4, 'unit_price' => 25],
        ];
        $this->append($id, 'attempt-E', $lines);
        $this->append($id, 'attempt-E', $lines);

        $this->assertSame(2, DB::table('restaurant_order_items')->where('order_id', $id)->count(),
            'both lines of ONE attempt stay, and the replay adds nothing');
        $this->assertSame(2, DB::table('restaurant_order_items')->where('append_uuid', 'attempt-E')->count(),
            'lines of the same attempt legitimately share the uuid — it must not be unique');
    }

    public function test_append_without_a_uuid_still_works_for_older_tablets(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeWaiter($c);
        $id = $this->makeHeldOrder($c, $u);

        // An old cached waiter screen sends no uuid. It must keep working (it just
        // does not get replay protection), never 422.
        $res = $this->append($id, null);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_order_items')->where('order_id', $id)->count());
        $this->assertNull(DB::table('restaurant_order_items')->where('order_id', $id)->value('append_uuid'));
    }

    public function test_uuid_from_one_order_does_not_block_another_order(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeWaiter($c);
        $a = $this->makeHeldOrder($c, $u);
        $b = $this->makeHeldOrder($c, $u);

        $this->append($a, 'shared-uuid');
        $this->append($b, 'shared-uuid');

        $this->assertSame(1, DB::table('restaurant_order_items')->where('order_id', $a)->count());
        $this->assertSame(1, DB::table('restaurant_order_items')->where('order_id', $b)->count(),
            'dedupe is scoped to the order — a stale uuid must never swallow a different order\'s items');
    }

    public function test_replay_is_skipped_when_the_column_is_missing(): void
    {
        $c = $this->makeCompany();
        $u = $this->makeWaiter($c);
        $id = $this->makeHeldOrder($c, $u);

        // A host whose schema has not caught up yet (prod drift): the guard must
        // fail OPEN — items still get added, no crash on the unknown column.
        Schema::table('restaurant_order_items', function (Blueprint $table) {
            $table->dropColumn('append_uuid');
        });

        $res = $this->append($id, 'attempt-F');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_order_items')->where('order_id', $id)->count());
    }
}
