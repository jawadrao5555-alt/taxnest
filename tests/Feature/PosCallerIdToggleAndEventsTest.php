<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\PosCallerIdController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CALLER ID (Task #1039) — settings toggle authorization + poll cursor.
 *
 * Invariants locked here:
 *   1. The company-wide Caller ID toggle is admin/manager ONLY. A pos_cashier
 *      gets 403 and no write — even one granted the 'customize' custom-access
 *      feature (posCashierBlocked() alone would let that cashier through).
 *   2. Burst-safe poll cursor: with more than 5 fresh rings pending, last_id
 *      advances only through DELIVERED rows, so the next poll surfaces the
 *      remainder — events must never be silently skipped.
 *   3. When nothing fresh is pending, the cursor jumps past stale rows so old
 *      unseen ids are not re-scanned forever.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same as PosCashReceivedToggleTest).
 */
class PosCallerIdToggleAndEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('caller_id_enabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_caller_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('phone')->nullable();
            $table->string('caller_name')->nullable();
            $table->string('source')->default('sim');
            $table->timestamp('ring_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('status')->nullable();
            $table->string('transaction_type')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        DB::table('companies')->insert(['id' => 1, 'name' => 'Test Cafe']);
        app()->instance('currentCompanyId', 1);
    }

    private function makeUser(string $posRole, ?string $customAccess = null): User
    {
        $user = User::forceCreate([
            'company_id' => 1,
            'name' => $posRole,
            'pos_role' => $posRole,
            'pos_custom_access' => $customAccess,
        ]);
        Auth::guard('pos')->setUser($user);
        return $user;
    }

    private function callToggle(bool $enabled)
    {
        $request = Request::create('/pos/settings/caller-id', 'POST', ['enabled' => $enabled]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->toggle($request);
    }

    private function callEvents(int $after)
    {
        $request = Request::create('/pos/api/caller-events', 'GET', ['after' => $after]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->events($request)->getData(true);
    }

    private function insertEvent(array $attrs = []): int
    {
        return (int) DB::table('pos_caller_events')->insertGetId(array_merge([
            'company_id' => 1,
            'phone' => '923001234567',
            'caller_name' => null,
            'source' => 'sim',
            'ring_at' => now(),
            'created_at' => now(),
        ], $attrs));
    }

    // ─── 1. Toggle authorization ────────────────────────────────────────────

    public function test_admin_and_manager_can_toggle(): void
    {
        foreach (['pos_admin', 'pos_manager'] as $role) {
            $this->makeUser($role);

            $res = $this->callToggle(true);
            $this->assertSame(200, $res->getStatusCode(), $role);
            $this->assertTrue((bool) DB::table('companies')->where('id', 1)->value('caller_id_enabled'), $role);

            $res = $this->callToggle(false);
            $this->assertSame(200, $res->getStatusCode(), $role);
            $this->assertFalse((bool) DB::table('companies')->where('id', 1)->value('caller_id_enabled'), $role);
        }
    }

    public function test_cashier_gets_403_and_no_write(): void
    {
        $this->makeUser('pos_cashier');
        $res = $this->callToggle(true);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertFalse((bool) DB::table('companies')->where('id', 1)->value('caller_id_enabled'));
    }

    public function test_cashier_with_customize_custom_access_still_403(): void
    {
        // A cashier granted 'customize' passes posCashierBlocked() — the toggle
        // must STILL refuse: company-wide integration switch is admin-only.
        $this->makeUser('pos_cashier', json_encode(['customize']));
        $res = $this->callToggle(true);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertFalse((bool) DB::table('companies')->where('id', 1)->value('caller_id_enabled'));
    }

    // ─── 2. Burst-safe poll cursor ──────────────────────────────────────────

    public function test_burst_of_seven_fresh_events_is_fully_delivered_across_polls(): void
    {
        DB::table('companies')->where('id', 1)->update(['caller_id_enabled' => true]);
        $ids = [];
        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->insertEvent(['phone' => '92300123456' . $i]);
        }

        $first = $this->callEvents(0);
        $this->assertTrue($first['enabled']);
        $this->assertCount(5, $first['events']);
        // Cursor advances only through DELIVERED rows — not past the burst.
        $this->assertSame($ids[4], $first['last_id']);

        $second = $this->callEvents($first['last_id']);
        $this->assertCount(2, $second['events']);
        $this->assertSame($ids[6], $second['last_id']);
        $this->assertSame(
            [$ids[5], $ids[6]],
            array_column($second['events'], 'id'),
            'remaining burst events must surface on the next poll'
        );

        $third = $this->callEvents($second['last_id']);
        $this->assertCount(0, $third['events']);
    }

    public function test_stale_rows_advance_cursor_without_being_delivered(): void
    {
        DB::table('companies')->where('id', 1)->update(['caller_id_enabled' => true]);
        $staleId = $this->insertEvent(['created_at' => now()->subMinutes(30), 'ring_at' => now()->subMinutes(30)]);

        $res = $this->callEvents(0);
        $this->assertCount(0, $res['events']);
        // Cursor jumps past stale rows so they are never re-scanned.
        $this->assertSame($staleId, $res['last_id']);
    }

    public function test_disabled_company_gets_empty_response(): void
    {
        $this->insertEvent();
        $res = $this->callEvents(0);
        $this->assertFalse($res['enabled']);
        $this->assertSame([], $res['events']);
    }
}
