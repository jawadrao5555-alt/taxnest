<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PosBusinessDay;
use App\Services\PosFeatureService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 204 — offline bills replay dedupe / poison-entry / business-day lock.
 *
 * PRA POS offline mode queues bills in IndexedDB and replays them to
 * POST /pos/invoice/store. Three server-side guarantees are locked here:
 *
 * 1. DEDUPE — the same offline_uuid submitted twice creates exactly ONE bill;
 *    the second request returns the FIRST bill's own result (replayed:true,
 *    same transaction_id / invoice_number / total) and appends NO extra
 *    items/payments. The guard is company-scoped (two shops may share a uuid)
 *    and sits BEFORE the monthly quota — a lost-response replay must never
 *    re-charge quota or be rejected by it.
 *
 * 2. POISON ISOLATION — one malformed queued entry must never wedge the rest
 *    of the queue. The client drain (universal.blade.php syncOfflineBills)
 *    stops only on 401/419 (login) and 403 (quota); any other error records
 *    tries/last_error on that entry and the NEXT entry still posts. So the
 *    server contract is: a malformed entry fails cleanly (422 validation /
 *    500 rolled-back) with ZERO rows persisted — especially not a row carrying
 *    the poison uuid, which would make a later FIXED resubmit phantom-match
 *    the replay guard — and the following entries process normally.
 *
 * 3. BUSINESS DAY — a late replay landing 00:00–05:59 next morning follows
 *    the owner's business-day rule exactly like a live sale: previous day
 *    still open → bill books into YESTERDAY's business_date; previous day
 *    already day-closed → today. created_at meanwhile keeps the ORIGINAL
 *    queued sale moment (offline_queued_at, clamped) — PRA/tax reporting
 *    stays on real timestamps while shop reports group by trading day.
 *
 * Out of scope (per task): client-side queue/service-worker redesign, new
 * sync mechanisms, the known tries-cap gap (a 422 entry retries forever on
 * the client — server just has to stay consistent, which is what we lock).
 *
 * Pattern: sqlite :memory: + minimal Schema::create, full HTTP through the
 * real route stack (pos.auth → company.approval → plan.limit:invoices →
 * storeInvoice) — same approach as OfflineReplayAfterDowngradeTest.
 */
class OfflineReplayDedupePoisonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // planAllows caches per company id statically — ids restart at 1 after
        // dropAllTables, so a stale cache would leak between tests.
        PosFeatureService::flushGateCaches();

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
            // NULL → PosBusinessDay falls back to the 06:00 default cutoff.
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
            $t->string('business_date')->nullable(); // stored as date string; NEVER whereDate()d
            $t->timestamps();
            $t->unique(['company_id', 'invoice_number']);
            // Production safety net for the concurrent two-tab drain race.
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

        // Branch context resolution (head-office lookup) runs on every POS
        // request — empty table is fine, missing table is not.
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
            ['payment_method' => 'debit_card', 'tax_rate' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Needed by BOTH PosBusinessDay::forMoment (closed-day check) and the
        // quota's deleted-finals add-back. Missing table would silently push
        // business_date onto the calendar-date fallback and mask regressions.
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
        Carbon::setTestNow(); // never leak frozen time into other test classes
        parent::tearDown();
    }

    /** Company on a given plan + its logged-in POS admin. */
    private function makeShop(array $planAttrs = [], string $slug = 'shop'): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Offline Shop ' . $slug,
            'product_type' => 'pos',
            'status' => 'active',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // The cutoff cache is static per-process; ids restart at 1 every test,
        // so an earlier test class could have cached a CUSTOM cutoff for the
        // same id. Forget it — these tests rely on the 06:00 default.
        PosBusinessDay::forgetCutoff($companyId);

        $planId = DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Business',
            'product_type' => 'pos',
            'offline_enabled' => true,
            'is_trial' => false,
            'invoice_limit' => -1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $planAttrs));

        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Shop Admin ' . $slug,
            'email' => "admin-{$slug}@offlineshop.pk",
            'password' => bcrypt('secret-123'),
            'company_id' => $companyId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [Company::findOrFail($companyId), User::findOrFail($userId)];
    }

    /** The exact payload syncOfflineBills replays from the IndexedDB queue. */
    private function queuedBillPayload(string $uuid, array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. DEDUPE — same offline_uuid twice = ONE bill, same result back
    // ─────────────────────────────────────────────────────────────────────

    public function test_same_uuid_twice_creates_one_bill_and_second_returns_first_result(): void
    {
        // Freeze mid-month so the monthly quota window can never straddle a
        // month boundary regardless of when the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));
        [$company, $user] = $this->makeShop();

        $uuid = 'replay-dup-0001';
        $first = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));

        // 2 × Rs150 = 300 + 16% cash tax (48) = 348 (whole-rupee convention).
        $first->assertOk()->assertJson([
            'success' => true,
            'invoice_number' => 'L-001',
            'total_amount' => 348,
        ]);
        $txId = $first->json('transaction_id');

        // Lost-response retry: the sync engine replays the IDENTICAL payload.
        $second = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));

        // The second request must return the FIRST bill's own result — same
        // id, same serial, same total — flagged replayed so the client can
        // delete the queue entry as "already synced".
        $second->assertOk()->assertJson([
            'success' => true,
            'replayed' => true,
            'transaction_id' => $txId,
            'invoice_number' => 'L-001',
            'total_amount' => 348,
        ]);

        // Exactly ONE bill — and the replay appended NOTHING to it: item and
        // payment rows would double a shop's line/settlement reports even if
        // the header deduped.
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $company->id)->count());
        $this->assertSame(1, DB::table('pos_transaction_items')->where('transaction_id', $txId)->count());
        $this->assertSame(1, DB::table('pos_payments')->where('transaction_id', $txId)->count());
    }

    public function test_replay_dedupes_before_quota_and_never_recharges_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));
        // Plan with a 1-bill monthly quota: the ordering proof. If the replay
        // guard sat AFTER PlanLimitService::canCreatePosBill, the retry below
        // would 403 (count 1 >= limit 1) and the client queue would wedge on a
        // bill that ALREADY EXISTS server-side — the exact disaster this task
        // locks out.
        [$company, $user] = $this->makeShop(['invoice_limit' => 1]);

        $uuid = 'replay-quota-0001';
        $first = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));
        $first->assertOk()->assertJson(['success' => true]);

        // Quota is now fully consumed (1/1) — yet the replay must still succeed.
        $retry = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));
        $retry->assertOk()->assertJson(['success' => true, 'replayed' => true]);

        // …while a genuinely NEW bill is correctly rejected by the same quota,
        // proving the deduped replay was counted exactly once.
        $fresh = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload('replay-quota-0002'));
        $fresh->assertStatus(403)->assertJson(['success' => false]);
        // Quota error is now localized to the shop's language (task 496) —
        // assert on the counts, which survive translation.
        $this->assertStringContainsString('(1/1', (string) $fresh->json('error'));

        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $company->id)->count());
    }

    public function test_dedupe_is_company_scoped_same_uuid_in_two_shops_creates_two_bills(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));
        // uuids are client-generated — two different shops CAN collide on the
        // same string. The guard (and the unique index) are (company_id, uuid)
        // scoped: shop B must get its own bill, never shop A's.
        [$shopA, $userA] = $this->makeShop([], 'aaa');
        [$shopB, $userB] = $this->makeShop([], 'bbb');

        $uuid = 'shared-uuid-across-shops';
        $a = $this->actingAs($userA, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));
        $a->assertOk()->assertJson(['success' => true]);

        $b = $this->actingAs($userB, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));
        $b->assertOk()->assertJson(['success' => true]);
        $b->assertJsonMissingPath('replayed'); // a real creation, not a phantom match

        $this->assertNotSame($a->json('transaction_id'), $b->json('transaction_id'));
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $shopA->id)->where('offline_uuid', $uuid)->count());
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $shopB->id)->where('offline_uuid', $uuid)->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. POISON ISOLATION — one bad entry never wedges the queue
    // ─────────────────────────────────────────────────────────────────────

    public function test_malformed_entries_reject_cleanly_and_entries_after_them_still_process(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));
        [$company, $user] = $this->makeShop();

        // Queue drain order: [good, poison ×6, good] — mirrors a real drain
        // where one corrupted IndexedDB record sits mid-queue.
        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload('good-entry-1'))
            ->assertOk()->assertJson(['success' => true, 'invoice_number' => 'L-001']);

        $poisons = [
            // [uuid, payload overrides, expected invalid field]
            ['poison-empty-items', ['items' => []], 'items'],
            ['poison-zero-qty', ['items' => [['type' => 'product', 'item_id' => null, '_manual' => true, 'name' => 'Chai', 'quantity' => 0, 'unit_price' => 150]]], 'items.0.quantity'],
            ['poison-garbage-qty', ['items' => [['type' => 'product', 'item_id' => null, '_manual' => true, 'name' => 'Chai', 'quantity' => 'abc', 'unit_price' => 150]]], 'items.0.quantity'],
            ['poison-absurd-price', ['items' => [['type' => 'product', 'item_id' => null, '_manual' => true, 'name' => 'Chai', 'quantity' => 1, 'unit_price' => 99999999999]]], 'items.0.unit_price'],
            ['poison-bad-method', ['payment_method' => 'hundi'], 'payment_method'],
            ['poison-bad-queued-at', ['offline_queued_at' => 'not-a-date'], 'offline_queued_at'],
        ];
        foreach ($poisons as [$uuid, $overrides, $field]) {
            $this->actingAs($user, 'pos')
                ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid, $overrides))
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        // A corrupted/oversized uuid itself must also 422 (max:64), not crash
        // the dedupe lookup.
        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload(str_repeat('x', 65)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('offline_uuid');

        // Not a single poison entry left a row behind…
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('pos_transactions')->where('offline_uuid', 'like', 'poison-%')->count());

        // …and the entry AFTER the poison batch processes normally. L-002
        // also proves the rejected entries burned no serials.
        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload('good-entry-2'))
            ->assertOk()->assertJson(['success' => true, 'invoice_number' => 'L-002']);
        $this->assertSame(2, DB::table('pos_transactions')->where('company_id', $company->id)->count());
    }

    public function test_mid_transaction_failure_rolls_back_fully_and_never_burns_the_uuid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));
        [$company, $user] = $this->makeShop();

        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload('ok-before-poison'))
            ->assertOk();

        // Simulate a DB-level failure mid-bill (what a poison entry hitting a
        // constraint looks like): the line-item insert aborts AFTER the header
        // row was already created inside the controller's transaction.
        DB::unprepared("
            CREATE TRIGGER poison_line_guard BEFORE INSERT ON pos_transaction_items
            WHEN NEW.item_name = 'POISON-EXPLODE'
            BEGIN
                SELECT RAISE(ABORT, 'simulated malformed line');
            END
        ");

        $poisonPayload = $this->queuedBillPayload('poison-tx-uuid', [
            'items' => [
                ['type' => 'product', 'item_id' => null, '_manual' => true, 'name' => 'POISON-EXPLODE', 'quantity' => 1, 'unit_price' => 150],
                ['type' => 'product', 'item_id' => null, '_manual' => true, 'name' => 'Chai', 'quantity' => 1, 'unit_price' => 150],
            ],
        ]);

        $poison = $this->actingAs($user, 'pos')->postJson('/pos/invoice/store', $poisonPayload);
        $poison->assertStatus(500)->assertJson(['success' => false]);

        // FULL rollback: no header row (so no half-bill in reports), no orphan
        // items/payments, and crucially NO row holding the poison uuid.
        $this->assertSame(1, DB::table('pos_transactions')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('pos_transactions')->where('offline_uuid', 'poison-tx-uuid')->count());
        $this->assertSame(1, DB::table('pos_transaction_items')->count());
        $this->assertSame(1, DB::table('pos_payments')->count());

        // The queue is NOT wedged: the next entry lands normally.
        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload('ok-after-poison'))
            ->assertOk()->assertJson(['success' => true, 'invoice_number' => 'L-002']);

        // The client will keep retrying the poison entry (known gap: no tries
        // cap) — every retry must fail the SAME clean way, never half-commit.
        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $poisonPayload)
            ->assertStatus(500)->assertJson(['success' => false]);
        $this->assertSame(2, DB::table('pos_transactions')->where('company_id', $company->id)->count());

        // And because failures persisted NOTHING, a FIXED resubmit under the
        // same uuid creates a real bill — not a phantom replayed:true match
        // against a corpse row that would silently swallow the sale.
        $fixed = $this->actingAs($user, 'pos')->postJson('/pos/invoice/store', $this->queuedBillPayload('poison-tx-uuid', [
            'items' => [['type' => 'product', 'item_id' => null, '_manual' => true, 'name' => 'Chai Fixed', 'quantity' => 1, 'unit_price' => 150]],
        ]));
        $fixed->assertOk()->assertJson(['success' => true]);
        $fixed->assertJsonMissingPath('replayed');
        $this->assertSame(1, DB::table('pos_transactions')->where('offline_uuid', 'poison-tx-uuid')->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. BUSINESS DAY — late replay 00:00–05:59 keeps the trading-day rule
    // ─────────────────────────────────────────────────────────────────────

    public function test_pre_cutoff_late_replay_books_into_yesterdays_open_business_day(): void
    {
        // Bill rung up 23:30 on Aug 1 while offline; device comes online and
        // the queue drains at 02:30 next morning — inside the 00:00–05:59
        // window, Aug 1 not yet day-closed.
        Carbon::setTestNow(Carbon::parse('2026-08-02 02:30:00'));
        [$company, $user] = $this->makeShop();

        $response = $this->actingAs($user, 'pos')->postJson('/pos/invoice/store', $this->queuedBillPayload('late-replay-open', [
            'offline_queued_at' => '2026-08-01T23:30:00+05:00',
        ]));
        $response->assertOk()->assertJson(['success' => true]);

        $row = DB::table('pos_transactions')->where('offline_uuid', 'late-replay-open')->first();
        // Trading day = YESTERDAY (open pre-cutoff window), exactly like a
        // live 02:30 sale — day-close/Z-report/dashboard group it into Aug 1.
        $this->assertSame('2026-08-01', $row->business_date);
        // PRA/tax side keeps the ORIGINAL sale moment, not the sync moment.
        $this->assertSame('2026-08-01 23:30:00', $row->created_at);

        // A replay of the same entry minutes later dedupes and leaves the
        // stamped business day untouched.
        Carbon::setTestNow(Carbon::parse('2026-08-02 02:35:00'));
        $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload('late-replay-open', [
                'offline_queued_at' => '2026-08-01T23:30:00+05:00',
            ]))
            ->assertOk()->assertJson(['success' => true, 'replayed' => true]);
        $this->assertSame(1, DB::table('pos_transactions')->where('offline_uuid', 'late-replay-open')->count());
        $this->assertSame('2026-08-01', DB::table('pos_transactions')->where('offline_uuid', 'late-replay-open')->value('business_date'));
    }

    public function test_pre_cutoff_late_replay_after_day_close_books_into_today(): void
    {
        // Same 02:30 replay — but Aug 1 was ALREADY day-closed. A closed day
        // never reopens (its Z-report is final), so the bill books into the
        // new trading day while created_at still records the real sale moment.
        Carbon::setTestNow(Carbon::parse('2026-08-02 02:30:00'));
        [$company, $user] = $this->makeShop();

        DB::table('pos_day_close_reports')->insert([
            'company_id' => $company->id,
            'report_date' => '2026-08-01',
            'report_number' => 'ZR-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user, 'pos')->postJson('/pos/invoice/store', $this->queuedBillPayload('late-replay-closed', [
            'offline_queued_at' => '2026-08-01T23:30:00+05:00',
        ]));
        $response->assertOk()->assertJson(['success' => true]);

        $row = DB::table('pos_transactions')->where('offline_uuid', 'late-replay-closed')->first();
        $this->assertSame('2026-08-02', $row->business_date);
        $this->assertSame('2026-08-01 23:30:00', $row->created_at);
    }
}
