<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\PosBusinessDay;
use App\Services\PosFeatureService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * NESTPOS OFFLINE UUID RACE — the loser of a concurrent duplicate replay gets
 * the winner's success payload, not a 500 (audit LOW finding; mirror of the
 * FbrPosController::store race-loser recovery).
 *
 * The pre-insert replay guard in storeInvoice is read-then-act: two drains of
 * the same queue entry (two tabs, a retry racing a slow first attempt) both
 * find no row and both INSERT. The unique(company_id, offline_uuid) index
 * stops the duplicate — but the loser used to bubble up as a raw
 * QueryException / HTTP 500 that the sync engine could not tell from a real
 * failure, so the entry stayed queued and the cashier saw an error for a bill
 * that had in fact been saved.
 *
 * Simulating the race: a PosTransaction::creating listener (registered by the
 * test only) plants the "winner" row AFTER the replay guard has run and
 * IMMEDIATELY BEFORE the controller's own INSERT, so the pre-check misses and
 * the insert collides. Because sqlite :memory: allows a single connection,
 * the listener commits the controller's open transaction first and re-opens
 * one, so the planted row survives the controller's rollback exactly like a
 * row committed by another PHP worker would.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, full HTTP through the
 * real route stack (same approach and schema as OfflineReplayDedupePoisonTest).
 */
class PosOfflineUuidRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PosFeatureService::flushGateCaches();
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->text('feature_flags')->nullable();
            $t->string('business_category')->nullable();
            $t->decimal('cashier_discount_limit', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $t->string('pos_business_day_cutoff', 5)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('pra_reporting_enabled')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('offline_enabled')->default(true);
            $t->boolean('deals_enabled')->default(false);
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
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('delivery_address')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('exempt_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->decimal('cash_received', 12, 2)->nullable();
            $t->decimal('change_due', 12, 2)->nullable();
            $t->string('status');
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('submission_hash')->nullable();
            $t->string('offline_uuid', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->string('business_date')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'invoice_number']);
            // The production safety net whose failure mode is under test.
            $t->unique(['company_id', 'offline_uuid'], 'pos_txn_offline_uuid_unique');
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_type')->default('product');
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->text('special_notes')->nullable();
            $t->text('deal_snapshot')->nullable();
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->boolean('is_tax_exempt')->default(false);
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method');
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('reference_number')->nullable();
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

        Schema::create('pos_tax_rules', function (Blueprint $t) {
            $t->id();
            $t->string('payment_method');
            $t->decimal('tax_rate', 8, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('pos_tax_rules')->insert([
            ['payment_method' => 'cash', 'tax_rate' => 16, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number')->nullable();
            $t->integer('deleted_final_count')->default(0);
            $t->integer('deleted_provisional_count')->default(0);
            $t->integer('total_invoices')->default(0);
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->text('notes')->nullable();
            $t->string('hash')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeShop(): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Race Shop',
            'product_type' => 'pos',
            'status' => 'active',
            'company_status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        PosBusinessDay::forgetCutoff($companyId);

        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business',
            'product_type' => 'pos',
            'offline_enabled' => true,
            'is_trial' => false,
            'invoice_limit' => -1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Shop Admin',
            'email' => 'admin@raceshop.pk',
            'password' => bcrypt('secret-123'),
            'company_id' => $companyId,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [Company::findOrFail($companyId), User::findOrFail($userId)];
    }

    private function queuedBillPayload(string $uuid): array
    {
        return [
            'items' => [[
                'type' => 'product',
                'item_id' => null,
                '_manual' => true,
                'name' => 'Chai',
                'quantity' => 2,
                'unit_price' => 150,
                'is_tax_exempt' => false,
            ]],
            'payment_method' => 'cash',
            'discount_type' => 'amount',
            'discount_value' => 0,
            'offline_uuid' => $uuid,
            'offline_queued_at' => now()->subHours(5)->toIso8601String(),
        ];
    }

    /**
     * Plant a committed "winner" row for ($companyId, $uuid) at the last
     * possible moment — after storeInvoice's replay guard, right before its own
     * INSERT — the way a concurrent worker would. Fires once.
     */
    private function plantConcurrentWinner(int $companyId, string $uuid, array $overrides = []): void
    {
        $fired = false;
        PosTransaction::creating(function () use (&$fired, $companyId, $uuid, $overrides) {
            if ($fired) {
                return;
            }
            $fired = true;

            // Single sqlite connection: the other worker's COMMIT has to happen
            // outside the controller's open transaction, or the rollback that
            // follows the collision would erase the winner too.
            $level = DB::transactionLevel();
            if ($level > 0) {
                DB::commit();
            }
            DB::table('pos_transactions')->insert(array_merge([
                'company_id' => $companyId,
                'invoice_number' => 'L-WINNER',
                'invoice_mode' => 'local',
                'status' => 'completed',
                'subtotal' => 300,
                'tax_amount' => 48,
                'total_amount' => 348,
                'payment_method' => 'cash',
                'offline_uuid' => $uuid,
                'business_date' => '2026-08-15',
                'created_at' => now(), 'updated_at' => now(),
            ], $overrides));
            if ($level > 0) {
                DB::beginTransaction();
            }
        });
    }

    // ── 1. the race loser gets the winner's replay payload ───────────────────

    public function test_race_loser_on_the_offline_uuid_index_returns_the_winners_replay_payload(): void
    {
        [$company, $user] = $this->makeShop();
        $uuid = 'race-uuid-0001';
        $this->plantConcurrentWinner($company->id, $uuid);

        $response = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));

        $winner = DB::table('pos_transactions')->where('company_id', $company->id)->where('offline_uuid', $uuid)->first();
        $this->assertNotNull($winner, 'test precondition: the concurrent winner row must have been planted and survive');

        $response->assertOk()->assertJson([
            'success' => true,
            'replayed' => true,
            'transaction_id' => $winner->id,
            'invoice_number' => 'L-WINNER',
            'total_amount' => 348,
        ]);

        // Exactly ONE bill for this uuid — the loser's insert never landed and
        // nothing of it (lines, payments) leaked past the rollback.
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $company->id)->where('offline_uuid', $uuid)->count());
        $this->assertSame(0, DB::table('pos_transaction_items')->count(), 'the rolled-back loser must leave no line rows');
        $this->assertSame(0, DB::table('pos_payments')->count());
    }

    public function test_pre_check_replay_and_race_loser_replay_have_the_same_shape(): void
    {
        [$company, $user] = $this->makeShop();
        $uuid = 'race-uuid-0002';
        $this->plantConcurrentWinner($company->id, $uuid);

        $raceLoser = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));
        $raceLoser->assertOk();

        // Now the row exists up front: the ordinary lost-response replay path.
        $preCheck = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));
        $preCheck->assertOk();

        $this->assertSame($preCheck->json(), $raceLoser->json(),
            'the client queue drain must not be able to tell the two recovery paths apart');
        $this->assertSame(['success', 'replayed', 'transaction_id', 'invoice_number', 'total_amount', 'pra_invoice_number', 'pra_status', 'message'],
            array_keys($raceLoser->json()));
    }

    // ── 2. other integrity errors are NOT swallowed ─────────────────────────

    public function test_an_unrelated_unique_violation_is_still_an_error_not_a_replay(): void
    {
        [$company, $user] = $this->makeShop();

        // A planted row with the SAME serial the controller is about to mint
        // but a DIFFERENT uuid: the collision is on (company_id, invoice_number),
        // which is a real problem and must surface as a failure.
        $this->plantConcurrentWinner($company->id, 'some-other-uuid', ['invoice_number' => 'L001']);

        $response = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload('race-uuid-0003'));

        $response->assertStatus(500)->assertJson(['success' => false]);
        $this->assertSame(0, DB::table('pos_transactions')->where('offline_uuid', 'race-uuid-0003')->count(),
            'no bill may be persisted under the loser\'s uuid');
    }

    // ── 3. the collision detector itself ─────────────────────────────────────

    private function queryException(string $driverMessage, $code = '23000'): QueryException
    {
        return new QueryException('sqlite', 'insert into "pos_transactions" ...', [], new \PDOException($driverMessage, (int) $code));
    }

    public function test_collision_detector_recognises_mysql_and_sqlite_messages_only(): void
    {
        $this->assertTrue(PosController::isOfflineUuidCollision($this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '7-abc' for key 'pos_transactions.pos_txn_offline_uuid_unique'"
        )), 'MySQL 8 names the index');
        $this->assertTrue(PosController::isOfflineUuidCollision($this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '7-abc' for key 'pos_txn_offline_uuid_unique'"
        )), 'MySQL 5.7 names the index without the table');
        $this->assertTrue(PosController::isOfflineUuidCollision($this->queryException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: pos_transactions.company_id, pos_transactions.offline_uuid'
        )), 'SQLite lists the columns');

        $this->assertFalse(PosController::isOfflineUuidCollision($this->queryException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: pos_transactions.company_id, pos_transactions.invoice_number'
        )), 'a serial collision is a different problem');
        $this->assertFalse(PosController::isOfflineUuidCollision($this->queryException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'fbr_pos_transactions.fbr_txn_offline_uuid_unique'"
        )), 'the FBR index is not ours');
        $this->assertFalse(PosController::isOfflineUuidCollision($this->queryException(
            'SQLSTATE[42S22]: Column not found: 1054 Unknown column pos_txn_offline_uuid_unique', '42S22'
        )), 'only SQLSTATE 23000 integrity violations qualify');
    }
}
