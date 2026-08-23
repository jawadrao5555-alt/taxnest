<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Services\FbrPosPlanComparisonService;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR POS: Pro merged INTO Business, two sellable packages (owner, 23 Aug 2026).
 *
 * Two things could go badly wrong here and both are pinned below:
 *   1. The PRICE CONVENTION changed. fbrpos used to store a MONTHLY price and
 *      charge price × 12 × 0.94 once a year. It now stores the ANNUAL rate with
 *      hand-set quarterly/monthly rates. If any surface kept the old reading, a
 *      shop would be sold a whole year for one month's fee (or charged twelve
 *      times over), so the migration's numbers are checked against what
 *      checkout actually charges.
 *   2. The one shop on Pro must land on Business with its term and the amount
 *      it paid untouched, and the Pro row must survive for history.
 */
class FbrPosTwoPackageLadderMigrationTest extends TestCase
{
    private const GATE_COLUMNS = [
        'inventory_enabled', 'reports_enabled', 'restaurant_enabled',
        'deals_enabled', 'riders_enabled', 'hazri_enabled',
        'analytics_enabled', 'rider_tracking_enabled',
        'custom_access_enabled', 'qr_menu_enabled', 'offline_enabled',
        'excel_enabled', 'khata_enabled', 'loyalty_enabled',
        'kot_enabled', 'caller_id_enabled', 'whatsapp_enabled',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('fbrpos');
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
            $table->decimal('discount_percent', 8, 2)->nullable();
            $table->decimal('final_price', 12, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('override_type')->nullable();
            $table->date('override_expires_at')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->text('override_reason')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('product_type')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->string('requested_billing_cycle')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->softDeletes();
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

    public function test_two_packages_the_new_annual_prices_and_the_pro_shop_moving_over(): void
    {
        $starterId = $this->insertPlan('Starter', 999, [
            'price_monthly' => 999, 'invoice_limit' => 500, 'user_limit' => 1,
            'branch_limit' => 1, 'max_terminals' => 1, 'max_products' => 100,
            'inventory_enabled' => true,
        ]);
        $businessId = $this->insertPlan('Business', 1999, [
            'price_monthly' => 1999, 'invoice_limit' => 2000, 'user_limit' => 3,
            'branch_limit' => 2, 'max_terminals' => 3, 'max_products' => 500,
            'inventory_enabled' => true, 'offline_enabled' => true, 'excel_enabled' => true,
            'khata_enabled' => true, 'reports_enabled' => true,
        ]);
        $proId = $this->insertPlan('Pro', 2999, [
            'price_monthly' => 2999, 'invoice_limit' => -1, 'user_limit' => -1,
            'branch_limit' => -1, 'max_terminals' => -1, 'max_products' => -1,
            'inventory_enabled' => true, 'offline_enabled' => true, 'excel_enabled' => true,
            'khata_enabled' => true, 'reports_enabled' => true, 'deals_enabled' => true,
            'loyalty_enabled' => true, 'kot_enabled' => true, 'analytics_enabled' => true,
        ]);
        $trialId = $this->insertPlan('Trial', 0, [
            'price_monthly' => 0, 'invoice_limit' => 10, 'user_limit' => 2,
            'branch_limit' => 1, 'max_terminals' => 1, 'max_products' => 50,
            'is_trial' => true, 'inventory_enabled' => true,
        ]);

        $liveSubId = DB::table('subscriptions')->insertGetId([
            'company_id' => 301, 'pricing_plan_id' => $proId, 'active' => true,
            'billing_cycle' => 'annual', 'final_price' => 33840,
            'start_date' => '2026-04-10', 'end_date' => '2027-04-10',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldSubId = DB::table('subscriptions')->insertGetId([
            'company_id' => 301, 'pricing_plan_id' => $proId, 'active' => false,
            'billing_cycle' => 'annual', 'final_price' => 33840,
            'start_date' => '2025-04-10', 'end_date' => '2026-04-10',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('companies')->insert([
            ['id' => 301, 'requested_plan_id' => $proId, 'status' => 'pending', 'company_status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 302, 'requested_plan_id' => $proId, 'status' => 'approved', 'company_status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pendingProofId = DB::table('payment_proofs')->insertGetId([
            'pricing_plan_id' => $proId, 'status' => 'pending', 'amount' => 33840,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $verifiedProofId = DB::table('payment_proofs')->insertGetId([
            'pricing_plan_id' => $proId, 'status' => 'verified', 'amount' => 33840,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        // ── Starter: 2,000 bills, unlimited products, yearly price ───────
        $starter = DB::table('pricing_plans')->find($starterId);
        $this->assertSame(17999.0, (float) $starter->price, 'price is now the ANNUAL rate');
        $this->assertSame(4699.0, (float) $starter->price_quarterly);
        $this->assertSame(1649.0, (float) $starter->price_monthly);
        $this->assertSame(2000, (int) $starter->invoice_limit);
        $this->assertSame(-1, (int) $starter->max_products, 'products are unlimited on both packages');
        $this->assertSame(1, (int) $starter->branch_limit);
        $this->assertSame(1, (int) $starter->user_limit, 'Starter capacity is otherwise untouched');

        // ── Business: everything Pro had, branches capped at 2 ───────────
        $business = DB::table('pricing_plans')->find($businessId);
        $pro = DB::table('pricing_plans')->find($proId);
        $this->assertSame('Business', $business->name);
        $this->assertSame(27999.0, (float) $business->price);
        $this->assertSame(7349.0, (float) $business->price_quarterly);
        $this->assertSame(2599.0, (float) $business->price_monthly);
        $this->assertSame(-1, (int) $business->invoice_limit);
        $this->assertSame(-1, (int) $business->user_limit);
        $this->assertSame(-1, (int) $business->max_terminals);
        $this->assertSame(-1, (int) $business->max_products);
        $this->assertSame(2, (int) $business->branch_limit,
            'branches are the ONE thing Business does not inherit — above 2 is the paid add-on');

        foreach (self::GATE_COLUMNS as $column) {
            $this->assertSame((int) $pro->{$column}, (int) $business->{$column},
                "Business must hold every gate Pro held ({$column}).");
        }
        $this->assertSame(1, (int) $business->deals_enabled);
        $this->assertSame(1, (int) $business->analytics_enabled);

        // ── Pro is off the shelf but still on record ─────────────────────
        $this->assertNotNull($pro, 'the retired row must stay for history');
        $this->assertSame(0, (int) $pro->is_public);
        $this->assertSame(['Starter', 'Business'], FbrPosPlanComparisonService::plans()->pluck('name')->all());
        $this->assertFalse(FbrPosPlanComparisonService::isSellablePlan(PricingPlan::find($proId)));
        $this->assertTrue(FbrPosPlanComparisonService::isSellablePlan(PricingPlan::find($businessId)));

        // ── The Pro shop lands on Business, unharmed ─────────────────────
        $live = DB::table('subscriptions')->find($liveSubId);
        $this->assertSame($businessId, (int) $live->pricing_plan_id);
        $this->assertSame(33840.0, (float) $live->final_price, 'what the shop paid is not rewritten');
        $this->assertSame('2027-04-10', $live->end_date, 'the term does not move');
        $this->assertSame($proId, (int) DB::table('subscriptions')->find($oldSubId)->pricing_plan_id,
            'a finished term keeps pointing at what was really bought');

        $this->assertSame($businessId, (int) DB::table('companies')->find(301)->requested_plan_id);
        $this->assertSame($proId, (int) DB::table('companies')->find(302)->requested_plan_id);
        $this->assertSame($businessId, (int) DB::table('payment_proofs')->find($pendingProofId)->pricing_plan_id);
        $this->assertSame($proId, (int) DB::table('payment_proofs')->find($verifiedProofId)->pricing_plan_id);

        // ── Trial is not a sellable package and must be left alone ───────
        $trial = DB::table('pricing_plans')->find($trialId);
        $this->assertSame(0.0, (float) $trial->price);
        $this->assertSame(10, (int) $trial->invoice_limit);

        // ── Checkout sells only annual, even if retired rates remain stored ──
        foreach ([$starterId => 17999.0, $businessId => 27999.0] as $id => $annual) {
            $plan = PricingPlan::find($id);
            foreach (['annual', 'quarterly', 'monthly'] as $cycle) {
                $priced = SubscriptionAssignmentService::computePrice($plan, $cycle);
                $this->assertSame('annual', $priced['cycle'], "{$plan->name}: only annual may be sold");
                $this->assertSame($annual, (float) $priced['final_price'],
                    "{$plan->name}: {$cycle} request must charge the annual total");
            }
        }

        // ── Idempotent ───────────────────────────────────────────────────
        $this->runMigration();
        $this->assertSame(27999.0, (float) DB::table('pricing_plans')->find($businessId)->price);
        $this->assertSame($businessId, (int) DB::table('subscriptions')->find($liveSubId)->pricing_plan_id);
        $this->assertSame($proId, (int) DB::table('subscriptions')->find($oldSubId)->pricing_plan_id);
    }

    /** With no Business row there is nothing to merge into — leave everything alone. */
    public function test_it_does_nothing_when_the_business_row_is_missing(): void
    {
        $proId = $this->insertPlan('Pro', 2999, ['invoice_limit' => -1, 'branch_limit' => -1]);
        $subId = DB::table('subscriptions')->insertGetId([
            'company_id' => 401, 'pricing_plan_id' => $proId, 'active' => true,
            'billing_cycle' => 'annual', 'final_price' => 33840,
            'start_date' => '2026-04-10', 'end_date' => '2027-04-10',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame($proId, (int) DB::table('subscriptions')->find($subId)->pricing_plan_id);
        $this->assertSame(1, (int) DB::table('pricing_plans')->find($proId)->is_public,
            'nothing may be retired when there is nowhere for its shops to go');
    }

    // ── after the merge: the retired package is off EVERY buying path ──
    //
    // Retiring used to be enforced per product line, and most call sites only
    // ever learned about PRA POS. PlanSellabilityService is now the single
    // product-aware answer, so a retired FBR package cannot come back through
    // a stale link, an old queued signup, or an admin assignment.

    public function test_the_retired_fbr_package_is_off_every_buying_path(): void
    {
        $this->insertPlan('Starter', 17999, ['invoice_limit' => 2000, 'branch_limit' => 1]);
        $businessId = $this->insertPlan('Business', 27999, ['invoice_limit' => -1, 'branch_limit' => 2]);
        $proId = $this->insertPlan('Pro', 2999, ['invoice_limit' => -1, 'branch_limit' => -1]);

        $this->runMigration();

        $pro = \App\Models\PricingPlan::find($proId);
        $business = \App\Models\PricingPlan::find($businessId);

        // The predicate itself, routed by product line.
        $this->assertTrue(\App\Services\PlanSellabilityService::isRetired($pro));
        $this->assertFalse(\App\Services\PlanSellabilityService::isRetired($business));

        // A stale ?plan=Pro link on the FBR signup resolves to nothing.
        $this->assertNull(\App\Services\RequestedPackageService::resolvePlan('Pro', 'fbrpos'));
        $this->assertNotNull(\App\Services\RequestedPackageService::resolvePlan('Business', 'fbrpos'));

        // A signup queued before the merge cannot be approved onto it.
        $companyId = DB::table('companies')->insertGetId([
            'requested_plan_id' => $proId, 'status' => 'pending', 'company_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNull(
            \App\Services\SubscriptionAssignmentService::assignRequestedPlanOnApproval(
                \App\Models\Company::find($companyId)
            ),
            'approving an old signup must not resurrect the retired package'
        );

        // And an admin cannot assign it by hand either.
        try {
            \App\Services\SubscriptionAssignmentService::assign($companyId, $proId, 'annual');
            $this->fail('assigning a retired FBR package must be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('FBR POS', $e->getMessage());
        }

        // The current package still assigns normally.
        $this->assertNotNull(
            \App\Services\SubscriptionAssignmentService::assign($companyId, $businessId, 'annual')
        );
    }

    /** A trial row is assigned by the system and is never "retired" here. */
    public function test_a_trial_row_is_never_treated_as_retired(): void
    {
        $trialId = $this->insertPlan('Trial', 0, ['is_trial' => true, 'is_public' => true]);

        $this->assertFalse(
            \App\Services\PlanSellabilityService::isRetired(\App\Models\PricingPlan::find($trialId))
        );
    }

    private function insertPlan(string $name, int $price, array $extra = []): int
    {
        return (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => $name,
            'product_type' => 'fbrpos',
            'price' => $price,
            'is_trial' => false,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_17_130000_fbrpos_two_package_ladder.php');
        $migration->up();
    }
}
