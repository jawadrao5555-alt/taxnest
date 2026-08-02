<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Services\DiFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 135: DI Premium tier + feature-gate foundation.
 *
 * Locks the gate semantics every later premium task (white-label, public
 * API, AI reader, recurring invoices) builds on:
 *   - the four gate KEYS exist and never change silently;
 *   - Premium opens all four; Business/Industrial/Enterprise only
 *     recurring_invoices; Retail nothing;
 *   - override grants follow their GRANTED plan (DI rule — unlike POS,
 *     an override does NOT unlock everything);
 *   - bare carrier rows (override without a plan) open no premium gates;
 *   - active trial evaluates everything, expired trial/subscription nothing;
 *   - POS / FBR POS companies can never pass DI gates.
 */
class DiPremiumFeatureGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // planAllows caches per company id statically — ids restart at 1 after
        // dropAllTables, so a stale cache would leak between tests.
        DiFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('di');
            $t->boolean('is_internal_account')->default(false);
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('di');
            $t->boolean('is_trial')->default(false);
            $t->integer('invoice_limit')->default(-1);
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

        // hasAccess counts invoices for trial caps (DI billableCount).
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->softDeletes();
            $t->timestamps();
        });
    }

    private function makeCompany(?array $planAttrs, array $subAttrs = [], array $companyAttrs = []): Company
    {
        $companyId = DB::table('companies')->insertGetId(array_merge([
            'name' => 'Test Co',
            'product_type' => 'di',
            'created_at' => now(),
            'updated_at' => now(),
        ], $companyAttrs));

        $planId = null;
        if ($planAttrs !== null) {
            $planId = DB::table('pricing_plans')->insertGetId(array_merge([
                'name' => 'Retail',
                'product_type' => 'di',
                'created_at' => now(),
                'updated_at' => now(),
            ], $planAttrs));
        }

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

    public function test_gate_keys_are_locked_for_later_tasks(): void
    {
        $this->assertEqualsCanonicalizing(
            ['white_label', 'public_api', 'ai_reader', 'recurring_invoices'],
            DiFeatureService::GATES
        );
    }

    public function test_premium_plan_opens_all_four_gates(): void
    {
        $company = $this->makeCompany(['name' => 'Premium']);
        foreach (DiFeatureService::GATES as $gate) {
            $this->assertTrue(DiFeatureService::planAllows($company, $gate), "Premium should allow {$gate}");
        }
    }

    public function test_business_and_above_get_recurring_only(): void
    {
        foreach (['Business', 'Industrial', 'Enterprise'] as $name) {
            $company = $this->makeCompany(['name' => $name]);
            $this->assertTrue(DiFeatureService::planAllows($company, 'recurring_invoices'), "{$name} should allow recurring_invoices");
            $this->assertFalse(DiFeatureService::planAllows($company, 'white_label'), "{$name} must NOT allow white_label");
            $this->assertFalse(DiFeatureService::planAllows($company, 'public_api'), "{$name} must NOT allow public_api");
            $this->assertFalse(DiFeatureService::planAllows($company, 'ai_reader'), "{$name} must NOT allow ai_reader");
        }
    }

    public function test_retail_gets_no_premium_features(): void
    {
        $company = $this->makeCompany(['name' => 'Retail']);
        foreach (DiFeatureService::GATES as $gate) {
            $this->assertFalse(DiFeatureService::planAllows($company, $gate), "Retail must NOT allow {$gate}");
        }
    }

    public function test_override_grant_follows_its_granted_plan(): void
    {
        // Lifetime grant carrying a Premium plan -> full premium access.
        $premium = $this->makeCompany(['name' => 'Premium'], ['override_type' => 'lifetime', 'end_date' => null]);
        $this->assertTrue(DiFeatureService::planAllows($premium, 'white_label'));

        // Lifetime grant carrying a Business plan -> Business features only,
        // NOT everything (DI rule differs from POS here).
        $business = $this->makeCompany(['name' => 'Business'], ['override_type' => 'lifetime', 'end_date' => null]);
        $this->assertTrue(DiFeatureService::planAllows($business, 'recurring_invoices'));
        $this->assertFalse(DiFeatureService::planAllows($business, 'white_label'));
    }

    public function test_bare_override_carrier_row_opens_no_premium_gates(): void
    {
        // Active temporary grant with NO plan attached (payment-proof carrier):
        // the company can work, but gets no premium features.
        $company = $this->makeCompany(null, [
            'override_type' => 'temporary',
            'override_until' => now()->addDays(5),
            'override_granted_at' => now()->subDay(),
            'end_date' => null,
        ]);
        foreach (DiFeatureService::GATES as $gate) {
            $this->assertFalse(DiFeatureService::planAllows($company, $gate), "bare carrier must NOT allow {$gate}");
        }
    }

    public function test_expired_temporary_override_blocks(): void
    {
        $company = $this->makeCompany(null, [
            'override_type' => 'temporary',
            'override_until' => now()->subDay(),
            'end_date' => null,
        ]);
        $this->assertFalse(DiFeatureService::planAllows($company, 'white_label'));
    }

    public function test_active_trial_evaluates_everything(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Trial', 'is_trial' => true, 'invoice_limit' => 10],
            ['trial_ends_at' => now()->addDays(2), 'end_date' => null]
        );
        foreach (DiFeatureService::GATES as $gate) {
            $this->assertTrue(DiFeatureService::planAllows($company, $gate), "active trial should allow {$gate}");
        }
    }

    public function test_expired_trial_blocks(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Trial', 'is_trial' => true, 'invoice_limit' => 10],
            ['trial_ends_at' => now()->subDay(), 'end_date' => null]
        );
        $this->assertFalse(DiFeatureService::planAllows($company, 'recurring_invoices'));
    }

    public function test_expired_paid_subscription_loses_premium_features(): void
    {
        $company = $this->makeCompany(['name' => 'Premium'], ['end_date' => now()->subDay()->toDateString()]);
        $this->assertFalse(DiFeatureService::planAllows($company, 'white_label'));
    }

    public function test_pos_and_fbrpos_companies_never_pass_di_gates(): void
    {
        $pos = $this->makeCompany(['name' => 'Premium', 'product_type' => 'pos'], [], ['product_type' => 'pos']);
        $this->assertFalse(DiFeatureService::planAllows($pos, 'white_label'));

        $fbrpos = $this->makeCompany(['name' => 'Premium', 'product_type' => 'fbrpos'], [], ['product_type' => 'fbrpos']);
        $this->assertFalse(DiFeatureService::planAllows($fbrpos, 'public_api'));
    }

    public function test_internal_account_is_always_allowed(): void
    {
        $company = $this->makeCompany(['name' => 'Retail'], [], ['is_internal_account' => true]);
        $this->assertTrue(DiFeatureService::planAllows($company, 'white_label'));
    }

    public function test_no_subscription_blocks(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'No Sub Co',
            'product_type' => 'di',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $company = Company::findOrFail($companyId);
        $this->assertFalse(DiFeatureService::planAllows($company, 'recurring_invoices'));
    }

    public function test_unknown_key_is_not_gated(): void
    {
        $company = $this->makeCompany(['name' => 'Retail']);
        $this->assertTrue(DiFeatureService::planAllows($company, 'some_future_feature'));
    }

    public function test_plan_includes_is_product_type_scoped(): void
    {
        $di = new PricingPlan(['name' => 'Premium', 'product_type' => 'di']);
        $pos = new PricingPlan(['name' => 'Premium', 'product_type' => 'pos']);
        $this->assertTrue(DiFeatureService::planIncludes($di, 'white_label'));
        $this->assertFalse(DiFeatureService::planIncludes($pos, 'white_label'));
        $this->assertFalse(DiFeatureService::planIncludes(null, 'white_label'));
    }
}
