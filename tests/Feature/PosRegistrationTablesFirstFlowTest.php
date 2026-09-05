<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\PosAuthController;
use App\Http\Controllers\FbrPosAuthController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * REGISTRATION TABLES-FIRST FLOW GUARD (Task 868).
 *
 * Asserts that PosAuthController::register and FbrPosAuthController::register
 * do NOT write tables_first_flow => 0 for a restaurant signup.
 *
 * Background: companies.tables_first_flow defaults to 1 via migration
 * 2026_08_28_130000. New signups must inherit that default — no registration
 * path may write an explicit 0. This test catches any future developer who
 * accidentally adds tables_first_flow => 0 to the Company::create() payload
 * in either registration controller.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same pattern as PosTablesFirstFlowToggleTest).
 */
class PosRegistrationTablesFirstFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // companies — all columns written by both registration controllers,
        // plus tables_first_flow with its mandatory DEFAULT 1.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('ntn')->nullable()->unique();
            $table->string('cnic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company_status')->nullable();
            $table->string('status')->nullable();
            $table->string('product_type')->nullable();
            $table->string('pos_type')->nullable();
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_environment')->nullable();
            $table->string('pos_integration_mode')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_pos_environment')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            // The invariant under test: DEFAULT 1 set by migration 2026_08_28_130000.
            $table->boolean('tables_first_flow')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('final_price', 10, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        Schema::create('registered_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('credential_type');
            $table->string('credential_value', 191);
            $table->string('product_type')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['credential_type', 'credential_value']);
        });

        // One non-trial POS plan, required by PosAuthController::register validation.
        DB::table('pricing_plans')->insert([
            'id'           => 1,
            'name'         => 'Starter',
            'product_type' => 'pos',
            'is_trial'     => false,
            'price'        => 9999,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * Build a POST Request and wire it to the Laravel session store so that
     * validation and Auth::guard->login work when called from a controller
     * directly (no HTTP kernel dispatch).
     */
    private function makeRequest(string $uri, array $data): Request
    {
        $request = Request::create($uri, 'POST', $data);
        $request->setLaravelSession(app('session.store'));
        return $request;
    }

    // ─── POS registration ─────────────────────────────────────────────────────

    /**
     * PosAuthController::register with pos_type=restaurant must produce a
     * company row whose tables_first_flow is 1 (the column default).
     *
     * If a developer adds `tables_first_flow => 0` to the Company::create()
     * payload in that controller, this test will fail.
     */
    public function test_pos_restaurant_signup_has_tables_first_flow_on(): void
    {
        $request = $this->makeRequest('/pos/register', [
            'company_name'          => 'Test Cafe',
            'company_ntn'           => null,
            'company_cnic'          => null,
            'name'                  => 'Owner Name',
            'email'                 => 'owner@postest.com',
            'phone'                 => '03001234567',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'pos_type'              => 'restaurant',
            'pricing_plan_id'       => 1,
        ]);

        // Exceptions after Company::create (e.g. Auth guard needing a real
        // session driver) are silenced — the Company row is written before
        // any of those calls, so the assertion is still valid.
        try {
            app(PosAuthController::class)->register($request);
        } catch (\Throwable) {
            // intentional
        }

        $company = DB::table('companies')->where('email', 'owner@postest.com')->first();

        $this->assertNotNull(
            $company,
            'POS registration must create a company row.'
        );
        $this->assertSame(
            1,
            (int) $company->tables_first_flow,
            'POS restaurant signup must NOT write tables_first_flow = 0; ' .
            'new companies must inherit the column default of 1.'
        );
    }

    /**
     * Same assertion for a non-restaurant POS signup — the default must also be
     * 1 regardless of pos_type, because the flag is never explicitly written by
     * the registration path. (PRA taxes services only, so the non-restaurant
     * case here is a salon, not a retail shop: retail is an FBR business.)
     */
    public function test_pos_non_restaurant_signup_has_tables_first_flow_on(): void
    {
        $request = $this->makeRequest('/pos/register', [
            'company_name'          => 'Test Salon',
            'company_ntn'           => null,
            'company_cnic'          => null,
            'name'                  => 'Salon Owner',
            'email'                 => 'salon@postest.com',
            'phone'                 => '03001111111',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'pos_type'              => 'salon',
            'pricing_plan_id'       => 1,
        ]);

        try {
            app(PosAuthController::class)->register($request);
        } catch (\Throwable) {
            // intentional
        }

        $company = DB::table('companies')->where('email', 'salon@postest.com')->first();

        $this->assertNotNull($company, 'POS non-restaurant registration must create a company row.');
        $this->assertSame(
            1,
            (int) $company->tables_first_flow,
            'POS non-restaurant signup must NOT write tables_first_flow = 0.'
        );
    }

    // ─── FBR POS registration ────────────────────────────────────────────────

    /**
     * FbrPosAuthController::register with pos_type=restaurant must produce a
     * company row whose tables_first_flow is 1.
     */
    public function test_fbr_pos_restaurant_signup_has_tables_first_flow_on(): void
    {
        $request = $this->makeRequest('/fbr-pos/register', [
            'company_name'          => 'FBR Cafe',
            'company_ntn'           => '1234567',   // required by FBR POS validator
            'company_cnic'          => null,
            'name'                  => 'FBR Owner',
            'email'                 => 'owner@fbrtest.com',
            'phone'                 => '03009876543',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'pos_type'              => 'restaurant',
        ]);

        try {
            app(FbrPosAuthController::class)->register($request);
        } catch (\Throwable) {
            // intentional
        }

        $company = DB::table('companies')->where('email', 'owner@fbrtest.com')->first();

        $this->assertNotNull(
            $company,
            'FBR POS registration must create a company row.'
        );
        $this->assertSame(
            1,
            (int) $company->tables_first_flow,
            'FBR POS restaurant signup must NOT write tables_first_flow = 0; ' .
            'new companies must inherit the column default of 1.'
        );
    }

    /**
     * Same assertion for a non-restaurant FBR POS signup.
     */
    public function test_fbr_pos_retail_signup_has_tables_first_flow_on(): void
    {
        $request = $this->makeRequest('/fbr-pos/register', [
            'company_name'          => 'FBR Retail',
            'company_ntn'           => '9876543',
            'company_cnic'          => null,
            'name'                  => 'FBR Retail Owner',
            'email'                 => 'retail@fbrtest.com',
            'phone'                 => '03002222222',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'pos_type'              => 'retail',
        ]);

        try {
            app(FbrPosAuthController::class)->register($request);
        } catch (\Throwable) {
            // intentional
        }

        $company = DB::table('companies')->where('email', 'retail@fbrtest.com')->first();

        $this->assertNotNull($company, 'FBR POS retail registration must create a company row.');
        $this->assertSame(
            1,
            (int) $company->tables_first_flow,
            'FBR POS retail signup must NOT write tables_first_flow = 0.'
        );
    }
}
