<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantKdsController;
use App\Models\Company;
use App\Services\KotPrintService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1356 — "Bill final ho to KOT zaroor jaye".
 *
 * Owner video (dine-in, Table No 02): the cashier hit CASH without ever
 * pressing "Send to Kitchen". The customer's bill printed, but the kitchen got
 * nothing — no slip, and the paid order never appeared on the KDS board either
 * (the board only listed held/preparing/ready). The customer had already paid
 * and the food had not even been started.
 *
 * This file locks the SERVER half of the fix — the signal the sale screen acts
 * on, and the KDS board rescue. The CLIENT half (the auto-print chain rule) is
 * locked by scripts/kot-on-final-check.mjs, which runs in the deploy preflight.
 *
 * Invariants under lock:
 *   • The "kitchen never saw this" signal comes from LINE-level kot_printed_at,
 *     never from restaurant_orders.kot_sent_at — hold stamps kot_sent_at on
 *     EVERY held order even when no ticket was ever rendered or enqueued, which
 *     is precisely why the bug was invisible.
 *   • The signal is false unless the shop really uses kitchen tickets
 *     (restaurant mode + the KOT feature) — plain retail can never be handed a
 *     surprise kitchen slip.
 *   • The shop-level switch (kot_on_final_if_unsent) turns the net off, and a
 *     missing column / NULL counts as ON so an un-migrated PROD behaves the same.
 *   • BOTH kitchen-flow toggles are mass-assignable and both bust the sale
 *     screen's boot fingerprint — otherwise a saved setting is either dropped
 *     outright or ignored by an already-cached/offline sale screen.
 *   • The KDS board keeps a paid-but-never-seen order visible for the current
 *     business day, so a KDS-only shop does not lose the order either.
 *
 * NEVER "fix" a failure here by falling back to kot_sent_at, or by widening the
 * KDS board to all completed orders — both re-open the bug this file exists for.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosKotOnFinalSafetyNetTest.php --testdox
 */
class PosKotOnFinalSafetyNetTest extends TestCase
{
    private const COMPANY_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('is_internal_account')->default(true); // plan gate: restaurant allowed
            $t->boolean('restaurant_mode')->default(true);
            $t->boolean('kot_on_final_if_unsent')->default(true);
            $t->boolean('delivery_kot_after_payment')->default(false);
            $t->text('feature_flags')->nullable();
            $t->string('pos_business_day_cutoff')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('order_number');
            $t->unsignedBigInteger('table_id')->nullable();
            $t->string('order_type')->default('dine_in');
            $t->string('status')->default('held');
            $t->string('kitchen_status')->nullable();
            $t->timestamp('kot_sent_at')->nullable();
            $t->timestamp('kitchen_cleared_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('item_name');
            $t->decimal('quantity', 8, 2)->default(1);
            $t->timestamp('kot_printed_at')->nullable();
            $t->timestamps();
        });

        // Eager-load targets of the KDS board query.
        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('table_number')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        DB::table('companies')->insert([
            'id' => self::COMPANY_ID,
            'name' => 'KOT Safety Net Co',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            'kot_on_final_if_unsent' => true,
            // Feature flags are an explicit per-company snapshot (base defaults are
            // all-false); 'kot' also depends on 'kitchen'.
            'feature_flags' => json_encode(['kot' => true, 'kitchen' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->instance('currentCompanyId', self::COMPANY_ID);
        \App\Services\PosBusinessDay::forgetCutoff(self::COMPANY_ID);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param  array<int, bool>  $linesPrinted  one entry per line; true = the kitchen printed it
     */
    private function makeOrder(array $linesPrinted, array $attrs = []): \App\Models\RestaurantOrder
    {
        $id = DB::table('restaurant_orders')->insertGetId(array_merge([
            'company_id' => self::COMPANY_ID,
            'order_number' => 'ORD-' . uniqid(),
            'order_type' => 'dine_in',
            'status' => 'held',
            // Deliberately stamped on EVERY order: hold does this unconditionally,
            // so any implementation that trusts it will fail these tests.
            'kot_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        foreach ($linesPrinted as $i => $printed) {
            DB::table('restaurant_order_items')->insert([
                'order_id' => $id,
                'item_name' => 'Item ' . ($i + 1),
                'quantity' => 1,
                'kot_printed_at' => $printed ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return \App\Models\RestaurantOrder::find($id);
    }

    private function company(array $attrs = []): Company
    {
        if ($attrs) {
            DB::table('companies')->where('id', self::COMPANY_ID)->update($attrs);
        }
        \App\Services\PosFeatureService::flushGateCaches();

        return Company::find(self::COMPANY_ID);
    }

    /** The real (private) KDS board filter — index() and liveOrders() both use it. */
    private function boardOrderIds(): array
    {
        $controller = new RestaurantKdsController();
        $method = (new \ReflectionClass($controller))->getMethod('boardOrders');
        $method->setAccessible(true);

        return $method->invoke($controller, self::COMPANY_ID)->pluck('id')->sort()->values()->all();
    }

    // ── 1. The signal: line stamps, never kot_sent_at ─────────────────────

    public function test_dine_in_cart_settled_straight_to_cash_still_owes_the_kitchen_a_ticket(): void
    {
        // The owner's Table 02 bill: kot_sent_at IS set (hold stamps it), but not
        // one line ever reached a printer.
        $order = $this->makeOrder([false, false]);

        $this->assertSame(2, KotPrintService::unseenLineCount($order));
        $this->assertTrue(
            KotPrintService::pendingForFinal($this->company(), $order),
            'A dine-in bill finalized without "Send to Kitchen" must report a pending kitchen ticket'
        );
    }

    public function test_fully_printed_order_never_owes_a_second_ticket(): void
    {
        $order = $this->makeOrder([true, true]);

        $this->assertSame(0, KotPrintService::unseenLineCount($order));
        $this->assertFalse(
            KotPrintService::pendingForFinal($this->company(), $order),
            'Settling a held / waiter order whose KOT already printed must NOT produce a second slip'
        );
    }

    public function test_partially_printed_order_owes_only_the_unseen_lines(): void
    {
        // Recall + append: two lines already went to the kitchen, one is new.
        $order = $this->makeOrder([true, true, false]);

        $this->assertSame(1, KotPrintService::unseenLineCount($order));
        $this->assertTrue(KotPrintService::pendingForFinal($this->company(), $order));
    }

    public function test_empty_order_can_never_trigger_a_blank_slip(): void
    {
        $order = $this->makeOrder([]);

        $this->assertSame(0, KotPrintService::unseenLineCount($order));
        $this->assertFalse(KotPrintService::pendingForFinal($this->company(), $order));
        $this->assertFalse(KotPrintService::pendingForFinal($this->company(), null));
        $this->assertSame(0, KotPrintService::unseenLineCount(null));
    }

    // ── 2. Gates: toggle, restaurant mode, KOT feature ────────────────────

    public function test_shop_toggle_turns_the_safety_net_off(): void
    {
        $order = $this->makeOrder([false]);

        $this->assertFalse(
            KotPrintService::pendingForFinal($this->company(['kot_on_final_if_unsent' => false]), $order),
            'kot_on_final_if_unsent = OFF must silence the net completely'
        );
        $this->assertTrue(
            KotPrintService::pendingForFinal($this->company(['kot_on_final_if_unsent' => true]), $order)
        );
    }

    public function test_toggle_defaults_to_on_when_the_column_is_missing_or_null(): void
    {
        $order = $this->makeOrder([false]);

        // Un-migrated PROD (column absent) and a NULL value must both behave as ON.
        $company = $this->company();
        $company->setAttribute('kot_on_final_if_unsent', null);
        $this->assertTrue(KotPrintService::pendingForFinal($company, $order));

        $bare = $this->company();
        unset($bare->kot_on_final_if_unsent);
        $this->assertTrue(KotPrintService::pendingForFinal($bare, $order));
    }

    public function test_plain_retail_shop_never_gets_a_kitchen_ticket(): void
    {
        $order = $this->makeOrder([false]);

        $this->assertFalse(
            KotPrintService::pendingForFinal($this->company(['restaurant_mode' => false]), $order),
            'A non-restaurant shop must never be handed a kitchen slip'
        );
    }

    public function test_restaurant_shop_with_the_kot_feature_off_gets_nothing(): void
    {
        $order = $this->makeOrder([false]);
        $company = $this->company(['feature_flags' => json_encode(['kot' => false, 'kitchen' => true])]);

        $this->assertFalse(
            KotPrintService::pendingForFinal($company, $order),
            'The net must respect the per-company KOT feature flag'
        );
    }

    // ── 3. Settings plumbing: saved AND seen by a cached sale screen ───────

    public function test_both_kitchen_flow_toggles_are_mass_assignable(): void
    {
        // delivery_kot_after_payment was missing from $fillable since Aug 2026:
        // Kitchen Settings "saved" it and Eloquent silently dropped the write.
        $company = $this->company();
        $company->update([
            'kot_on_final_if_unsent' => false,
            'delivery_kot_after_payment' => true,
        ]);

        $row = DB::table('companies')->where('id', self::COMPANY_ID)->first();
        $this->assertEquals(0, $row->kot_on_final_if_unsent, 'kot_on_final_if_unsent was not persisted');
        $this->assertEquals(1, $row->delivery_kot_after_payment, 'delivery_kot_after_payment was not persisted');
    }

    public function test_both_toggles_bust_the_sale_screen_boot_fingerprint(): void
    {
        // The sale screen bakes both flags into kitchenSettings and is served
        // cache-first; if they are not in posConfigRev() an offline/cached screen
        // keeps obeying the old setting forever.
        foreach (['kot_on_final_if_unsent' => false, 'delivery_kot_after_payment' => true] as $column => $value) {
            $before = $this->company()->posConfigRev();
            $after = $this->company([$column => $value])->posConfigRev();
            $this->assertNotSame($before, $after, "Changing {$column} must change posConfigRev()");
        }
    }

    // ── 4. KDS board rescue ───────────────────────────────────────────────

    public function test_kds_board_keeps_a_paid_order_the_kitchen_never_saw(): void
    {
        $held = $this->makeOrder([false], ['status' => 'held']);
        $paidUnseen = $this->makeOrder([false], ['status' => 'completed']);
        $paidPrinted = $this->makeOrder([true], ['status' => 'completed']);
        $cleared = $this->makeOrder([false], ['status' => 'completed', 'kitchen_cleared_at' => now()]);
        $cancelled = $this->makeOrder([false], ['status' => 'cancelled']);

        $ids = $this->boardOrderIds();

        $this->assertContains($held->id, $ids, 'held orders must stay on the board');
        $this->assertContains($paidUnseen->id, $ids, 'a paid order the kitchen never saw must stay visible or it is lost forever');
        $this->assertNotContains($paidPrinted->id, $ids, 'a paid order the kitchen already cooked must not reappear');
        $this->assertNotContains($cleared->id, $ids, 'a cleared order must stay off the board');
        $this->assertNotContains($cancelled->id, $ids, 'cancelled orders must never surface');
    }

    public function test_kds_rescue_is_limited_to_the_current_business_day(): void
    {
        $today = $this->makeOrder([false], ['status' => 'completed']);
        $old = $this->makeOrder([false], ['status' => 'completed']);
        DB::table('restaurant_orders')->where('id', $old->id)
            ->update(['created_at' => now()->subDays(3)]);

        $ids = $this->boardOrderIds();

        $this->assertContains($today->id, $ids);
        $this->assertNotContains($old->id, $ids, 'the board must not fill up with old unseen orders');
    }

    public function test_kds_rescue_window_opens_at_the_company_business_day_cutoff(): void
    {
        // Cutoff 06:00 (default). An order booked just before this morning's
        // cutoff belongs to YESTERDAY's trading day → off the board; one booked
        // after it is today's → on the board. Frozen mid-afternoon so the
        // window is unambiguous.
        $this->travelTo(now()->startOfDay()->addHours(15));
        \App\Services\PosBusinessDay::forgetCutoff(self::COMPANY_ID);

        $yesterday = $this->makeOrder([false], ['status' => 'completed']);
        DB::table('restaurant_orders')->where('id', $yesterday->id)
            ->update(['created_at' => now()->startOfDay()->addHours(5)->subMinute()]);

        $today = $this->makeOrder([false], ['status' => 'completed']);
        DB::table('restaurant_orders')->where('id', $today->id)
            ->update(['created_at' => now()->startOfDay()->addHours(7)]);

        $ids = $this->boardOrderIds();

        $this->assertNotContains($yesterday->id, $ids, 'pre-cutoff orders belong to the previous business day');
        $this->assertContains($today->id, $ids);

        $this->travelBack();
    }
}
