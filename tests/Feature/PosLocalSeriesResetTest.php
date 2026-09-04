<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Http\Controllers\RestaurantPosController;
use App\Models\User;
use App\Services\PlanLimitService;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Archived-local-bill clearing plus the durable, monotonic L-series.
 *
 * Clearing records must never rewind or reuse an L-reference. The company
 * counter is a permanent high-water mark; surviving/imported exact L-NNN rows
 * can move it forward, but deleting, archiving or promoting a bill cannot move
 * it backward.
 *
 * Locked here:
 *  1. STATUS FIGURES: the card counts only bills that really block the series,
 *     with their date range and the same monotonic next number before/after a
 *     clear.
 *  2. CLEAR = DAY-CLOSE DELETE RULES: PRA/fiscal bills, unsettled rider CASH
 *     bills, returns, legacy LOCAL-YYYY rows and LIVE (un-archived) bills all
 *     survive; only archived provisionals + reporting-OFF finals go.
 *  3. Items and payments of the deleted bills go with them.
 *  4. SERIES CONTINUITY: after the clear both generators (retail PosController
 *     and RestaurantPosController) continue above the historical high-water.
 *  5. SPEND RECORD: customer spend snapshots are written BEFORE deleting when
 *     the company keeps the spend record, and skipped when it is OFF.
 *  6. QUOTA HONESTY: deleted CURRENT-MONTH reporting-OFF finals are added back
 *     via pos_local_series_resets, so clearing can never buy back monthly bill
 *     quota; older months are not inflated.
 *  7. ADMIN ONLY: a cashier posting the URL directly is refused (403) and
 *     nothing is deleted.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create; HTTP
 * through the real route/middleware (copied from PosDayClosePerBillActionTest).
 */
class PosLocalSeriesResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Carbon::setTestNow(now()->setTime(12, 0));

        User::flushScopeColumnCache();
        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->text('feature_flags')->nullable();
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_local_series_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('order_type')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_customer_spend_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('bill_kind')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('sold_at')->nullable();
            $table->unsignedBigInteger('dayclose_report_id')->nullable();
            $table->timestamps();
        });

        // The quota add-back ledger written by the clear action.
        Schema::create('pos_local_series_resets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('reset_date');
            $table->unsignedInteger('deleted_final_count')->default(0);
            $table->unsignedInteger('deleted_provisional_count')->default(0);
            $table->unsignedInteger('total_deleted')->default(0);
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamps();
        });

        // Sibling add-back source consulted by the same quota counter.
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->integer('deleted_final_count')->default(0);
            $table->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'ZFC Pizza Point',
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => true, // bypass plan gates unless a test opts in
            'restaurant_mode' => false,
            'invoice_limit_override' => -1,
            'pra_reporting_enabled' => false,
            'pos_dayclose_provisional_action' => 'delete',
            'pos_dayclose_final_local_action' => 'delete',
            'pos_customer_spend_persist' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makeUser(int $companyId, ?string $posRole = null): User
    {
        static $seq = 0;
        $id = DB::table('users')->insertGetId([
            'name' => $posRole ?? 'Owner',
            'email' => ($posRole ?? 'owner') . $companyId . '-' . (++$seq) . '@lseries.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => $posRole === null ? 'company_admin' : 'user',
            'pos_role' => $posRole,
            'is_active' => true,
            'language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::find($id);
    }

    /** Archived provisional (local+local triple) unless overridden. */
    private function makeBill(int $companyId, string $number, array $attrs = []): int
    {
        $id = (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'is_archived' => true,
            'subtotal' => 500,
            'total_amount' => 500,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        DB::table('pos_transaction_items')->insert([
            'transaction_id' => $id,
            'item_name' => 'Zinger Burger',
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_payments')->insert([
            'transaction_id' => $id,
            'payment_method' => 'cash',
            'amount' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (preg_match('/^L-?(\d+)$/', $number, $match)) {
            $highest = max(
                (int) (DB::table('pos_local_series_counters')->where('company_id', $companyId)->value('last_number') ?? 0),
                (int) $match[1]
            );
            DB::table('pos_local_series_counters')->updateOrInsert(
                ['company_id' => $companyId],
                ['last_number' => $highest, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return $id;
    }

    private function clear(User $user)
    {
        return $this->actingAs($user, 'pos')
            ->postJson('/pos/settings/local-billing/clear-archived');
    }

    private function seriesStatus(int $companyId): array
    {
        return $this->callPrivate(new PosController(), 'localSeriesStatus', [$companyId]);
    }

    private function nextRetail(int $companyId): string
    {
        return $this->callPrivate(new PosController(), 'generateLocalInvoiceNumber', [$companyId]);
    }

    private function nextRestaurant(int $companyId): string
    {
        return $this->callPrivate(new RestaurantPosController(), 'generateLocalInvoiceNumber', [$companyId]);
    }

    private function nextPreview(int $companyId, array $excludeIds = []): string
    {
        return $this->callPrivate(new PosController(), 'previewNextLocalNumber', [$companyId, $excludeIds]);
    }

    private function callPrivate(object $target, string $method, array $args)
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($target, $args);
    }

    private function callPrivateStatic(string $class, string $method, array $args)
    {
        $ref = new \ReflectionMethod($class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs(null, $args);
    }

    private function numbers(int $companyId): array
    {
        return DB::table('pos_transactions')->where('company_id', $companyId)
            ->orderBy('invoice_number')->pluck('invoice_number')->all();
    }

    // ── 1. status figures ────────────────────────────────────────────────────

    public function test_status_reports_blockers_date_range_and_both_next_numbers(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001', ['business_date' => '2026-07-15']);
        $this->makeBill($cid, 'L002', ['business_date' => '2026-08-01']);
        $this->makeBill($cid, 'L003', ['business_date' => '2026-08-19']);
        // Live bill of the running day — blocks nothing, is never cleared.
        $this->makeBill($cid, 'L004', ['is_archived' => false]);

        $status = $this->seriesStatus($cid);

        $this->assertSame(3, $status['count']);
        $this->assertSame('2026-07-15', $status['from']);
        $this->assertSame('2026-08-19', $status['to']);
        $this->assertSame('L005', $status['next']);
        $this->assertSame('L005', $status['next_after']);
    }

    public function test_status_is_silent_when_nothing_blocks_the_series(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001', ['is_archived' => false]);

        $status = $this->seriesStatus($cid);

        $this->assertSame(0, $status['count']);
        $this->assertSame('L002', $status['next']);
    }

    // ── 2/3/4. the clear itself ──────────────────────────────────────────────

    public function test_clear_removes_archived_local_bills_without_rewinding_series(): void
    {
        $cid = $this->makeCompany();
        $prov = $this->makeBill($cid, 'L001');
        $final = $this->makeBill($cid, 'L002', ['invoice_mode' => 'pra', 'pra_status' => null]);
        // Everything below must SURVIVE.
        $pra = $this->makeBill($cid, 'L003', [
            'invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => '1234567890123',
        ]);
        $riderCash = $this->makeBill($cid, 'L004', [
            'rider_id' => 7, 'payment_method' => 'cash', 'rider_settlement_id' => null, 'delivery_status' => 'dispatched',
        ]);
        $live = $this->makeBill($cid, 'L005', ['is_archived' => false]);
        $return = $this->makeBill($cid, 'L006', ['transaction_type' => 'return']);
        $legacy = $this->makeBill($cid, 'LOCAL-2026-00007');

        $res = $this->clear($this->makeUser($cid));

        $res->assertStatus(200)->assertJson(['success' => true, 'deleted' => 2, 'next_number' => 'L007']);
        $this->assertSame(
            ['L003', 'L004', 'L005', 'L006', 'LOCAL-2026-00007'],
            $this->numbers($cid)
        );
        foreach ([$pra, $riderCash, $live, $return, $legacy] as $keptId) {
            $this->assertDatabaseHas('pos_transactions', ['id' => $keptId]);
        }
        // Items + payments of the deleted bills go with them; survivors keep theirs.
        foreach ([$prov, $final] as $goneId) {
            $this->assertSame(0, DB::table('pos_transaction_items')->where('transaction_id', $goneId)->count());
            $this->assertSame(0, DB::table('pos_payments')->where('transaction_id', $goneId)->count());
        }
        $this->assertSame(1, DB::table('pos_transaction_items')->where('transaction_id', $pra)->count());
        $this->assertSame(1, DB::table('pos_payments')->where('transaction_id', $pra)->count());
    }

    public function test_after_clear_both_generators_continue_above_high_water(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001');
        $this->makeBill($cid, 'L002');
        $this->makeBill($cid, 'L003');

        $this->clear($this->makeUser($cid))->assertStatus(200);

        // Retail sale screen takes L-004 …
        $this->assertSame('L004', $this->nextRetail($cid));
        $this->makeBill($cid, 'L004', ['is_archived' => false]);
        // … and the restaurant dine-in pay path continues the SAME series.
        $this->assertSame('L005', $this->nextRestaurant($cid));
        $this->makeBill($cid, 'L005', ['is_archived' => false]);
        $this->assertSame('L006', $this->nextRetail($cid));
    }

    public function test_day_after_delete_policy_close_never_reuses_old_numbers(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001');
        $this->makeBill($cid, 'L002');
        $this->clear($this->makeUser($cid))->assertStatus(200);

        // Next trading day under the delete policy: newly allocated references
        // are later removed, but the durable counter must still remember them.
        $this->assertSame('L003', $this->nextRetail($cid));
        $b1 = $this->makeBill($cid, 'L003', ['is_archived' => false]);
        $this->assertSame('L004', $this->nextRetail($cid));
        $b2 = $this->makeBill($cid, 'L004', ['is_archived' => false]);
        DB::table('pos_transactions')->whereIn('id', [$b1, $b2])->delete();

        $this->assertSame('L005', $this->nextRetail($cid));
    }

    public function test_clear_with_nothing_to_remove_is_a_harmless_noop(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001', ['is_archived' => false]);

        $this->clear($this->makeUser($cid))
            ->assertStatus(200)
            ->assertJson(['success' => true, 'deleted' => 0, 'next_number' => 'L002']);

        $this->assertSame(['L001'], $this->numbers($cid));
        $this->assertSame(0, DB::table('pos_local_series_resets')->count());
    }

    // ── 4b. saying which rider-held records were deliberately kept ───────────

    /**
     * The clear spares archived bills whose rider cash is still unsettled.
     * The response must say how many records were kept and why.
     */
    public function test_clear_reports_the_bills_a_rider_still_holds(): void
    {
        $cid = $this->makeCompany();
        $riderArgs = ['rider_id' => 7, 'payment_method' => 'cash', 'rider_settlement_id' => null, 'delivery_status' => 'dispatched'];
        $this->makeBill($cid, 'L001', $riderArgs);
        $this->makeBill($cid, 'L002', $riderArgs);
        $this->makeBill($cid, 'L003');
        $this->makeBill($cid, 'L004');
        $this->makeBill($cid, 'L005');

        $res = $this->clear($this->makeUser($cid));

        // Figures stay honest: three deleted, numbering continues above every
        // reference that existed before the clear.
        $res->assertStatus(200)->assertJson([
            'success' => true,
            'deleted' => 3,
            'next_number' => 'L006',
            'rider_held' => 2,
        ]);
        // …and the reason is spelled out from the lang file, never hardcoded.
        $this->assertSame(
            trans('pos.local_series_rider_kept', ['count' => 2], 'en'),
            $res->json('rider_held_message')
        );
        $this->assertSame(['L001', 'L002'], $this->numbers($cid));
    }

    public function test_no_rider_note_when_the_clear_kept_nothing_back(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001');
        $this->makeBill($cid, 'L002');

        $res = $this->clear($this->makeUser($cid));

        $res->assertStatus(200)->assertJson(['deleted' => 2, 'next_number' => 'L003', 'rider_held' => 0]);
        $this->assertNull($res->json('rider_held_message'), 'nothing was kept — the card must stay quiet');
    }

    /**
     * The kept count is the exact COMPLEMENT of the delete rule, never a loose
     * "has a rider" count: settled, returned and card rider bills are deleted
     * like any other archived local bill, and a stray "L-…" row reserves no
     * number so it is neither deleted nor reported as a blocker.
     */
    public function test_only_genuinely_held_l_series_bills_are_reported_as_kept(): void
    {
        $cid = $this->makeCompany();
        $rider = ['rider_id' => 7, 'payment_method' => 'cash', 'delivery_status' => 'dispatched', 'rider_settlement_id' => null];
        $this->makeBill($cid, 'L001', array_merge($rider, ['rider_settlement_id' => 55]));      // cash already handed over
        $this->makeBill($cid, 'L002', array_merge($rider, ['delivery_status' => 'returned']));  // never delivered
        $this->makeBill($cid, 'L003', ['rider_id' => 7, 'payment_method' => 'card']);           // shop got the money
        $this->makeBill($cid, 'L-ABC', $rider);                                                  // reserves no number
        $this->makeBill($cid, 'L004', $rider);                                                  // the only real blocker

        $res = $this->clear($this->makeUser($cid));

        $res->assertStatus(200)->assertJson(['deleted' => 3, 'rider_held' => 1, 'next_number' => 'L005']);
        $this->assertSame(['L-ABC', 'L004'], $this->numbers($cid));
    }

    /**
     * The worst-looking case: every archived bill is khata-held, so the clear
     * deletes nothing at all. "Nothing left to clear" alone would be a flat lie
     * about numbering — the kept count must ride along on this branch too.
     */
    public function test_nothing_to_clear_still_reports_the_held_bills(): void
    {
        $cid = $this->makeCompany();
        $riderArgs = ['rider_id' => 7, 'payment_method' => 'cash', 'rider_settlement_id' => null, 'delivery_status' => 'delivered'];
        $this->makeBill($cid, 'L001', $riderArgs);
        $this->makeBill($cid, 'L002', $riderArgs);

        $res = $this->clear($this->makeUser($cid));

        $res->assertStatus(200)->assertJson(['deleted' => 0, 'next_number' => 'L003', 'rider_held' => 2]);
        $this->assertSame(
            trans('pos.local_series_rider_kept', ['count' => 2], 'en'),
            $res->json('rider_held_message')
        );
        $this->assertSame(['L001', 'L002'], $this->numbers($cid));
    }

    // ── 5. customer spend record ─────────────────────────────────────────────

    public function test_spend_snapshot_is_written_before_deleting_when_persist_is_on(): void
    {
        $cid = $this->makeCompany(['pos_customer_spend_persist' => true]);
        $this->makeBill($cid, 'L001', [
            'customer_id' => 42, 'customer_name' => 'Bilal', 'customer_phone' => '03001234567', 'total_amount' => 750,
        ]);
        $this->makeBill($cid, 'L002', [
            'invoice_mode' => 'pra', 'pra_status' => null,
            'customer_phone' => '03009999999', 'total_amount' => 250,
        ]);
        // Walk-in bill (no customer) leaves no snapshot.
        $this->makeBill($cid, 'L003');

        $this->clear($this->makeUser($cid))->assertStatus(200);

        $this->assertSame(2, DB::table('pos_customer_spend_snapshots')->count());
        $this->assertDatabaseHas('pos_customer_spend_snapshots', [
            'company_id' => $cid, 'customer_id' => 42, 'invoice_number' => 'L001',
            'bill_kind' => 'provisional', 'total_amount' => 750,
        ]);
        $this->assertDatabaseHas('pos_customer_spend_snapshots', [
            'invoice_number' => 'L002', 'bill_kind' => 'final_local', 'total_amount' => 250,
        ]);
    }

    public function test_no_spend_snapshot_when_the_setting_is_off(): void
    {
        $cid = $this->makeCompany(['pos_customer_spend_persist' => false]);
        $this->makeBill($cid, 'L001', ['customer_id' => 42, 'customer_phone' => '03001234567']);

        $this->clear($this->makeUser($cid))->assertStatus(200);

        $this->assertSame(0, DB::table('pos_customer_spend_snapshots')->count());
    }

    // ── 6. quota honesty ─────────────────────────────────────────────────────

    public function test_clearing_never_buys_back_monthly_bill_quota(): void
    {
        // Real (non-internal) shop on a 2-bills-per-month allowance.
        $cid = $this->makeCompany(['is_internal_account' => false, 'invoice_limit_override' => 2]);
        $finalArgs = ['invoice_mode' => 'pra', 'pra_status' => null];
        $this->makeBill($cid, 'L001', $finalArgs);
        $this->makeBill($cid, 'L002', $finalArgs);
        // Last month's archived final — cleared too, but it must NOT be added to
        // THIS month's count.
        $this->makeBill($cid, 'L003', $finalArgs + [
            'business_date' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'created_at' => now()->subMonthNoOverflow()->startOfMonth(),
        ]);
        // A provisional never consumed quota, so it is never added back either.
        $this->makeBill($cid, 'L004');

        $this->assertFalse(PlanLimitService::canCreatePosBill($cid)['allowed'], 'quota should be full before the clear');

        $this->clear($this->makeUser($cid))->assertStatus(200)->assertJson(['deleted' => 4]);

        $ledger = DB::table('pos_local_series_resets')->where('company_id', $cid)->first();
        $this->assertSame(2, (int) $ledger->deleted_final_count);
        $this->assertSame(1, (int) $ledger->deleted_provisional_count);
        $this->assertSame(4, (int) $ledger->total_deleted);

        $quota = PlanLimitService::canCreatePosBill($cid);
        $this->assertFalse($quota['allowed'], 'clearing must not hand the shop free bills');
        $this->assertStringContainsString('2/2', $quota['reason']);
    }

    /**
     * Month-boundary basis: an after-midnight final (created 1 Aug 00:30, business
     * date 31 Jul) is inside AUGUST's live quota — PlanLimitService counts
     * created_at. The ledger add-back is credited to the month the reset runs in,
     * so it must use the SAME created_at basis; counting by business_date would
     * write it off as July and quietly return the slot to the shop.
     */
    public function test_after_midnight_final_still_counts_after_the_clear(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-08-01 09:00:00'));

        $cid = $this->makeCompany(['is_internal_account' => false, 'invoice_limit_override' => 1]);
        // Sold after midnight, so it belongs to July's TRADING day but to August's
        // calendar month — the month the quota counter is looking at right now.
        $this->makeBill($cid, 'L001', [
            'invoice_mode' => 'pra',
            'pra_status' => null,
            'business_date' => '2026-07-31',
            'created_at' => \Illuminate\Support\Carbon::parse('2026-08-01 00:30:00'),
        ]);

        $this->assertFalse(PlanLimitService::canCreatePosBill($cid)['allowed'], 'quota should be full before the clear');

        $this->clear($this->makeUser($cid))->assertStatus(200)->assertJson(['deleted' => 1]);

        $ledger = DB::table('pos_local_series_resets')->where('company_id', $cid)->first();
        $this->assertSame(1, (int) $ledger->deleted_final_count, 'August live count must be credited back');

        $this->assertFalse(
            PlanLimitService::canCreatePosBill($cid)['allowed'],
            'an after-midnight final must not become a free bill slot'
        );
    }

    /**
     * Only EXACT L-NNN serials reserve a number. A stray archived bill whose number
     * merely starts with "L-" ("L-ABC", "L-001-extra") blocks nothing, so it must not
     * be counted as a blocker and — the clear being permanent — must not be deleted.
     */
    public function test_non_numeric_l_bills_are_never_counted_or_deleted(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001');
        $this->makeBill($cid, 'L-ABC');
        $this->makeBill($cid, 'L-001-extra');

        $status = $this->seriesStatus($cid);
        $this->assertSame(1, $status['count'], 'only the real L-001 holds a number');
        $this->assertSame('L002', $status['next_after']);

        $this->clear($this->makeUser($cid))->assertStatus(200)->assertJson([
            'deleted' => 1,
            'next_number' => 'L002',
        ]);

        $this->assertSame(['L-001-extra', 'L-ABC'], $this->numbers($cid), 'stray L-* bills must survive');
        // Their items/payments stay too — nothing about them was touched.
        $this->assertSame(2, DB::table('pos_transaction_items')->count());
        $this->assertSame(2, DB::table('pos_payments')->count());
        // The deleted real serial remains consumed by the durable counter.
        $this->assertSame('L002', $this->nextRetail($cid));
    }

    /**
     * Housekeeping language is a safety feature: deleting bill detail is not a
     * numbering reset, and retained customer spend history can only be purged by
     * its own explicit action.  Check all supported POS languages so a locale
     * cannot quietly reintroduce the unsafe promise.
     */
    public function test_customize_ui_explains_monotonic_numbering_and_explicit_history_purge(): void
    {
        $customize = file_get_contents(base_path('resources/views/pos/customize.blade.php'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("__('pos.local_billing_numbering_never_resets')", $customize);
        $this->assertStringContainsString("route('pos.settings.local-billing.number-style'", $customize);
        $this->assertStringContainsString("__('pos.local_number_display_sub')", $customize);
        $this->assertStringContainsString("&& \$localNumberStyle !== 'daily'", $customize);
        $this->assertStringContainsString('reset-numbering', $customize);
        $this->assertStringContainsString('reset-numbering', $routes);
        $this->assertStringContainsString('local-billing/number-style', $routes);

        $receiptSettings = file_get_contents(base_path('resources/views/pos/receipt-settings.blade.php'));
        $this->assertStringContainsString("['serial', 'token', 'daily']", $receiptSettings);
        $this->assertStringContainsString('value="daily"', $receiptSettings);

        foreach (['en', 'rur', 'ur'] as $locale) {
            $copy = file_get_contents(base_path("lang/{$locale}/pos.php"));
            $this->assertStringContainsString("'local_billing_numbering_never_resets'", $copy);
            $this->assertStringContainsString("'local_number_display_sub'", $copy);
            $this->assertStringContainsString("'spend_records_hint'", $copy);
            $this->assertStringContainsString("'auto_dayclose_6am_sub'", $copy);
        }
    }

    /**
     * Replayed / double-clicked clear (and the second admin in a race): candidates
     * are selected and locked INSIDE the transaction, so the second run finds an
     * empty set — one snapshot set, ONE quota ledger row, no phantom usage.
     * (sqlite has no row locks, so this covers the replay half; the FOR UPDATE
     * lock is what serialises two genuinely concurrent admins on MySQL.)
     */
    public function test_replayed_clear_writes_no_second_ledger_row(): void
    {
        $cid = $this->makeCompany(['is_internal_account' => false, 'invoice_limit_override' => 5]);
        $finalArgs = ['invoice_mode' => 'pra', 'pra_status' => null];
        $this->makeBill($cid, 'L001', $finalArgs + ['customer_id' => 42]);
        $this->makeBill($cid, 'L002', $finalArgs + ['customer_id' => 42]);
        $user = $this->makeUser($cid);

        $this->clear($user)->assertStatus(200)->assertJson(['deleted' => 2]);
        $this->clear($user)->assertStatus(200)->assertJson(['deleted' => 0, 'next_number' => 'L003']);

        $this->assertSame(1, DB::table('pos_local_series_resets')->count(), 'a replay must not add a ledger row');
        $this->assertSame(2, DB::table('pos_customer_spend_snapshots')->count(), 'spend history must not be duplicated');
        $this->assertSame(
            2,
            (int) DB::table('pos_local_series_resets')->where('company_id', $cid)->sum('deleted_final_count'),
            'quota add-back must stay at the real number of deleted finals'
        );
    }

    // ── 7. who may do it ─────────────────────────────────────────────────────

    public function test_cashier_cannot_clear_even_by_hitting_the_url(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001');

        $this->clear($this->makeUser($cid, 'pos_cashier'))->assertStatus(403);

        $this->assertSame(['L001'], $this->numbers($cid));
        $this->assertSame(0, DB::table('pos_local_series_resets')->count());
    }

    public function test_manager_may_clear(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001');

        $this->clear($this->makeUser($cid, 'pos_manager'))->assertStatus(200);

        $this->assertSame([], $this->numbers($cid));
    }

    // ── 9. explicit fresh start on an EMPTY series ──────────────────────────

    private function resetNumbering(User $user)
    {
        return $this->actingAs($user, 'pos')
            ->postJson('/pos/settings/local-billing/reset-numbering');
    }

    public function test_reset_is_offered_only_once_the_series_is_empty(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L014');

        $this->assertFalse($this->seriesStatus($cid)['can_reset']);
        $this->clear($this->makeUser($cid))->assertStatus(200);

        $after = $this->seriesStatus($cid);
        $this->assertTrue($after['can_reset']);
        $this->assertSame('L015', $after['next'], 'Clear itself never resets numbering');
    }

    public function test_a_brand_new_company_is_never_offered_a_pointless_reset(): void
    {
        $this->assertFalse($this->seriesStatus($this->makeCompany())['can_reset']);
    }

    public function test_reset_starts_the_next_local_bill_at_the_first_number(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L014');
        $this->makeBill($cid, 'L015');
        $this->clear($this->makeUser($cid))->assertStatus(200);

        $this->resetNumbering($this->makeUser($cid))
            ->assertStatus(200)
            ->assertJson(['success' => true, 'next_number' => 'L001']);

        $this->assertSame('L001', $this->nextPreview($cid));
        $this->assertSame('L001', $this->nextRetail($cid));
        $this->assertSame('L002', $this->nextRestaurant($cid));
    }

    public function test_reset_refuses_while_a_live_bill_still_holds_a_reference(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L014', ['is_archived' => false]);

        $this->resetNumbering($this->makeUser($cid))->assertStatus(409);
        $this->assertSame('L015', $this->nextPreview($cid));
    }

    public function test_reset_refuses_while_an_archived_bill_still_holds_a_reference(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L014');

        $this->resetNumbering($this->makeUser($cid))->assertStatus(409);
        $this->assertSame('L015', $this->nextPreview($cid));
    }

    public function test_cashier_cannot_reset_numbering_even_by_hitting_the_url(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L014');
        $this->clear($this->makeUser($cid))->assertStatus(200);

        $this->resetNumbering($this->makeUser($cid, 'pos_cashier'))->assertStatus(403);
        $this->assertSame('L015', $this->nextPreview($cid));
    }

    public function test_reset_never_reaches_another_companys_series(): void
    {
        $mine = $this->makeCompany();
        $theirs = $this->makeCompany();
        $this->makeBill($theirs, 'L050', ['is_archived' => false]);
        $this->makeBill($mine, 'L014');
        $this->clear($this->makeUser($mine))->assertStatus(200);

        $this->resetNumbering($this->makeUser($mine))->assertStatus(200);

        $this->assertSame('L001', $this->nextPreview($mine));
        $this->assertSame('L051', $this->nextPreview($theirs));
    }

    // ── 8. one rule behind screen + both printers (Task 1373) ────────────────

    /** Preview and both sale paths share one monotonic company counter. */
    public function test_preview_and_both_sale_paths_share_one_monotonic_series(): void
    {
        $cid = $this->makeCompany();

        $this->assertSame('L001', $this->nextPreview($cid));
        $this->assertSame('L001', $this->nextRetail($cid));
        $this->makeBill($cid, 'L001', ['is_archived' => false]);
        $this->assertSame('L002', $this->nextPreview($cid));
        $this->assertSame('L002', $this->nextRestaurant($cid));
        $this->makeBill($cid, 'L002', ['is_archived' => false]);

        // Legacy + stray formats reserve nothing at all.
        $this->makeBill($cid, 'LOCAL-2026-00003', ['is_archived' => false]);
        $this->makeBill($cid, 'L-ABC', ['is_archived' => false]);
        $this->makeBill($cid, 'L-003-extra', ['is_archived' => false]);
        $this->assertSame('L003', $this->nextPreview($cid));

        // Past the pad width the series just grows (L-1000), still in step.
        DB::table('pos_local_series_counters')->where('company_id', $cid)->update(['last_number' => 999]);
        $this->assertSame('L1000', $this->nextPreview($cid));
        $this->assertSame('L1000', $this->nextRetail($cid));
    }

    /**
     * "…and after the clear?" remains the same monotonic number; records can be
     * deleted, but their references stay permanently consumed.
     */
    public function test_preview_after_clear_is_what_the_sale_path_then_issues(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L001');
        $this->makeBill($cid, 'L002');
        $this->makeBill($cid, 'L003', ['is_archived' => false]); // live, survives

        $promised = $this->seriesStatus($cid)['next_after'];
        $this->assertSame('L004', $promised);

        $this->clear($this->makeUser($cid))->assertStatus(200);

        $this->assertSame($promised, $this->nextRetail($cid));
        $this->assertSame('L005', $this->nextRestaurant($cid));
    }

    /** A rolled-back sale must roll its counter reservation back too. */
    public function test_counter_advance_participates_in_the_sale_transaction(): void
    {
        $cid = $this->makeCompany();

        DB::beginTransaction();
        $this->assertSame('L001', $this->nextRetail($cid));
        $this->assertSame('L002', $this->nextPreview($cid));
        DB::rollBack();

        $this->assertSame('L001', $this->nextPreview($cid));
        $this->assertSame(0, DB::table('pos_local_series_counters')->where('company_id', $cid)->count());
    }

    /**
     * The dash was dropped from the reference (owner, 25 Aug 2026): new bills
     * are minted "L017", but the "L-016" bills a shop already has must still
     * reserve their number — the series may never hand 16 out twice.
     */
    public function test_dashed_references_still_reserve_their_number(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L-016');

        $this->assertSame('L017', $this->nextPreview($cid));
        $this->assertSame('L017', $this->nextRetail($cid));
        $this->assertSame(16, \App\Services\PosLocalSeries::serialOf('L-016'));
        $this->assertSame(16, \App\Services\PosLocalSeries::serialOf('L016'));
    }

    /** The production migration discovers legacy rows without a prefilled counter. */
    public function test_migration_backfills_highest_exact_legacy_reference(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L832');
        $this->makeBill($cid, 'L-1000-extra');
        $this->makeBill($cid, 'LOCAL-2026-09999');

        // Natural pre-migration state: transactions exist, counter table does not.
        Schema::drop('pos_local_series_counters');
        $migration = require database_path('migrations/2026_09_12_120000_create_pos_local_series_counters_table.php');
        $migration->up();

        $this->assertSame(
            832,
            (int) DB::table('pos_local_series_counters')->where('company_id', $cid)->value('last_number')
        );
        $this->assertSame('L833', $this->nextPreview($cid));
        $this->assertSame('L833', $this->nextRetail($cid));
    }

    // ── company isolation ────────────────────────────────────────────────────

    public function test_clear_never_reaches_another_company(): void
    {
        $mine = $this->makeCompany();
        $other = $this->makeCompany(['name' => 'Other Shop']);
        $this->makeBill($mine, 'L001');
        $this->makeBill($other, 'L001');

        $this->clear($this->makeUser($mine))->assertStatus(200)->assertJson(['deleted' => 1]);

        $this->assertSame([], $this->numbers($mine));
        $this->assertSame(['L001'], $this->numbers($other));
    }
}
