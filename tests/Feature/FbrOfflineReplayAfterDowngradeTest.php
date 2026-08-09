<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 389 — FBR twin of OfflineReplayAfterDowngradeTest.
 *
 * Locks the same rule for the FBR POS path: offline REPLAY is NEVER
 * plan-gated, only NEW queueing/pairing is. An FBR shop that queued bills
 * while on a plan with offline_enabled=1 and was then downgraded to
 * Starter (offline_enabled=0) must STILL be able to sync those bills:
 * the offline queue replays them to POST /fbr-pos/store with an
 * offline_uuid, and FbrPosController::store deliberately carries NO
 * offline_enabled rejection on the save path (the fbrPlanAllows check
 * inside store() only gates the backdating/attribution extras —
 * offline_queued_at/by/branch — never the save or the dedupe).
 *
 * These are full HTTP tests through the real route + middleware stack
 * (fbrpos.auth → company.approval → plan.limit:invoices → store), so they
 * fail if ANYONE adds an fbrPlanAllows('offline_enabled') rejection
 * anywhere on that path — not just inside the controller method.
 *
 * Also locked, at the route level (FbrPosPlanGatingTest covers the
 * middleware unit only):
 *   - offline_uuid dedupe: a second replay of the same queued bill
 *     returns the SAME bill with replayed=true, no duplicate row;
 *   - REPLAY GUARD BEFORE QUOTA: that dedupe still answers 200 even when
 *     the monthly quota is full — while a NEW uuid at quota-full is 403
 *     (new bills stay quota-gated; already-saved bills never are).
 *
 * Pattern: sqlite :memory: + minimal Schema::create (same approach as
 * OfflineReplayAfterDowngradeTest / FbrPosStoreReplayGuardTest). Columns
 * guarded by Schema::hasColumn in production code (token_no, order_code,
 * is_third_schedule, …) are deliberately omitted where irrelevant.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrOfflineReplayAfterDowngradeTest.php --testdox
 */
class FbrOfflineReplayAfterDowngradeTest extends TestCase
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
            $t->string('product_type')->default('fbrpos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('fbr_pos_enabled')->default(true);
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->boolean('agent_enabled')->default(false);
            $t->string('fbr_connection_mode')->nullable();
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->text('feature_flags')->nullable();
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
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('offline_enabled')->default(true);
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

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->unsignedBigInteger('shift_id')->nullable();
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->string('transaction_type')->nullable();
            $t->string('status')->nullable();
            $t->string('fbr_status')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->string('fbr_response_code')->nullable();
            $t->text('fbr_response')->nullable();
            $t->string('fbr_submission_hash')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('customer_ntn')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->nullable();
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('fbr_service_charge', 12, 2)->nullable();
            $t->decimal('loyalty_points_earned', 12, 2)->nullable();
            $t->decimal('loyalty_points_redeemed', 12, 2)->nullable();
            $t->decimal('loyalty_redemption_amount', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->text('payment_breakdown')->nullable();
            $t->decimal('cash_received', 12, 2)->nullable();
            $t->decimal('change_due', 12, 2)->nullable();
            $t->unsignedBigInteger('promotion_id')->nullable();
            $t->string('promotion_code')->nullable();
            $t->unsignedBigInteger('parent_transaction_id')->nullable();
            $t->string('order_type')->nullable();
            $t->string('delivery_address')->nullable();
            $t->string('offline_uuid', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'offline_uuid'], 'fbr_txn_offline_uuid_unique');
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('item_name');
            $t->string('hs_code')->nullable();
            $t->string('uom')->nullable();
            $t->decimal('quantity', 12, 4)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('cost_price', 12, 4)->nullable();
            $t->decimal('discount', 12, 2)->nullable();
            $t->decimal('item_discount', 12, 2)->nullable();
            $t->boolean('is_tax_exempt')->default(false);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->decimal('subtotal', 12, 2)->nullable();
            $t->decimal('total', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->boolean('is_enabled')->default(false);
            $t->decimal('rs_per_point', 8, 2)->default(100);
            $t->decimal('point_value', 8, 2)->default(1);
            $t->integer('min_redeem_points')->default(50);
            $t->integer('points_expiry_days')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->string('status')->default('open');
            $t->decimal('sales_count', 12, 2)->default(0);
            $t->decimal('total_sales', 12, 2)->default(0);
            $t->decimal('total_cash', 12, 2)->default(0);
            $t->decimal('total_card', 12, 2)->default(0);
            $t->decimal('total_other', 12, 2)->default(0);
            $t->timestamps();
        });

        // Branch context resolution runs on every FBR POS request — empty
        // table is fine, missing table is not.
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    /** FBR company on a given plan + its logged-in fbrpos admin. */
    private function makeShop(array $planAttrs): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'FBR Paired Shop',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'company_status' => 'active',
            'fbr_pos_enabled' => true,
            'fbr_reporting_enabled' => false, // reporting-OFF final path: no FBR API call
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planId = DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Business',
            'product_type' => 'fbrpos',
            'offline_enabled' => true,
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
            'name' => 'FBR Shop Admin',
            'email' => 'admin@fbrpairedshop.pk',
            'password' => bcrypt('secret-123'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [Company::findOrFail($companyId), User::findOrFail($userId)];
    }

    /** Downgrade the shop's active subscription to Starter (offline gate OFF). */
    private function downgradeToStarter(Company $company, int $invoiceLimit = -1): void
    {
        $starterId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Starter',
            'product_type' => 'fbrpos',
            'offline_enabled' => false,
            'invoice_limit' => $invoiceLimit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->where('company_id', $company->id)
            ->update(['pricing_plan_id' => $starterId, 'updated_at' => now()]);
        // Plan flip must be visible immediately — mirrors a fresh request after
        // the downgrade (the static gate cache is per-request in production).
        PosFeatureService::flushGateCaches();
    }

    /** The exact payload the FBR offline queue replays after reconnect. */
    private function queuedBillPayload(string $uuid): array
    {
        return [
            'items' => [[
                'item_name' => 'Rooh Afza',
                'quantity' => 2,
                'unit_price' => 150,
                'uom' => 'U',
                'tax_rate' => 0,
                'is_tax_exempt' => true,
                'item_discount' => 0,
            ]],
            'payment_method' => 'cash',
            'cash_received' => 300,
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'tax_inclusive' => false,
            'offline_uuid' => $uuid,
            'offline_queued_at' => now()->subHours(5)->toIso8601String(),
        ];
    }

    /** A pre-existing bill this month (fills the monthly quota counter). */
    private function addCountedBill(Company $company): void
    {
        DB::table('fbr_pos_transactions')->insert([
            'company_id' => $company->id,
            'invoice_number' => 'FPOS-PRE-' . uniqid(),
            'invoice_mode' => 'fbr',
            'transaction_type' => 'sale',
            'status' => 'completed',
            'total_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_queued_offline_bill_still_syncs_after_downgrade_to_starter(): void
    {
        [$company, $user] = $this->makeShop(['name' => 'Business', 'offline_enabled' => true]);

        // Shop was paired while on Business; bills queued offline; then downgraded.
        $this->downgradeToStarter($company);

        // Sanity: the plan gate itself really is CLOSED for this shop now —
        // proving the accept below is the deliberate no-gate-on-replay rule,
        // not a mis-built fixture.
        $this->assertFalse(PosFeatureService::planAllows($company->fresh(), 'offline_enabled'));

        $uuid = 'fbr-off-e2e-0001';
        $response = $this->actingAs($user, 'fbrpos')
            ->postJson('/fbr-pos/store', $this->queuedBillPayload($uuid));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('fbr_pos_transactions', [
            'company_id' => $company->id,
            'offline_uuid' => $uuid,
            'status' => 'completed',
        ]);
    }

    public function test_replay_of_same_uuid_after_downgrade_dedupes_instead_of_duplicating(): void
    {
        [$company, $user] = $this->makeShop(['name' => 'Business', 'offline_enabled' => true]);
        $this->downgradeToStarter($company);

        $uuid = 'fbr-off-e2e-0002';
        $first = $this->actingAs($user, 'fbrpos')
            ->postJson('/fbr-pos/store', $this->queuedBillPayload($uuid));
        $first->assertOk()->assertJson(['success' => true]);
        $txId = $first->json('transaction_id');

        // Lost-response retry: sync engine replays the identical queued bill.
        $second = $this->actingAs($user, 'fbrpos')
            ->postJson('/fbr-pos/store', $this->queuedBillPayload($uuid));

        $second->assertOk()->assertJson([
            'success' => true,
            'replayed' => true,
            'transaction_id' => $txId,
        ]);
        $this->assertSame(1, DB::table('fbr_pos_transactions')
            ->where('company_id', $company->id)
            ->where('offline_uuid', $uuid)
            ->count());
    }

    public function test_replay_dedupes_through_full_stack_even_at_quota_full(): void
    {
        // Downgraded Starter with a 2-bill monthly quota. The queued bill's
        // FIRST sync lands as bill #2 (quota now full). A lost-response retry
        // of the SAME uuid must pass plan.limit:invoices (replay-guard-before-
        // quota) and reach the controller's dedupe — 200 replayed, never 403.
        [$company, $user] = $this->makeShop(['name' => 'Business', 'offline_enabled' => true]);
        $this->downgradeToStarter($company, invoiceLimit: 2);
        $this->addCountedBill($company);

        $uuid = 'fbr-off-e2e-0003';
        $first = $this->actingAs($user, 'fbrpos')
            ->postJson('/fbr-pos/store', $this->queuedBillPayload($uuid));
        $first->assertOk()->assertJson(['success' => true]);
        $txId = $first->json('transaction_id');

        // Quota is now full — a NEW uuid is correctly blocked (new bills stay
        // quota-gated; only REPLAYS of saved bills bypass)…
        $blocked = $this->actingAs($user, 'fbrpos')
            ->postJson('/fbr-pos/store', $this->queuedBillPayload('fbr-off-e2e-0003-new'));
        $blocked->assertStatus(403);

        // …but the retry of the already-saved bill still answers with the
        // saved bill, through the real route + middleware stack.
        $retry = $this->actingAs($user, 'fbrpos')
            ->postJson('/fbr-pos/store', $this->queuedBillPayload($uuid));

        $retry->assertOk()->assertJson([
            'success' => true,
            'replayed' => true,
            'transaction_id' => $txId,
        ]);
        $this->assertSame(1, DB::table('fbr_pos_transactions')
            ->where('company_id', $company->id)
            ->where('offline_uuid', $uuid)
            ->count());
    }

    public function test_starter_shop_from_day_one_can_also_land_offline_uuid_bills(): void
    {
        // Paired-then-grandfathered edge: even a shop that only ever held
        // Starter must never have its replays rejected by a plan gate —
        // pairing/queueing is where Starter is blocked, not the sync.
        [$company, $user] = $this->makeShop(['name' => 'Starter', 'offline_enabled' => false]);

        $this->assertFalse(PosFeatureService::planAllows($company, 'offline_enabled'));

        $response = $this->actingAs($user, 'fbrpos')
            ->postJson('/fbr-pos/store', $this->queuedBillPayload('fbr-off-e2e-0004'));

        $response->assertOk()->assertJson(['success' => true]);
    }
}
