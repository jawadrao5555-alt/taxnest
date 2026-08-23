<?php

namespace Tests\Feature;

use App\Services\BranchAddonService;
use App\Services\PlanLimitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PAID EXTRA-BRANCH SLOTS — enforcement scope (owner approved, Aug 2026;
 * see .agents/memory/pos-extra-branch-addon.md)
 *
 * The Rs 10,000/branch/year add-on is a NestPOS (PRA) product. Slots live in a
 * plain companies column, but the branch gate is SHARED with FBR POS / DI /
 * standalone companies — so the stored number must only ever widen a limit it
 * was actually sold for. Locked here:
 *
 *   1. Active PRA POS package: limit = package branches + purchased slots, and
 *      the gate opens for exactly that many branches, then closes again.
 *   2. FBR POS, DI and no-product companies: slots change NOTHING.
 *   3. Trial packages: slots change nothing (buy a package first).
 *   4. Expired packages: slots change nothing until the package is renewed.
 *   5. Unlimited-branch packages: slots change nothing (nothing to widen).
 *   6. Admin branch override keeps winning outright over package + slots.
 *   7. getEffectiveLimits() reports exactly what canAddBranch() enforces —
 *      the panel can never advertise capacity the gate refuses.
 *   8. Slots survive a product-line switch in storage but stop applying
 *      immediately, with no admin cleanup step required.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosExtraBranchSlotScopeTest.php --testdox
 */
class PosExtraBranchSlotScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // slotsColumnExists() memoises per process — an earlier test's schema
        // must not decide this one's answer.
        $cache = new \ReflectionProperty(BranchAddonService::class, 'slotsColumn');
        $cache->setAccessible(true);
        $cache->setValue(null, null);

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->integer('user_limit_override')->nullable();
            $table->integer('branch_limit_override')->nullable();
            $table->unsignedInteger('extra_branch_slots')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable()->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->integer('invoice_limit')->nullable();
            $table->integer('user_limit')->nullable();
            $table->integer('branch_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // getEffectiveLimits() also counts invoices and users.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** A company on a package, with `slots` extra-branch slots stored. */
    private function makeCompany(
        string $productType = 'pos',
        ?int $planBranches = 2,
        int $slots = 0,
        array $companyAttrs = [],
        array $planAttrs = [],
        array $subAttrs = []
    ): int {
        $companyId = (int) DB::table('companies')->insertGetId(array_merge([
            'name' => strtoupper($productType) . ' Co',
            'product_type' => $productType,
            'status' => 'active',
            'is_internal_account' => false,
            'extra_branch_slots' => $slots,
            'created_at' => now(),
            'updated_at' => now(),
        ], $companyAttrs));

        $planId = (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Pro',
            'product_type' => $productType,
            'is_trial' => false,
            'branch_limit' => $planBranches,
            'created_at' => now(),
            'updated_at' => now(),
        ], $planAttrs));

        DB::table('subscriptions')->insert(array_merge([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonths(3)->toDateString(),
            'end_date' => now()->addMonths(9)->toDateString(),
            'override_type' => 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ], $subAttrs));

        return $companyId;
    }

    private function addBranches(int $companyId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('branches')->insert([
                'company_id' => $companyId,
                'name' => 'Branch ' . ($i + 1),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Branch limit as REPORTED by the panel. */
    private function reportedLimit(int $companyId): int
    {
        return (int) PlanLimitService::getEffectiveLimits($companyId)['branch']['limit'];
    }

    /** Branch limit as ENFORCED by the gate: how many branches can exist. */
    private function enforcedLimit(int $companyId): int
    {
        $allowed = 0;
        while ($allowed < 50 && PlanLimitService::canAddBranch($companyId)['allowed']) {
            $this->addBranches($companyId, 1);
            $allowed++;
        }

        DB::table('branches')->where('company_id', $companyId)->delete();

        return $allowed;
    }

    // ── 1. PRA POS: slots do widen the limit ────────────────────────────────

    public function test_pra_pos_package_gets_included_branches_plus_purchased_slots(): void
    {
        $company = $this->makeCompany('pos', 2, 3);

        $this->assertSame(5, $this->reportedLimit($company), 'panel must advertise 2 included + 3 paid');
        $this->assertSame(5, $this->enforcedLimit($company), 'gate must open for 2 included + 3 paid');
    }

    public function test_pra_pos_gate_closes_once_included_and_paid_branches_are_used(): void
    {
        $company = $this->makeCompany('pos', 2, 1);
        $this->addBranches($company, 3);

        $verdict = PlanLimitService::canAddBranch($company);

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(2, $verdict['included']);
        $this->assertSame(1, $verdict['slots']);
        $this->assertStringContainsString('3/3', $verdict['reason']);
        $this->assertStringContainsString('Buy an extra branch', $verdict['reason']);
    }

    // ── 2. Other product lines ──────────────────────────────────────────────

    /**
     * 23 Aug 2026: FBR POS sells the SAME paid extra branch (Rs 10,000/year) as
     * PRA POS, so its slots must count — panel and gate alike. DI and a company
     * with no product line still get nothing.
     */
    public function test_fbr_pos_company_slots_extend_the_branch_limit(): void
    {
        $withSlots = $this->makeCompany('fbrpos', 2, 4);
        $without = $this->makeCompany('fbrpos', 2, 0);

        $this->assertSame(6, $this->reportedLimit($withSlots), 'panel must advertise 2 included + 4 paid');
        $this->assertSame(6, $this->enforcedLimit($withSlots), 'gate must open for 2 included + 4 paid');
        $this->assertSame(2, $this->reportedLimit($without), 'no slots = package limit only');
        $this->assertSame(4, PlanLimitService::getEffectiveLimits($withSlots)['branch']['extra_slots']);
    }

    public function test_di_company_slots_do_not_change_the_branch_limit(): void
    {
        $company = $this->makeCompany('di', 1, 5);

        $this->assertSame(1, $this->reportedLimit($company));
        $this->assertSame(1, $this->enforcedLimit($company));
    }

    public function test_company_with_no_product_type_slots_do_not_change_the_branch_limit(): void
    {
        $company = $this->makeCompany('di', 1, 5, ['product_type' => null], ['product_type' => null]);

        $this->assertSame(1, $this->reportedLimit($company));
        $this->assertSame(1, $this->enforcedLimit($company));
    }

    public function test_slots_stop_applying_the_moment_a_company_switches_product_line(): void
    {
        $company = $this->makeCompany('pos', 2, 2);
        $this->assertSame(4, $this->enforcedLimit($company), 'PRA POS: 2 included + 2 paid');

        // Plan swapped to a line that does NOT sell the add-on (DI); the stored
        // slot count is untouched but must stop counting, with no cleanup step.
        DB::table('pricing_plans')->update(['product_type' => 'di']);

        $this->assertSame(2, $this->reportedLimit($company), 'slots must stop applying with no cleanup step');
        $this->assertSame(2, $this->enforcedLimit($company));
        $this->assertSame(2, (int) DB::table('companies')->where('id', $company)->value('extra_branch_slots'));
    }

    // ── 3-5. Trial, expired, unlimited ──────────────────────────────────────

    public function test_trial_package_slots_do_not_change_the_branch_limit(): void
    {
        $company = $this->makeCompany('pos', 1, 3, [], ['name' => 'Trial', 'is_trial' => true]);

        $this->assertSame(1, $this->reportedLimit($company));
        $this->assertSame(1, $this->enforcedLimit($company));
    }

    public function test_expired_package_slots_do_not_change_the_branch_limit(): void
    {
        $company = $this->makeCompany('pos', 2, 3, [], [], [
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->assertSame(2, $this->reportedLimit($company));
        $this->assertSame(2, $this->enforcedLimit($company));
    }

    public function test_unlimited_branch_package_is_unaffected_by_slots(): void
    {
        $company = $this->makeCompany('pos', -1, 3);

        $this->assertSame(-1, $this->reportedLimit($company));
        $this->assertSame(0, PlanLimitService::getEffectiveLimits($company)['branch']['extra_slots']);
        $this->assertTrue(PlanLimitService::canAddBranch($company)['allowed']);
    }

    /**
     * (Task 1441) The two halves of the service must AGREE: whatever
     * applicableSlots() treats as inert (unlimited / no-limit package) is never
     * billed. Before this fix addonForCycle() still multiplied the stored slots
     * by 10,000 on an unlimited package, charging the shop for capacity the
     * package already gave it for free.
     */
    public function test_slots_that_widen_nothing_are_also_billed_for_nothing(): void
    {
        foreach ([null, -1] as $unlimited) {
            $company = \App\Models\Company::find($this->makeCompany('pos', $unlimited, 3));
            $plan = BranchAddonService::activeSubscription($company)?->pricingPlan;

            // Enforcement half: these slots widen no limit.
            $this->assertSame(0, BranchAddonService::applicableSlots($company));
            // Pricing half must agree: they are billed for nothing.
            $this->assertSame(0, BranchAddonService::billableSlots($company, $plan));
            $this->assertSame(0.0, BranchAddonService::addonForCycle($company, $plan, 'annual'));
        }
    }

    // ── 6-7. Override precedence + report/enforce agreement ─────────────────

    public function test_admin_branch_override_still_wins_over_package_plus_slots(): void
    {
        $company = $this->makeCompany('pos', 2, 5, ['branch_limit_override' => 3]);

        $this->assertSame(3, $this->reportedLimit($company), 'override wins the reported limit');
        $this->assertSame(3, $this->enforcedLimit($company), 'override wins the gate');
    }

    public function test_reported_limit_always_matches_the_enforced_limit(): void
    {
        $cases = [
            ['pos', 2, 3],
            ['pos', 1, 0],
            ['fbrpos', 2, 4],
            ['di', 1, 5],
        ];

        foreach ($cases as [$product, $planBranches, $slots]) {
            $company = $this->makeCompany($product, $planBranches, $slots);

            $this->assertSame(
                $this->reportedLimit($company),
                $this->enforcedLimit($company),
                "{$product} plan={$planBranches} slots={$slots}: panel and gate must agree"
            );
        }
    }

    // ── 8. Admin manual control is scoped the same way ──────────────────────

    public function test_only_pos_line_companies_may_be_given_slots_by_an_admin(): void
    {
        $pos = \App\Models\Company::find($this->makeCompany('pos', 2, 0));
        $fbr = \App\Models\Company::find($this->makeCompany('fbrpos', 2, 0));
        $di = \App\Models\Company::find($this->makeCompany('di', 2, 0));
        $trial = \App\Models\Company::find($this->makeCompany('pos', 1, 0, [], ['is_trial' => true]));
        $unlimited = \App\Models\Company::find($this->makeCompany('pos', -1, 0));

        $this->assertTrue(BranchAddonService::supportsCompany($pos));
        $this->assertTrue(BranchAddonService::supportsCompany($fbr), 'FBR POS sells the same paid branch (23 Aug 2026)');
        $this->assertFalse(BranchAddonService::supportsCompany($di));
        $this->assertFalse(BranchAddonService::supportsCompany($trial));
        $this->assertFalse(BranchAddonService::supportsCompany($unlimited));
        $this->assertFalse(BranchAddonService::supportsCompany(null));
    }
}
