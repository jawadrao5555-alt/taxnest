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
 * ADMIN-CONTROLLED WAITER PERMISSIONS — Task 527 (owner voice notes, 12 Aug 2026).
 *
 * Two company-level toggles (waiters are excluded from per-user Custom Access):
 *   pos_waiter_cancel_enabled   — waiter self-cancel, DEFAULT OFF
 *   pos_waiter_takeaway_enabled — waiter takeaway punch, DEFAULT ON
 *
 * Locks:
 *   1. Cancel OFF  → waiter cancel POST = 403, order untouched.
 *   2. Cancel ON   → waiter cancel works (200, status flips).
 *   3. Cancel OFF  → admin/manager on the tablet still cancels (gate is
 *      waiter-role-only).
 *   4. Takeaway OFF → waiter order_type=takeaway = 403, no order row.
 *   5. Takeaway OFF → waiter dine-in punch stays possible.
 *   6. Takeaway ON (default) → waiter takeaway punches normally.
 *   7. Takeaway OFF → admin on the tablet still punches takeaway.
 *   8. AUTHORIZATION on the toggle endpoint itself: only POS admin/manager may
 *      flip the toggles — waiter AND cashier get 403 and the column stays
 *      unchanged (a waiter must never grant themselves cancel/takeaway).
 *   9. PosAuth middleware confines a waiter away from /pos/customize
 *      (redirect to /pos/waiter) — the settings page is unreachable.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same approach as PosDineInTableRequiredTest).
 */
class PosWaiterPermissionTogglesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // PosFeatureService caches restaurantAllowed per company id in a
        // STATIC — earlier suites' verdicts leak into this one. Reset it.
        \App\Services\PosFeatureService::flushGateCaches();

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
            // Task 527 toggles — real prod defaults.
            $table->boolean('pos_waiter_cancel_enabled')->default(false);
            $table->boolean('pos_waiter_takeaway_enabled')->default(true);
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
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
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
    private function makeCompany(array $overrides = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Waiter Perm Co',
            'product_type' => 'pos',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            'feature_flags' => ['kot' => true, 'kitchen' => true],
        ], $overrides));
        app()->instance('currentCompanyId', null); // clear any earlier binding
        app()->bind('currentCompanyId', fn () => $company->id);
        return $company;
    }

    private function makeUser(Company $c, string $posRole, ?string $role = null): User
    {
        $u = User::create([
            'company_id' => $c->id, 'name' => ucfirst($posRole),
            'role' => $role, 'pos_role' => $posRole, 'is_active' => true,
        ]);
        Auth::guard('pos')->setUser($u);
        return $u;
    }

    private function punch(array $payload)
    {
        $request = Request::create('/pos/waiter/orders', 'POST', $payload);
        return app(RestaurantWaiterController::class)->storeOrder($request);
    }

    private function cancel(int $orderId)
    {
        return app(RestaurantWaiterController::class)->cancelOrder(Request::create('/', 'POST'), $orderId);
    }

    private function toggle(string $permission, bool $enabled)
    {
        $request = Request::create('/pos/settings/waiter-permission', 'POST', [
            'permission' => $permission, 'enabled' => $enabled,
        ]);
        return app(\App\Http\Controllers\PosController::class)->toggleWaiterPermission($request);
    }

    private function makeHeldOrder(Company $c, int $createdBy): int
    {
        return DB::table('restaurant_orders')->insertGetId([
            'company_id' => $c->id, 'order_number' => 'W-' . rand(1000, 9999),
            'status' => 'held', 'created_by' => $createdBy, 'source' => 'waiter',
            'subtotal' => 100, 'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function items(): array
    {
        return [['name' => 'Bottle', 'quantity' => 1, 'unit_price' => 100]];
    }

    // ── 1. Cancel permission (default OFF) ──────────────────────────────

    public function test_cancel_off_blocks_waiter_with_403(): void
    {
        $c = $this->makeCompany(); // defaults: cancel OFF
        $waiter = $this->makeUser($c, 'pos_waiter');
        $orderId = $this->makeHeldOrder($c, $waiter->id);

        $res = $this->cancel($orderId);

        $this->assertSame(403, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(__('pos.waiter_cancel_not_allowed'), $body['message']);
        $this->assertSame('held', DB::table('restaurant_orders')->find($orderId)->status);
    }

    public function test_cancel_on_lets_waiter_cancel(): void
    {
        $c = $this->makeCompany(['pos_waiter_cancel_enabled' => true]);
        $waiter = $this->makeUser($c, 'pos_waiter');
        $orderId = $this->makeHeldOrder($c, $waiter->id);

        $this->assertSame(200, $this->cancel($orderId)->getStatusCode());
        $this->assertSame('cancelled', DB::table('restaurant_orders')->find($orderId)->status);
    }

    public function test_cancel_off_does_not_block_admin_on_tablet(): void
    {
        $c = $this->makeCompany(); // cancel OFF
        $admin = $this->makeUser($c, 'pos_admin', 'company_admin');
        $orderId = $this->makeHeldOrder($c, $admin->id);

        $this->assertSame(200, $this->cancel($orderId)->getStatusCode());
        $this->assertSame('cancelled', DB::table('restaurant_orders')->find($orderId)->status);
    }

    // ── 2. Takeaway permission (default ON) ─────────────────────────────

    public function test_takeaway_off_rejects_waiter_takeaway_punch(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => false]);
        $this->makeUser($c, 'pos_waiter');

        $res = $this->punch(['items' => $this->items(), 'order_type' => 'takeaway']);

        $this->assertSame(403, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(__('pos.waiter_takeaway_not_allowed'), $body['message']);
        $this->assertSame(0, DB::table('restaurant_orders')->count(), 'no order/KOT may be created');
    }

    public function test_takeaway_off_keeps_waiter_dine_in_possible(): void
    {
        // tables feature OFF here → dine-in without table stays a valid punch.
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => false]);
        $this->makeUser($c, 'pos_waiter');

        $res = $this->punch(['items' => $this->items(), 'order_type' => 'dine_in']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('order_type', 'dine_in')->count());
    }

    public function test_takeaway_default_on_waiter_punches_normally(): void
    {
        $c = $this->makeCompany(); // default ON
        $this->makeUser($c, 'pos_waiter');

        $res = $this->punch(['items' => $this->items(), 'order_type' => 'takeaway']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('order_type', 'takeaway')->count());
    }

    public function test_takeaway_off_does_not_block_admin_on_tablet(): void
    {
        $c = $this->makeCompany(['pos_waiter_takeaway_enabled' => false]);
        $this->makeUser($c, 'pos_admin', 'company_admin');

        $res = $this->punch(['items' => $this->items(), 'order_type' => 'takeaway']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(1, DB::table('restaurant_orders')->where('order_type', 'takeaway')->count());
    }

    // ── 3. Toggle endpoint authorization ────────────────────────────────
    // A waiter (or cashier) must NEVER be able to flip these permissions —
    // that would let waiters grant themselves cancel/takeaway.

    public function test_waiter_cannot_flip_toggles(): void
    {
        $c = $this->makeCompany(); // cancel OFF, takeaway ON
        $this->makeUser($c, 'pos_waiter');

        $res = $this->toggle('cancel', true);
        $this->assertSame(403, $res->getStatusCode());
        $res = $this->toggle('takeaway', false);
        $this->assertSame(403, $res->getStatusCode());

        $c->refresh();
        $this->assertFalse((bool) $c->pos_waiter_cancel_enabled, 'cancel must stay OFF');
        $this->assertTrue((bool) $c->pos_waiter_takeaway_enabled, 'takeaway must stay ON');
    }

    public function test_cashier_cannot_flip_toggles(): void
    {
        $c = $this->makeCompany();
        $this->makeUser($c, 'pos_cashier');

        $this->assertSame(403, $this->toggle('cancel', true)->getStatusCode());

        $c->refresh();
        $this->assertFalse((bool) $c->pos_waiter_cancel_enabled);
    }

    public function test_admin_and_manager_can_flip_toggles(): void
    {
        $c = $this->makeCompany();
        $this->makeUser($c, 'pos_admin', 'company_admin');

        $res = $this->toggle('cancel', true);
        $this->assertSame(200, $res->getStatusCode());
        $c->refresh();
        $this->assertTrue((bool) $c->pos_waiter_cancel_enabled);

        $this->makeUser($c, 'pos_manager');
        $res = $this->toggle('takeaway', false);
        $this->assertSame(200, $res->getStatusCode());
        $c->refresh();
        $this->assertFalse((bool) $c->pos_waiter_takeaway_enabled);
    }

    // ── 4. Middleware confinement — waiter can't even open the settings page ──

    public function test_pos_auth_middleware_redirects_waiter_away_from_customize(): void
    {
        $c = $this->makeCompany();
        $waiter = $this->makeUser($c, 'pos_waiter');

        $middleware = new \App\Http\Middleware\PosAuth();
        $request = Request::create('/pos/customize', 'GET');

        $response = $middleware->handle($request, fn () => response('SHOULD-NOT-REACH'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/pos/waiter', $response->headers->get('Location'));

        // POST to the toggle route is equally confined.
        $response = $middleware->handle(
            Request::create('/pos/settings/waiter-permission', 'POST'),
            fn () => response('SHOULD-NOT-REACH')
        );
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/pos/waiter', $response->headers->get('Location'));
    }
}
