<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 117: Offline billing + Desktop App = Business+ plan gate
 * (pricing_plans.offline_enabled via PosFeatureService::planAllows).
 *
 * Locks the gate matrix all key-creation entry points rely on
 * (AgentManagementController::desktopConfig/generateKey/toggle,
 * PosController::praSettings fiscal_device):
 *   - Starter (no trial)      → blocked
 *   - Business+               → allowed
 *   - ACTIVE trial            → allowed (planAllows active-trial rule)
 *   - expired trial           → blocked
 *   - internal account        → allowed
 * The controllers additionally GRANDFATHER any company whose
 * agent_api_key already exists — that rule lives in the controllers
 * (gate check is skipped when a key is present), so the service must
 * only answer the plan question tested here.
 */
class OfflinePlanGateTest extends TestCase
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
            $t->boolean('is_internal_account')->default(false);
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('offline_enabled')->default(true);
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
    }

    private function makeCompany(array $planAttrs, array $subAttrs = [], array $companyAttrs = []): Company
    {
        $companyId = DB::table('companies')->insertGetId(array_merge([
            'name' => 'Test Shop',
            'product_type' => 'pos',
            'created_at' => now(),
            'updated_at' => now(),
        ], $companyAttrs));

        $planId = DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Starter',
            'product_type' => 'pos',
            'created_at' => now(),
            'updated_at' => now(),
        ], $planAttrs));

        DB::table('subscriptions')->insert(array_merge([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $subAttrs));

        return Company::findOrFail($companyId);
    }

    public function test_starter_without_trial_is_blocked(): void
    {
        $company = $this->makeCompany(['name' => 'Starter', 'offline_enabled' => false]);
        $this->assertFalse(PosFeatureService::planAllows($company, 'offline_enabled'));
    }

    public function test_business_plan_is_allowed(): void
    {
        $company = $this->makeCompany(['name' => 'Business', 'offline_enabled' => true]);
        $this->assertTrue(PosFeatureService::planAllows($company, 'offline_enabled'));
    }

    public function test_active_trial_is_allowed_even_with_gate_off(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Trial', 'is_trial' => true, 'offline_enabled' => false],
            ['trial_ends_at' => now()->addDays(5)]
        );
        $this->assertTrue(PosFeatureService::planAllows($company, 'offline_enabled'));
    }

    public function test_expired_trial_is_blocked(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Trial', 'is_trial' => true, 'offline_enabled' => false],
            ['trial_ends_at' => now()->subDay()]
        );
        $this->assertFalse(PosFeatureService::planAllows($company, 'offline_enabled'));
    }

    public function test_internal_account_is_always_allowed(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Starter', 'offline_enabled' => false],
            [],
            ['is_internal_account' => true]
        );
        $this->assertTrue(PosFeatureService::planAllows($company, 'offline_enabled'));
    }

    public function test_no_active_subscription_is_blocked(): void
    {
        $company = $this->makeCompany(['name' => 'Starter', 'offline_enabled' => false], ['active' => false]);
        $this->assertFalse(PosFeatureService::planAllows($company, 'offline_enabled'));
    }
}
