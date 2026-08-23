<?php

namespace Tests\Feature;

use App\Services\PosPlanComparisonService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRA POS: Pro merged INTO Business (owner decision, 23 Aug 2026).
 *
 * The danger in a package merge is never the price column — it is the shops
 * already sitting on the package that disappears. This pins the whole contract:
 *   • Business keeps its NAME but takes Pro's feature set and capacity, and is
 *     repriced to Rs 27,999 / 7,349 / 2,599.
 *   • WhatsApp Bill is included in Business and Unlimited; Caller ID only in
 *     Unlimited; Rider Live Tracking stays a paid add-on everywhere.
 *   • Pro and Pro Max rows SURVIVE (history must keep pointing at what was
 *     really bought) but stop being sellable.
 *   • A live Pro subscription moves to Business with its dates, cycle and the
 *     amount actually paid untouched; a finished one stays on Pro.
 *   • Only PENDING signup requests and PENDING proofs are re-pointed.
 *   • Running it twice changes nothing.
 */
class PosProMergeIntoBusinessMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->decimal('price', 12, 2);
            $table->decimal('price_quarterly', 12, 2)->nullable();
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->integer('invoice_limit')->default(0);
            $table->integer('user_limit')->nullable();
            $table->integer('branch_limit')->nullable();
            $table->integer('max_terminals')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_products')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->boolean('is_public')->default(true);
            foreach (self::GATE_COLUMNS as $column) {
                $table->boolean($column)->default(false);
            }
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->boolean('active')->default(true);
            $table->string('billing_cycle')->nullable();
            $table->decimal('final_price', 12, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    private const GATE_COLUMNS = [
        'inventory_enabled', 'reports_enabled', 'restaurant_enabled',
        'deals_enabled', 'riders_enabled', 'hazri_enabled',
        'analytics_enabled', 'rider_tracking_enabled',
        'custom_access_enabled', 'qr_menu_enabled', 'offline_enabled',
        'excel_enabled', 'khata_enabled', 'loyalty_enabled',
        'kot_enabled', 'caller_id_enabled', 'whatsapp_enabled',
    ];

    public function test_business_absorbs_pro_and_the_shops_on_pro_come_with_it(): void
    {
        $starterId = $this->insertPlan('Starter', 17999, [
            'price_quarterly' => 4699, 'price_monthly' => 1649,
            'invoice_limit' => 2000, 'user_limit' => 2, 'branch_limit' => 1, 'max_terminals' => 1,
            'khata_enabled' => true, 'loyalty_enabled' => true, 'kot_enabled' => true,
            'inventory_enabled' => true,
        ]);
        $businessId = $this->insertPlan('Business', 24999, [
            'price_quarterly' => 6549, 'price_monthly' => 2299,
            'invoice_limit' => -1, 'user_limit' => 5, 'branch_limit' => 1, 'max_terminals' => -1,
            'inventory_enabled' => true, 'reports_enabled' => true, 'restaurant_enabled' => true,
            'deals_enabled' => true, 'riders_enabled' => true, 'analytics_enabled' => true,
            'custom_access_enabled' => true, 'qr_menu_enabled' => true, 'offline_enabled' => true,
            'excel_enabled' => true, 'khata_enabled' => true, 'loyalty_enabled' => true,
            'kot_enabled' => true,
        ]);
        // Pro is the row Business must end up looking like — Hazri included.
        $proId = $this->insertPlan('Pro', 29999, [
            'price_quarterly' => 7849, 'price_monthly' => 2749,
            'invoice_limit' => -1, 'user_limit' => 20, 'branch_limit' => 3, 'max_terminals' => -1,
            'inventory_enabled' => true, 'reports_enabled' => true, 'restaurant_enabled' => true,
            'deals_enabled' => true, 'riders_enabled' => true, 'hazri_enabled' => true,
            'analytics_enabled' => true, 'custom_access_enabled' => true, 'qr_menu_enabled' => true,
            'offline_enabled' => true, 'excel_enabled' => true, 'khata_enabled' => true,
            'loyalty_enabled' => true, 'kot_enabled' => true,
        ]);
        $proMaxId = $this->insertPlan('Pro Max', 49999, [
            'price_quarterly' => 14399, 'invoice_limit' => -1, 'user_limit' => 20,
            'branch_limit' => 3, 'max_terminals' => -1, 'hazri_enabled' => true,
        ]);
        $unlimitedId = $this->insertPlan('Unlimited', 34999, [
            'price_quarterly' => 9199, 'price_monthly' => 3199,
            'invoice_limit' => -1, 'user_limit' => 20, 'branch_limit' => -1, 'max_terminals' => -1,
            'inventory_enabled' => true, 'reports_enabled' => true, 'restaurant_enabled' => true,
            'deals_enabled' => true, 'riders_enabled' => true, 'hazri_enabled' => true,
            'analytics_enabled' => true, 'custom_access_enabled' => true, 'qr_menu_enabled' => true,
            'offline_enabled' => true, 'excel_enabled' => true, 'khata_enabled' => true,
            'loyalty_enabled' => true, 'kot_enabled' => true,
        ]);

        // A shop mid-term on Pro, and a finished Pro Max term in its history.
        $liveSubId = DB::table('subscriptions')->insertGetId([
            'company_id' => 101, 'pricing_plan_id' => $proId, 'active' => true,
            'billing_cycle' => 'annual', 'final_price' => 29999,
            'start_date' => '2026-05-01', 'end_date' => '2027-05-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldSubId = DB::table('subscriptions')->insertGetId([
            'company_id' => 101, 'pricing_plan_id' => $proMaxId, 'active' => false,
            'billing_cycle' => 'annual', 'final_price' => 49999,
            'start_date' => '2025-05-01', 'end_date' => '2026-05-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('companies')->insert([
            ['id' => 101, 'requested_plan_id' => $proId, 'status' => 'pending', 'company_status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'requested_plan_id' => $proMaxId, 'status' => 'approved', 'company_status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pendingProofId = DB::table('payment_proofs')->insertGetId([
            'pricing_plan_id' => $proId, 'status' => 'pending', 'amount' => 29999,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $verifiedProofId = DB::table('payment_proofs')->insertGetId([
            'pricing_plan_id' => $proId, 'status' => 'verified', 'amount' => 29999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        // ── Business IS Pro now, at the new price ────────────────────────
        $business = DB::table('pricing_plans')->find($businessId);
        $pro = DB::table('pricing_plans')->find($proId);
        $this->assertSame('Business', $business->name, 'the name must not change — only what it contains');
        $this->assertSame(27999.0, (float) $business->price);
        $this->assertSame(7349.0, (float) $business->price_quarterly);
        $this->assertSame(2599.0, (float) $business->price_monthly);
        $this->assertSame(-1, (int) $business->invoice_limit);
        $this->assertSame(7, (int) $business->user_limit, 'Business sells 7 team accounts');
        $this->assertSame(1, (int) $business->branch_limit, 'extra branches are the paid add-on');
        $this->assertSame(-1, (int) $business->max_terminals, 'counters are unlimited');
        $this->assertSame(-1, (int) $business->max_products);
        $this->assertSame(1, (int) $business->hazri_enabled, 'Staff Hazri came down from Pro');

        foreach (self::GATE_COLUMNS as $column) {
            if (in_array($column, ['whatsapp_enabled', 'caller_id_enabled', 'rider_tracking_enabled'], true)) {
                continue; // decided explicitly below
            }
            $this->assertSame((int) $pro->{$column}, (int) $business->{$column},
                "Business must hold every gate Pro held ({$column}).");
        }

        // ── The two add-ons the owner moved, and the one he did not ──────
        $this->assertSame(1, (int) $business->whatsapp_enabled, 'WhatsApp Bill is included in Business');
        $this->assertSame(0, (int) $business->caller_id_enabled, 'Caller ID stays a paid add-on for Business');
        $this->assertSame(0, (int) $business->rider_tracking_enabled, 'Rider Live Tracking stays add-on-only');

        $unlimited = DB::table('pricing_plans')->find($unlimitedId);
        $this->assertSame(1, (int) $unlimited->whatsapp_enabled);
        $this->assertSame(1, (int) $unlimited->caller_id_enabled, 'Caller ID is included in Unlimited');
        $this->assertSame(0, (int) $unlimited->rider_tracking_enabled);
        $this->assertSame(12, (int) $unlimited->user_limit);
        $this->assertSame(2, (int) $unlimited->branch_limit, 'Unlimited includes 2 branches, the rest are paid');
        $this->assertSame(34999.0, (float) $unlimited->price, 'Unlimited was not repriced');

        $starter = DB::table('pricing_plans')->find($starterId);
        $this->assertSame(17999.0, (float) $starter->price, 'Starter is untouched');
        $this->assertSame(2000, (int) $starter->invoice_limit);
        $this->assertSame(2, (int) $starter->user_limit);
        $this->assertSame(0, (int) $starter->whatsapp_enabled, 'Starter still buys WhatsApp Bill');

        // ── Retired rows survive, but are off the shelf ──────────────────
        foreach ([$proId, $proMaxId] as $retiredId) {
            $row = DB::table('pricing_plans')->find($retiredId);
            $this->assertNotNull($row, 'a retired package row must stay for history');
            $this->assertSame(0, (int) $row->is_public);
        }
        $this->assertSame(
            ['Starter', 'Business', 'Unlimited'],
            PosPlanComparisonService::plans()->pluck('name')->all()
        );

        // ── Shops move; history does not ─────────────────────────────────
        $live = DB::table('subscriptions')->find($liveSubId);
        $this->assertSame($businessId, (int) $live->pricing_plan_id, 'a live Pro shop is now on Business');
        $this->assertSame('annual', $live->billing_cycle);
        $this->assertSame(29999.0, (float) $live->final_price, 'what the shop paid must not be rewritten');
        $this->assertSame('2027-05-01', $live->end_date, 'the term must not move');
        $this->assertSame($proMaxId, (int) DB::table('subscriptions')->find($oldSubId)->pricing_plan_id,
            'a finished term keeps pointing at the package that was really bought');

        $this->assertSame($businessId, (int) DB::table('companies')->find(101)->requested_plan_id);
        $this->assertSame($proMaxId, (int) DB::table('companies')->find(102)->requested_plan_id,
            'an already-approved signup is history, not a pending request');
        $this->assertSame($businessId, (int) DB::table('payment_proofs')->find($pendingProofId)->pricing_plan_id);
        $this->assertSame($proId, (int) DB::table('payment_proofs')->find($verifiedProofId)->pricing_plan_id,
            'a verified proof records what was actually paid for');

        // ── Idempotent ───────────────────────────────────────────────────
        $this->runMigration();
        $this->assertSame(27999.0, (float) DB::table('pricing_plans')->find($businessId)->price);
        $this->assertSame($businessId, (int) DB::table('subscriptions')->find($liveSubId)->pricing_plan_id);
        $this->assertSame($proMaxId, (int) DB::table('subscriptions')->find($oldSubId)->pricing_plan_id);
    }

    /** A shop already on Business must not be disturbed by the merge. */
    public function test_a_shop_already_on_business_keeps_its_own_subscription_row(): void
    {
        $businessId = $this->insertPlan('Business', 24999, ['invoice_limit' => -1, 'user_limit' => 5, 'branch_limit' => 1]);
        $this->insertPlan('Pro', 29999, ['invoice_limit' => -1, 'user_limit' => 20, 'branch_limit' => 3]);

        $subId = DB::table('subscriptions')->insertGetId([
            'company_id' => 55, 'pricing_plan_id' => $businessId, 'active' => true,
            'billing_cycle' => 'quarterly', 'final_price' => 6549,
            'start_date' => '2026-08-01', 'end_date' => '2026-11-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $sub = DB::table('subscriptions')->find($subId);
        $this->assertSame($businessId, (int) $sub->pricing_plan_id);
        $this->assertSame(6549.0, (float) $sub->final_price, 'a paid quarterly term is not repriced mid-cycle');
        $this->assertSame('2026-11-01', $sub->end_date);
    }

    private function insertPlan(string $name, int $price, array $extra = []): int
    {
        return (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => $name,
            'product_type' => 'pos',
            'price' => $price,
            'max_products' => -1,
            'max_users' => -1,
            'is_trial' => false,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_17_120000_pos_merge_pro_into_business.php');
        $migration->up();
    }
}
