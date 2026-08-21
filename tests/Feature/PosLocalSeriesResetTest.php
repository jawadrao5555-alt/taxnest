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
 * Task 1358 — "clear archived local bills so the L-series restarts at L-001".
 *
 * A new local bill takes the SMALLEST FREE L-number, and day-close ARCHIVED
 * bills keep theirs reserved forever. A shop that archived L-001…L-149 before
 * switching to the "delete" policy therefore starts every day at L-150. The
 * Customize POS → Local Billing card now states the reason and offers an
 * owner-confirmed clear.
 *
 * Locked here:
 *  1. STATUS FIGURES: the card counts only bills that really block the series,
 *     with their date range, today's next number and the number after a clear.
 *  2. CLEAR = DAY-CLOSE DELETE RULES: PRA/fiscal bills, unsettled rider CASH
 *     bills, returns, legacy LOCAL-YYYY rows and LIVE (un-archived) bills all
 *     survive; only archived provisionals + reporting-OFF finals go.
 *  3. Items and payments of the deleted bills go with them.
 *  4. SERIES RESTART: after the clear both generators (retail PosController and
 *     RestaurantPosController) hand out L-001, then L-002.
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

    private function callPrivate(object $target, string $method, array $args)
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($target, $args);
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
        $this->makeBill($cid, 'L-001', ['business_date' => '2026-07-15']);
        $this->makeBill($cid, 'L-002', ['business_date' => '2026-08-01']);
        $this->makeBill($cid, 'L-003', ['business_date' => '2026-08-19']);
        // Live bill of the running day — blocks nothing, is never cleared.
        $this->makeBill($cid, 'L-004', ['is_archived' => false]);

        $status = $this->seriesStatus($cid);

        $this->assertSame(3, $status['count']);
        $this->assertSame('2026-07-15', $status['from']);
        $this->assertSame('2026-08-19', $status['to']);
        $this->assertSame('L-005', $status['next']);
        $this->assertSame('L-001', $status['next_after']);
    }

    public function test_status_is_silent_when_nothing_blocks_the_series(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L-001', ['is_archived' => false]);

        $status = $this->seriesStatus($cid);

        $this->assertSame(0, $status['count']);
        $this->assertSame('L-002', $status['next']);
    }

    // ── 2/3/4. the clear itself ──────────────────────────────────────────────

    public function test_clear_removes_archived_local_bills_and_restarts_at_l001(): void
    {
        $cid = $this->makeCompany();
        $prov = $this->makeBill($cid, 'L-001');
        $final = $this->makeBill($cid, 'L-002', ['invoice_mode' => 'pra', 'pra_status' => null]);
        // Everything below must SURVIVE.
        $pra = $this->makeBill($cid, 'L-003', [
            'invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => '1234567890123',
        ]);
        $riderCash = $this->makeBill($cid, 'L-004', [
            'rider_id' => 7, 'payment_method' => 'cash', 'rider_settlement_id' => null, 'delivery_status' => 'dispatched',
        ]);
        $live = $this->makeBill($cid, 'L-005', ['is_archived' => false]);
        $return = $this->makeBill($cid, 'L-006', ['transaction_type' => 'return']);
        $legacy = $this->makeBill($cid, 'LOCAL-2026-00007');

        $res = $this->clear($this->makeUser($cid));

        $res->assertStatus(200)->assertJson(['success' => true, 'deleted' => 2, 'next_number' => 'L-001']);
        $this->assertSame(
            ['L-003', 'L-004', 'L-005', 'L-006', 'LOCAL-2026-00007'],
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

    public function test_after_clear_both_generators_issue_l001_then_l002(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L-001');
        $this->makeBill($cid, 'L-002');
        $this->makeBill($cid, 'L-003');

        $this->clear($this->makeUser($cid))->assertStatus(200);

        // Retail sale screen takes L-001 …
        $this->assertSame('L-001', $this->nextRetail($cid));
        $this->makeBill($cid, 'L-001', ['is_archived' => false]);
        // … and the restaurant dine-in pay path continues the SAME series.
        $this->assertSame('L-002', $this->nextRestaurant($cid));
        $this->makeBill($cid, 'L-002', ['is_archived' => false]);
        $this->assertSame('L-003', $this->nextRetail($cid));
    }

    public function test_day_after_a_delete_policy_close_series_starts_at_one_again(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L-001');
        $this->makeBill($cid, 'L-002');
        $this->clear($this->makeUser($cid))->assertStatus(200);

        // Next trading day under the delete policy: bills are made and removed,
        // so nothing is left holding a number the following morning.
        $b1 = $this->makeBill($cid, 'L-001', ['is_archived' => false]);
        $b2 = $this->makeBill($cid, 'L-002', ['is_archived' => false]);
        DB::table('pos_transactions')->whereIn('id', [$b1, $b2])->delete();

        $this->assertSame('L-001', $this->nextRetail($cid));
    }

    public function test_clear_with_nothing_to_remove_is_a_harmless_noop(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L-001', ['is_archived' => false]);

        $this->clear($this->makeUser($cid))
            ->assertStatus(200)
            ->assertJson(['success' => true, 'deleted' => 0, 'next_number' => 'L-002']);

        $this->assertSame(['L-001'], $this->numbers($cid));
        $this->assertSame(0, DB::table('pos_local_series_resets')->count());
    }

    // ── 5. customer spend record ─────────────────────────────────────────────

    public function test_spend_snapshot_is_written_before_deleting_when_persist_is_on(): void
    {
        $cid = $this->makeCompany(['pos_customer_spend_persist' => true]);
        $this->makeBill($cid, 'L-001', [
            'customer_id' => 42, 'customer_name' => 'Bilal', 'customer_phone' => '03001234567', 'total_amount' => 750,
        ]);
        $this->makeBill($cid, 'L-002', [
            'invoice_mode' => 'pra', 'pra_status' => null,
            'customer_phone' => '03009999999', 'total_amount' => 250,
        ]);
        // Walk-in bill (no customer) leaves no snapshot.
        $this->makeBill($cid, 'L-003');

        $this->clear($this->makeUser($cid))->assertStatus(200);

        $this->assertSame(2, DB::table('pos_customer_spend_snapshots')->count());
        $this->assertDatabaseHas('pos_customer_spend_snapshots', [
            'company_id' => $cid, 'customer_id' => 42, 'invoice_number' => 'L-001',
            'bill_kind' => 'provisional', 'total_amount' => 750,
        ]);
        $this->assertDatabaseHas('pos_customer_spend_snapshots', [
            'invoice_number' => 'L-002', 'bill_kind' => 'final_local', 'total_amount' => 250,
        ]);
    }

    public function test_no_spend_snapshot_when_the_setting_is_off(): void
    {
        $cid = $this->makeCompany(['pos_customer_spend_persist' => false]);
        $this->makeBill($cid, 'L-001', ['customer_id' => 42, 'customer_phone' => '03001234567']);

        $this->clear($this->makeUser($cid))->assertStatus(200);

        $this->assertSame(0, DB::table('pos_customer_spend_snapshots')->count());
    }

    // ── 6. quota honesty ─────────────────────────────────────────────────────

    public function test_clearing_never_buys_back_monthly_bill_quota(): void
    {
        // Real (non-internal) shop on a 2-bills-per-month allowance.
        $cid = $this->makeCompany(['is_internal_account' => false, 'invoice_limit_override' => 2]);
        $finalArgs = ['invoice_mode' => 'pra', 'pra_status' => null];
        $this->makeBill($cid, 'L-001', $finalArgs);
        $this->makeBill($cid, 'L-002', $finalArgs);
        // Last month's archived final — cleared too, but it must NOT be added to
        // THIS month's count.
        $this->makeBill($cid, 'L-003', $finalArgs + [
            'business_date' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'created_at' => now()->subMonthNoOverflow()->startOfMonth(),
        ]);
        // A provisional never consumed quota, so it is never added back either.
        $this->makeBill($cid, 'L-004');

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
        $this->makeBill($cid, 'L-001', [
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
        $this->makeBill($cid, 'L-001');
        $this->makeBill($cid, 'L-ABC');
        $this->makeBill($cid, 'L-001-extra');

        $status = $this->seriesStatus($cid);
        $this->assertSame(1, $status['count'], 'only the real L-001 holds a number');
        $this->assertSame('L-001', $status['next_after']);

        $this->clear($this->makeUser($cid))->assertStatus(200)->assertJson([
            'deleted' => 1,
            'next_number' => 'L-001',
        ]);

        $this->assertSame(['L-001-extra', 'L-ABC'], $this->numbers($cid), 'stray L-* bills must survive');
        // Their items/payments stay too — nothing about them was touched.
        $this->assertSame(2, DB::table('pos_transaction_items')->count());
        $this->assertSame(2, DB::table('pos_payments')->count());
        // And they still do not reserve number 1 for the next sale.
        $this->assertSame('L-001', $this->nextRetail($cid));
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
        $this->makeBill($cid, 'L-001', $finalArgs + ['customer_id' => 42]);
        $this->makeBill($cid, 'L-002', $finalArgs + ['customer_id' => 42]);
        $user = $this->makeUser($cid);

        $this->clear($user)->assertStatus(200)->assertJson(['deleted' => 2]);
        $this->clear($user)->assertStatus(200)->assertJson(['deleted' => 0, 'next_number' => 'L-001']);

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
        $this->makeBill($cid, 'L-001');

        $this->clear($this->makeUser($cid, 'pos_cashier'))->assertStatus(403);

        $this->assertSame(['L-001'], $this->numbers($cid));
        $this->assertSame(0, DB::table('pos_local_series_resets')->count());
    }

    public function test_manager_may_clear(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L-001');

        $this->clear($this->makeUser($cid, 'pos_manager'))->assertStatus(200);

        $this->assertSame([], $this->numbers($cid));
    }

    // ── company isolation ────────────────────────────────────────────────────

    public function test_clear_never_reaches_another_company(): void
    {
        $mine = $this->makeCompany();
        $other = $this->makeCompany(['name' => 'Other Shop']);
        $this->makeBill($mine, 'L-001');
        $this->makeBill($other, 'L-001');

        $this->clear($this->makeUser($mine))->assertStatus(200)->assertJson(['deleted' => 1]);

        $this->assertSame([], $this->numbers($mine));
        $this->assertSame(['L-001'], $this->numbers($other));
    }
}
