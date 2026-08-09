<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosBusinessDay;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DINE-IN TABLE-REQUIRED — DIRECT INVOICE PATH (owner voice note, 9 Aug 2026).
 *
 * PosDineInTableRequiredTest locks the two restaurant-order punch paths
 * (waiter storeOrder + cashier holdOrder). This file locks the third path the
 * code-review round found: PosController::storeInvoice — manual/deal carts and
 * crafted requests bypass the restaurant hold flow and land here directly with
 * order_type=dine_in and a null table.
 *
 * Rules locked:
 *   1. tables ON + dine_in + no table  → 422, ZERO rows persisted.
 *   2. OFFLINE REPLAY EXEMPTION — a queued offline bill (offline_queued_at)
 *      must NEVER be stranded by the new rule: losing a rung-up bill is far
 *      worse than a missing table.
 *   3. tables ON + dine_in + WITH table → bill stores normally.
 *   4. tables OFF (KOT-only kitchen)   → dine-in without table stays possible.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, full HTTP through the
 * real route stack (pos.auth → company.approval → plan.limit:invoices →
 * storeInvoice) — same approach as OfflineReplayDedupePoisonTest.
 */
class PosDineInTableRequiredInvoicePathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Both static feature caches leak between test classes (ids restart
        // at 1 after dropAllTables) — flush plan gates AND restaurantAllowed.
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
            $t->boolean('restaurant_mode')->default(false);
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
            $t->string('override_type')->default('none');
            $t->timestamp('override_expires_at')->nullable();
            $t->unsignedBigInteger('override_by')->nullable();
            $t->text('override_reason')->nullable();
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
            $t->string('order_type')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'invoice_number']);
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

        // Dine-in WITH table: storeInvoice frees a reserved table after the
        // bill stores — needs the real table row.
        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('floor_id')->nullable();
            $t->string('table_number');
            $t->integer('seats')->default(4);
            $t->string('status')->default('available');
            $t->timestamp('occupied_since')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Restaurant shop + logged-in POS admin. is_internal_account=true →
     * restaurantAllowed() passes without a restaurant_enabled plan column.
     */
    private function makeShop(array $flags, string $slug = 'shop'): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Table Guard Shop ' . $slug,
            'product_type' => 'pos',
            'status' => 'active',
            'company_status' => 'active',
            'is_internal_account' => true,
            'restaurant_mode' => true,
            'feature_flags' => json_encode($flags),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PosBusinessDay::forgetCutoff($companyId);

        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business',
            'product_type' => 'pos',
            'offline_enabled' => true,
            'is_trial' => false,
            'invoice_limit' => -1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'email' => "admin-{$slug}@tableguard.pk",
            'password' => bcrypt('secret-123'),
            'company_id' => $companyId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [Company::findOrFail($companyId), User::findOrFail($userId)];
    }

    private function dineInPayload(array $overrides = []): array
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
            'order_type' => 'dine_in',
        ], $overrides);
    }

    public function test_dine_in_without_table_rejected_when_tables_on(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));
        [, $user] = $this->makeShop(['tables' => true, 'kot' => true, 'kitchen' => true], 'on');

        $res = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->dineInPayload());

        $res->assertStatus(422)->assertJson(['success' => false, 'message' => __('pos.dine_in_table_required')]);
        $this->assertSame(0, DB::table('pos_transactions')->count(), 'no bill may be persisted');
    }

    public function test_offline_replay_dine_in_without_table_is_never_stranded(): void
    {
        // A bill queued offline (possibly before this rule existed) must sync.
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));
        [, $user] = $this->makeShop(['tables' => true, 'kot' => true, 'kitchen' => true], 'replay');

        $res = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->dineInPayload([
                'offline_uuid' => 'legacy-dinein-0001',
                'offline_queued_at' => now()->subHours(3)->toIso8601String(),
            ]));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, DB::table('pos_transactions')->count());
    }

    public function test_dine_in_with_table_stores_normally(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));
        [$company, $user] = $this->makeShop(['tables' => true, 'kot' => true, 'kitchen' => true], 'withtable');
        $tableId = DB::table('restaurant_tables')->insertGetId([
            'company_id' => $company->id, 'table_number' => 'T-2',
            'status' => 'reserved', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->dineInPayload(['table_id' => $tableId]));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, DB::table('pos_transactions')->count());
    }

    public function test_dine_in_without_table_allowed_when_tables_feature_off(): void
    {
        // KOT-only kitchen — no tables exist to pick; billing must stay possible.
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));
        [, $user] = $this->makeShop(['tables' => false, 'kot' => true, 'kitchen' => true], 'off');

        $res = $this->actingAs($user, 'pos')
            ->postJson('/pos/invoice/store', $this->dineInPayload());

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, DB::table('pos_transactions')->count());
    }
}
