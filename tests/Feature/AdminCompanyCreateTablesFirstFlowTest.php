<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\SaasAdmin\AdminCompanyController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADMIN COMPANY CREATION — TABLES-FIRST FLOW GUARD (Task 879).
 *
 * Asserts that AdminCompanyController::store() does NOT write
 * tables_first_flow => 0 for a newly-created company, regardless of
 * product_type (pos or fbrpos).
 *
 * Background: companies.tables_first_flow defaults to 1 via migration
 * 2026_08_28_130000. The admin create path must never include an explicit
 * 0 in the Company::create() payload. This test catches any future
 * developer who accidentally adds tables_first_flow => 0 to the
 * $companyData array in AdminCompanyController::store().
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same pattern as PosRegistrationTablesFirstFlowTest).
 */
class AdminCompanyCreateTablesFirstFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // companies — all columns written by AdminCompanyController::store(),
        // plus tables_first_flow with its mandatory DEFAULT 1.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('business_activity')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->string('product_type')->nullable();
            $table->decimal('standard_tax_rate', 8, 2)->default(16);
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_pos_environment')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_environment')->nullable();
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

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * Build a POST Request and wire it to the Laravel session store so that
     * validation works when the controller is called directly (no HTTP kernel).
     */
    private function makeRequest(string $uri, array $data): Request
    {
        $request = Request::create($uri, 'POST', $data);
        $request->setLaravelSession(app('session.store'));
        return $request;
    }

    /**
     * Minimal valid payload for AdminCompanyController::store().
     * franchise_id and agent_id are omitted (nullable) to avoid needing
     * those tables in the in-memory schema.
     */
    private function basePayload(string $productType, string $companyEmail, string $adminEmail): array
    {
        return [
            'name'           => 'Test Company',
            'owner_name'     => 'Test Owner',
            'product_type'   => $productType,
            'email'          => $companyEmail,
            'ntn'            => null,
            'cnic'           => null,
            'phone'          => '03001234567',
            'mobile'         => null,
            'address'        => null,
            'city'           => null,
            'province'       => null,
            'business_activity' => null,
            'website'        => null,
            'status'         => 'approved',
            'franchise_id'   => null,
            'agent_id'       => null,
            'admin_email'    => $adminEmail,
            'admin_password' => 'Secret123!',
            'admin_name'     => 'Admin User',
        ];
    }

    // ─── POS product type ─────────────────────────────────────────────────────

    /**
     * AdminCompanyController::store() with product_type=pos must produce a
     * company row whose tables_first_flow is 1 (the column default).
     *
     * If a developer adds `tables_first_flow => 0` to the $companyData array
     * in AdminCompanyController::store(), this test will fail.
     */
    public function test_admin_create_pos_company_has_tables_first_flow_on(): void
    {
        $request = $this->makeRequest('/saas-admin/companies', $this->basePayload('pos', 'pos@admintest.com', 'posadmin@admintest.com'));

        // Exceptions after Company::create (e.g. redirect, missing relations)
        // are silenced — the Company row is written before any of those calls,
        // so the assertion is still valid.
        try {
            app(AdminCompanyController::class)->store($request);
        } catch (\Throwable) {
            // intentional
        }

        $company = DB::table('companies')->where('email', 'pos@admintest.com')->first();

        $this->assertNotNull(
            $company,
            'Admin company creation (pos) must create a company row.'
        );
        $this->assertSame(
            1,
            (int) $company->tables_first_flow,
            'Admin-created POS company must NOT write tables_first_flow = 0; ' .
            'new companies must inherit the column default of 1.'
        );
    }

    // ─── FBR POS product type ─────────────────────────────────────────────────

    /**
     * AdminCompanyController::store() with product_type=fbrpos must also
     * produce a company row whose tables_first_flow is 1.
     */
    public function test_admin_create_fbrpos_company_has_tables_first_flow_on(): void
    {
        $request = $this->makeRequest('/saas-admin/companies', $this->basePayload('fbrpos', 'fbrpos@admintest.com', 'fbrposadmin@admintest.com'));

        try {
            app(AdminCompanyController::class)->store($request);
        } catch (\Throwable) {
            // intentional
        }

        $company = DB::table('companies')->where('email', 'fbrpos@admintest.com')->first();

        $this->assertNotNull(
            $company,
            'Admin company creation (fbrpos) must create a company row.'
        );
        $this->assertSame(
            1,
            (int) $company->tables_first_flow,
            'Admin-created FBR POS company must NOT write tables_first_flow = 0; ' .
            'new companies must inherit the column default of 1.'
        );
    }
}
