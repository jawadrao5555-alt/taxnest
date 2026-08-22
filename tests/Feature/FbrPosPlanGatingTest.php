<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Middleware\CheckPlanLimit;
use App\Services\PlanLimitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS strict plan mapping (owner order, Aug 2026) — locks the new
 * enforcement so a subscription buys EXACTLY its plan, nothing extra:
 *
 *   1. PlanLimitService::canCreateFbrPosBill — monthly quota counts
 *      fbr_pos_transactions completed SALES (returns excluded, provisional
 *      'local' rows INCLUDED — creation-time counting means later promotion
 *      is quota-free), current month only.
 *   2. Admin override (bills/month) + -1 unlimited + trial + internal bypass.
 *   3. CheckPlanLimit 'invoices' case routes fbrpos plans to the monthly
 *      FBR quota and blocks with 403 JSON at the limit.
 *   4. REPLAY GUARD BEFORE QUOTA: a retry carrying an offline_uuid that is
 *      already saved passes the middleware even at quota-full (the
 *      controller's replay guard must answer, never a quota error).
 *   5. 'inventory' case: paid plan without inventory blocks the FBR stock
 *      routes; an active trial plan is never blocked (evaluate-first rule).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (same as PosMonthlyBillQuotaPathsTest).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrPosPlanGatingTest.php --testdox
 */
class FbrPosPlanGatingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->integer('user_limit_override')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('user_limit')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_products')->nullable();
            $table->integer('max_terminals')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->default('sale');
            $table->string('status')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('offline_uuid')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('fbr_pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'FBR Gating Co',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'is_internal_account' => false,
            'invoice_limit_override' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function subscribe(int $companyId, array $planAttrs = []): int
    {
        $planId = (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Starter',
            'product_type' => 'fbrpos',
            'is_trial' => false,
            'invoice_limit' => -1,
            'inventory_enabled' => false,
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

        return $planId;
    }

    private function addBill(int $companyId, array $attrs = []): int
    {
        return (int) DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => 'TEST-' . uniqid(),
            'invoice_mode' => 'fbr',
            'transaction_type' => 'sale',
            'status' => 'completed',
            'total_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /** Run CheckPlanLimit with currentCompanyId bound, JSON request. */
    private function runMiddleware(int $companyId, string $resource, array $input = [])
    {
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/fbr-pos/store', 'POST', $input);
        $request->headers->set('Accept', 'application/json');

        return (new CheckPlanLimit())->handle($request, fn () => response()->json(['passed' => true]), $resource);
    }

    // ── 1-2: service-level quota semantics ──────────────────────────────────

    public function test_monthly_quota_counts_sales_not_returns_and_includes_provisionals(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId, ['invoice_limit' => 3]);

        // 1 final + 1 provisional local = 2 counted; return + last-month NOT counted.
        $this->addBill($companyId);
        $this->addBill($companyId, ['invoice_mode' => 'local', 'fbr_status' => 'local']);
        $this->addBill($companyId, ['transaction_type' => 'return']);
        $this->addBill($companyId, ['created_at' => now()->subMonth()]);

        $check = PlanLimitService::canCreateFbrPosBill($companyId);
        $this->assertTrue($check['allowed']);
        $this->assertSame(1, $check['remaining'] ?? null, 'sale+provisional counted, return+old month excluded');

        $this->addBill($companyId); // 3rd counted bill = at limit
        $check = PlanLimitService::canCreateFbrPosBill($companyId);
        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('Starter', $check['reason']);
    }

    public function test_quota_bypasses_unlimited_trial_internal_and_override(): void
    {
        // Plan -1 = unlimited
        $c1 = $this->makeCompany();
        $this->subscribe($c1, ['invoice_limit' => -1]);
        $this->addBill($c1);
        $this->assertTrue(PlanLimitService::canCreateFbrPosBill($c1)['allowed']);

        // Trial plan = always allowed (trial cap owned by SubscriptionAccessService)
        $c2 = $this->makeCompany();
        $this->subscribe($c2, ['is_trial' => true, 'invoice_limit' => 1]);
        $this->addBill($c2);
        $this->assertTrue(PlanLimitService::canCreateFbrPosBill($c2)['allowed']);

        // Internal account = always allowed
        $c3 = $this->makeCompany(['is_internal_account' => true]);
        $this->assertTrue(PlanLimitService::canCreateFbrPosBill($c3)['allowed']);

        // Admin override wins over plan: override 1 blocks at 1 even on unlimited plan
        $c4 = $this->makeCompany(['invoice_limit_override' => 1]);
        $this->subscribe($c4, ['invoice_limit' => -1]);
        $this->addBill($c4);
        $this->assertFalse(PlanLimitService::canCreateFbrPosBill($c4)['allowed']);

        // Override -1 = unlimited
        $c5 = $this->makeCompany(['invoice_limit_override' => -1]);
        $this->subscribe($c5, ['invoice_limit' => 1]);
        $this->addBill($c5);
        $this->assertTrue(PlanLimitService::canCreateFbrPosBill($c5)['allowed']);
    }

    // ── 3-4: middleware 'invoices' case ─────────────────────────────────────

    public function test_middleware_blocks_fbrpos_store_at_quota_with_403_reason(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId, ['invoice_limit' => 1]);
        $this->addBill($companyId);

        $response = $this->runMiddleware($companyId, 'invoices');
        $this->assertSame(403, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertStringContainsString('Monthly bill limit reached', $data['error'] ?? '');
        $this->assertArrayHasKey('message', $data, 'sale screen reads message key');
    }

    public function test_middleware_allows_within_quota_and_replay_bypasses_quota(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId, ['invoice_limit' => 2]);
        $this->addBill($companyId);

        // Within quota → passes.
        $response = $this->runMiddleware($companyId, 'invoices');
        $this->assertSame(200, $response->getStatusCode());

        // Quota full…
        $this->addBill($companyId, ['offline_uuid' => 'uuid-already-saved']);
        $response = $this->runMiddleware($companyId, 'invoices');
        $this->assertSame(403, $response->getStatusCode());

        // …but a REPLAY of an already-saved bill must pass through to the
        // controller's replay guard (never a quota error for a saved bill).
        $response = $this->runMiddleware($companyId, 'invoices', ['offline_uuid' => 'uuid-already-saved']);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_package_scoped_grant_keeps_fbr_monthly_bill_cap(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId, ['invoice_limit' => 1]);
        $this->addBill($companyId);

        DB::table('subscriptions')->where('company_id', $companyId)->update([
            'override_type' => 'temporary',
            'override_until' => now()->addDay(),
        ]);

        // The service covers controller-side checks and the middleware covers
        // routes that previously skipped every cap for an active grant.
        $this->assertFalse(PlanLimitService::canCreateFbrPosBill($companyId)['allowed']);
        $this->assertSame(403, $this->runMiddleware($companyId, 'invoices')->getStatusCode());
    }

    public function test_package_scoped_grant_keeps_fbr_counter_cap(): void
    {
        $companyId = $this->makeCompany();
        $this->subscribe($companyId, ['max_terminals' => 1]);
        DB::table('fbr_pos_terminals')->insert([
            'company_id' => $companyId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->where('company_id', $companyId)->update([
            'override_type' => 'temporary',
            'override_until' => now()->addDay(),
        ]);

        // Toggle/reactivation uses the direct service; new-counter creation
        // passes through the middleware. Both must retain the package cap.
        $this->assertFalse(PlanLimitService::canAddFbrTerminal($companyId)['allowed']);
        $this->assertSame(403, $this->runMiddleware($companyId, 'terminals')->getStatusCode());
    }

    public function test_trial_and_planless_grants_keep_the_blanket_escape_hatch(): void
    {
        $trialCompany = $this->makeCompany();
        $this->subscribe($trialCompany, ['is_trial' => true, 'invoice_limit' => 1]);
        DB::table('subscriptions')->where('company_id', $trialCompany)->update([
            'override_type' => 'temporary',
            'override_until' => now()->addDay(),
        ]);
        $trialSub = PlanLimitService::getActiveSubscription($trialCompany);
        $this->assertTrue(PlanLimitService::grantWaivesPackageLimits($trialSub));

        $planlessCompany = $this->makeCompany(['name' => 'Plan-less Partner']);
        DB::table('subscriptions')->insert([
            'company_id' => $planlessCompany,
            'pricing_plan_id' => null,
            'active' => true,
            'override_type' => 'temporary',
            'override_until' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $planlessSub = PlanLimitService::getActiveSubscription($planlessCompany);
        $this->assertTrue(PlanLimitService::grantWaivesPackageLimits($planlessSub));
        $this->assertSame(200, $this->runMiddleware($planlessCompany, 'invoices')->getStatusCode());
    }

    // ── 5: middleware 'inventory' case ──────────────────────────────────────

    public function test_inventory_blocked_without_plan_feature_but_trial_allowed(): void
    {
        // Paid plan without inventory → blocked (Starter/Business strict mapping).
        $c1 = $this->makeCompany();
        $this->subscribe($c1, ['inventory_enabled' => false]);
        $response = $this->runMiddleware($c1, 'inventory');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('inventory', $response->getData(true)['error'] ?? '');

        // Paid plan WITH inventory (Pro) → allowed.
        $c2 = $this->makeCompany();
        $this->subscribe($c2, ['name' => 'Pro', 'inventory_enabled' => true]);
        $this->assertSame(200, $this->runMiddleware($c2, 'inventory')->getStatusCode());

        // Active trial → allowed even without the feature column (evaluate-first).
        $c3 = $this->makeCompany();
        $this->subscribe($c3, ['is_trial' => true, 'inventory_enabled' => false]);
        $this->assertSame(200, $this->runMiddleware($c3, 'inventory')->getStatusCode());

        // DISABLE always allowed: downgraded company (column still ON) must be
        // able to POST enabled=false — otherwise sales keep deducting stock.
        $response = $this->runMiddleware($c1, 'inventory', ['enabled' => '0']);
        $this->assertSame(200, $response->getStatusCode(), 'toggle OFF must never be plan-blocked');
    }
}
