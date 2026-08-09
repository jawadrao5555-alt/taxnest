<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR POS strict plan binding — ROUTE-LEVEL gate tests (Task 386, owner-approved
 * 9 Aug 2026 ladder: Starter 999 / Business 1,999 / Pro 2,999).
 *
 * These go through the REAL route + middleware stack (fbrpos.auth →
 * company.approval → controller fbrPlanGate), so they fail if anyone removes a
 * fbrPlanGate() call from an entry point — not just if planAllows itself breaks.
 *
 * Matrix locked here (premium gates only; inventory_enabled is deliberately 1
 * on EVERY paid fbrpos plan — inventory is included in all tiers, per owner):
 *   - Starter: khata/deals/loyalty/excel/reports/analytics/kot ALL redirect
 *     to /fbr-pos/billing (page requests) — nothing premium leaks.
 *   - Business: khata/excel/reports pass the gate; deals/loyalty/analytics/kot
 *     still redirect to billing.
 *   - Active trial: everything passes the gate (evaluate-first rule).
 *   - Expired trial: locked exactly like Starter.
 *
 * "Passes the gate" = the response is NOT the fbrPlanGate redirect to
 * /fbr-pos/billing (the page itself may need more schema than this minimal
 * sqlite setup provides — the gate decision is what's under test).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (same as WhatsNewAudienceTargetingTest / FbrPosPlanGatingTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosStrictGateRoutesTest.php
 */
class FbrPosStrictGateRoutesTest extends TestCase
{
    /** Gate column => a representative gated route (all GET page requests). */
    private const GATED_ROUTES = [
        'khata_enabled'     => '/fbr-pos/khata',
        'deals_enabled'     => '/fbr-pos/promotions',
        'loyalty_enabled'   => '/fbr-pos/loyalty',
        'excel_enabled'     => '/fbr-pos/products/template',
        'reports_enabled'   => '/fbr-pos/reports/export-csv',
        'analytics_enabled' => '/fbr-pos/reports/analytics-pdf',
        'kot_enabled'       => '/fbr-pos/held/999999/kitchen-ticket',
    ];

    private int $companyId;
    private int $adminId;
    private int $starterPlanId;
    private int $businessPlanId;
    private int $trialPlanId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // BranchContextService (bound in fbrpos.auth middleware)
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('fbrpos');
            $table->boolean('is_trial')->default(false);
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('offline_enabled')->default(false);
            $table->boolean('excel_enabled')->default(false);
            $table->boolean('khata_enabled')->default(false);
            $table->boolean('reports_enabled')->default(false);
            $table->boolean('deals_enabled')->default(false);
            $table->boolean('loyalty_enabled')->default(false);
            $table->boolean('kot_enabled')->default(false);
            $table->boolean('analytics_enabled')->default(false);
            // Extra gate columns planAllows may consult via PLAN_GATES.
            $table->boolean('riders_enabled')->default(false);
            $table->boolean('hazri_enabled')->default(false);
            $table->boolean('rider_tracking_enabled')->default(false);
            $table->boolean('custom_access_enabled')->default(false);
            $table->boolean('qr_menu_enabled')->default(false);
            $table->boolean('restaurant_enabled')->default(false);
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
            $table->timestamps();
        });

        $now = now();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Strict Gate Shop', 'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true, 'status' => 'approved',
            'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->adminId = DB::table('users')->insertGetId([
            'name' => 'FBR Admin', 'email' => 'gateadmin@sg.test',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->companyId, 'role' => 'company_admin',
            'pos_role' => 'pos_admin', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Owner-approved fbrpos ladder (mirror of the reprice migration).
        $this->starterPlanId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Starter', 'product_type' => 'fbrpos', 'price' => 999,
            'inventory_enabled' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->businessPlanId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'product_type' => 'fbrpos', 'price' => 1999,
            'inventory_enabled' => true, 'offline_enabled' => true,
            'excel_enabled' => true, 'khata_enabled' => true, 'reports_enabled' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->trialPlanId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Trial', 'product_type' => 'fbrpos', 'is_trial' => true,
            'inventory_enabled' => true, // gate COLUMNS stay 0 — isTrialActive unlocks
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function subscribe(int $planId, ?\DateTimeInterface $trialEndsAt = null): void
    {
        DB::table('subscriptions')->where('company_id', $this->companyId)->delete();
        DB::table('subscriptions')->insert([
            'company_id' => $this->companyId, 'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'trial_ends_at' => $trialEndsAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        PosFeatureService::flushGateCaches();
    }

    private function hitAsAdmin(string $uri)
    {
        PosFeatureService::flushGateCaches();
        return $this->actingAs(User::find($this->adminId), 'fbrpos')->get($uri);
    }

    private function assertBlockedToBilling(string $gate, string $uri): void
    {
        $resp = $this->hitAsAdmin($uri);
        $this->assertTrue(
            $resp->isRedirect() && str_contains($resp->headers->get('Location') ?? '', '/fbr-pos/billing'),
            "{$gate}: {$uri} must redirect to /fbr-pos/billing, got status {$resp->getStatusCode()}"
                . ' location=' . ($resp->headers->get('Location') ?? 'none')
        );
    }

    private function assertGatePasses(string $gate, string $uri): void
    {
        $resp = $this->hitAsAdmin($uri);
        $loc = $resp->headers->get('Location') ?? '';
        $this->assertFalse(
            $resp->isRedirect() && str_contains($loc, '/fbr-pos/billing'),
            "{$gate}: {$uri} must NOT be plan-blocked (got billing redirect)"
        );
        $this->assertFalse(
            $resp->isRedirect() && str_contains($loc, '/fbr-pos/login'),
            "{$gate}: {$uri} bounced to login — auth fixture broken, gate untested"
        );
    }

    public function test_starter_is_blocked_on_every_premium_gate_route(): void
    {
        $this->subscribe($this->starterPlanId);
        foreach (self::GATED_ROUTES as $gate => $uri) {
            $this->assertBlockedToBilling($gate, $uri);
        }
    }

    public function test_business_passes_its_gates_but_stays_blocked_on_pro_features(): void
    {
        $this->subscribe($this->businessPlanId);

        foreach (['khata_enabled', 'excel_enabled', 'reports_enabled'] as $gate) {
            $this->assertGatePasses($gate, self::GATED_ROUTES[$gate]);
        }
        foreach (['deals_enabled', 'loyalty_enabled', 'analytics_enabled', 'kot_enabled'] as $gate) {
            $this->assertBlockedToBilling($gate, self::GATED_ROUTES[$gate]);
        }
    }

    public function test_active_trial_passes_every_gate(): void
    {
        $this->subscribe($this->trialPlanId, now()->addDays(3));
        foreach (self::GATED_ROUTES as $gate => $uri) {
            $this->assertGatePasses($gate, $uri);
        }
    }

    public function test_expired_trial_is_blocked_like_starter(): void
    {
        $this->subscribe($this->trialPlanId, now()->subDay());
        foreach (self::GATED_ROUTES as $gate => $uri) {
            $this->assertBlockedToBilling($gate, $uri);
        }
    }
}
