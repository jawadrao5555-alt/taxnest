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
 * Task 128 (locks the Task 117 rule): offline REPLAY is NEVER plan-gated.
 *
 * A paired Business shop queues bills in IndexedDB while offline. If the shop
 * is then downgraded to Starter (pricing_plans.offline_enabled = false), the
 * queued bills must STILL sync: syncOfflineBills replays them to
 * POST /pos/invoice/store with an offline_uuid, and storeInvoice deliberately
 * carries NO offline_enabled gate. The Starter gate only stops NEW pairings /
 * key creation (AgentManagementController) — never the sync of bills already
 * rung up.
 *
 * These are full HTTP tests through the real route + middleware stack
 * (pos.auth → company.approval → plan.limit:invoices → storeInvoice), so they
 * fail if ANYONE adds a planAllows('offline_enabled') rejection anywhere on
 * that path — not just inside the controller method.
 *
 * Also locked: the offline_uuid dedupe guard (a second replay of the same
 * queued bill returns the SAME bill with replayed=true, no duplicate row)
 * must keep working for downgraded shops — it sits BEFORE any quota/plan
 * logic in storeInvoice.
 *
 * Pattern: sqlite :memory: + minimal Schema::create (same approach as
 * OfflinePlanGateTest / PosDayCloseAutoFinalizeTest). Columns guarded by
 * Schema::hasColumn in production code (tax_menu_rate, rider_id, …) are
 * deliberately omitted where irrelevant.
 */
class OfflineReplayAfterDowngradeTest extends TestCase
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
    }

    /** Company on a given plan + its logged-in POS admin. */
    private function makeShop(array $planAttrs): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Paired Shop',
            'product_type' => 'pos',
            'status' => 'active',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planId = DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Business',
            'product_type' => 'pos',
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
            'name' => 'Shop Admin',
            'email' => 'admin@pairedshop.pk',
            'password' => bcrypt('secret-123'),
            'company_id' => $companyId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [Company::findOrFail($companyId), User::findOrFail($userId)];
    }

    /** Downgrade the shop's active subscription to a Starter plan (offline gate OFF). */
    private function downgradeToStarter(Company $company): void
    {
        $starterId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Starter',
            'product_type' => 'pos',
            'offline_enabled' => false,
            'deals_enabled' => false,
            'invoice_limit' => -1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->where('company_id', $company->id)
            ->update(['pricing_plan_id' => $starterId, 'updated_at' => now()]);
        // Plan flip must be visible immediately — mirrors a fresh request after
        // the downgrade (the static gate cache is per-request in production).
        PosFeatureService::flushGateCaches();
    }

    /** The exact payload syncOfflineBills replays from the IndexedDB queue. */
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

    public function test_queued_offline_bill_still_syncs_after_downgrade_to_starter(): void
    {
        [$company, $user] = $this->makeShop(['name' => 'Business', 'offline_enabled' => true]);

        // Shop was paired while on Business; bills queued offline; then downgraded.
        $this->downgradeToStarter($company);

        // Sanity: the plan gate itself really is CLOSED for this shop now —
        // proving the accept below is the deliberate no-gate-on-replay rule,
        // not a mis-built fixture.
        $this->assertFalse(PosFeatureService::planAllows($company->fresh(), 'offline_enabled'));

        $uuid = 'off-e2e-0001';
        $response = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('pos_transactions', [
            'company_id' => $company->id,
            'offline_uuid' => $uuid,
            'status' => 'completed',
        ]);
    }

    public function test_replay_of_same_uuid_after_downgrade_dedupes_instead_of_duplicating(): void
    {
        [$company, $user] = $this->makeShop(['name' => 'Business', 'offline_enabled' => true]);
        $this->downgradeToStarter($company);

        $uuid = 'off-e2e-0002';
        $first = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));
        $first->assertOk()->assertJson(['success' => true]);
        $txId = $first->json('transaction_id');

        // Lost-response retry: sync engine replays the identical queued bill.
        $second = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));

        $second->assertOk()->assertJson([
            'success' => true,
            'replayed' => true,
            'transaction_id' => $txId,
        ]);
        $this->assertSame(1, DB::table('pos_transactions')
            ->where('company_id', $company->id)
            ->where('offline_uuid', $uuid)
            ->count());
    }

    public function test_replay_dedupes_even_after_day_close_archived_the_bill(): void
    {
        // Sync succeeded, response was lost, day-close ARCHIVED the bill, and the
        // queue retries next morning: withoutGlobalScope('hide_archived') in the
        // replay guard must still find it — no duplicate row, no second bill.
        [$company, $user] = $this->makeShop(['name' => 'Business', 'offline_enabled' => true]);
        $this->downgradeToStarter($company);

        $uuid = 'off-e2e-0004';
        $first = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));
        $first->assertOk();
        $txId = $first->json('transaction_id');

        DB::table('pos_transactions')->where('id', $txId)
            ->update(['is_archived' => true, 'archived_at' => now()]);

        $retry = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload($uuid));

        $retry->assertOk()->assertJson([
            'success' => true,
            'replayed' => true,
            'transaction_id' => $txId,
        ]);
        $this->assertSame(1, DB::table('pos_transactions')
            ->where('company_id', $company->id)
            ->where('offline_uuid', $uuid)
            ->count());
    }

    public function test_starter_shop_from_day_one_can_also_land_offline_uuid_bills(): void
    {
        // Paired-then-grandfathered edge: even a shop that only ever held
        // Starter must never have its replays rejected by a plan gate —
        // pairing/key creation is where Starter is blocked, not the sync.
        [$company, $user] = $this->makeShop(['name' => 'Starter', 'offline_enabled' => false]);

        $this->assertFalse(PosFeatureService::planAllows($company, 'offline_enabled'));

        $response = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->queuedBillPayload('off-e2e-0003'));

        $response->assertOk()->assertJson(['success' => true]);
    }
}
