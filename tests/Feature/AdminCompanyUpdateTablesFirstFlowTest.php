<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\SaasAdmin\AdminCompanyController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADMIN COMPANY UPDATE — TABLES-FIRST FLOW GUARD (Task 922).
 *
 * Asserts that AdminCompanyController::update() does NOT overwrite
 * tables_first_flow when an admin edits an existing company, even if the
 * HTTP request body includes tables_first_flow = 0.
 *
 * Background: the update() method builds a dynamic $fields array at request
 * time. If a developer accidentally adds 'tables_first_flow' to that array,
 * the column would be silently reset to 0. This test catches that regression.
 *
 * Pattern mirrors AdminCompanyCreateTablesFirstFlowTest (Task 879): sqlite
 * :memory: schema + controller invoked directly, throwables caught because
 * the company row is written before the redirect.
 */
class AdminCompanyUpdateTablesFirstFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // companies — all columns referenced by update() validation and $fields,
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
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->decimal('standard_tax_rate', 8, 2)->default(16);
            $table->string('invoice_number_prefix')->nullable();
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            // PRA / FBR fields
            $table->string('pra_environment')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->string('fbr_environment')->nullable();
            $table->string('fbr_registration_no')->nullable();
            $table->string('fbr_business_name')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_pos_environment')->nullable();
            $table->string('fbr_pos_id')->nullable();
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
     * Build a PUT Request and wire it to the Laravel session store so that
     * validation works when the controller is called directly (no HTTP kernel).
     */
    private function makeRequest(string $uri, array $data): Request
    {
        $request = Request::create($uri, 'PUT', $data);
        $request->setLaravelSession(app('session.store'));
        return $request;
    }

    /**
     * Minimal valid payload for AdminCompanyController::update().
     * Sends tables_first_flow = 0 to simulate an attacker / accidental field
     * inclusion. cnic/franchise_id/agent_id omitted (nullable) to avoid
     * needing those tables in the in-memory schema.
     */
    private function basePayload(): array
    {
        return [
            'name'               => 'Updated Company Name',
            'owner_name'         => 'Updated Owner',
            'email'              => null,
            'ntn'                => null,
            'cnic'               => null,
            'phone'              => '03001234567',
            'mobile'             => null,
            'address'            => null,
            'city'               => null,
            'province'           => null,
            'business_activity'  => null,
            'website'            => null,
            'franchise_id'       => null,
            'agent_id'           => null,
            'standard_tax_rate'  => 16,
            'invoice_number_prefix' => null,
            // Intentionally sent in the request body — the guard must ignore it.
            'tables_first_flow'  => 0,
        ];
    }

    /**
     * Insert a company row with tables_first_flow = 1 and return its id.
     */
    private function seedCompany(string $productType, string $email): int
    {
        return DB::table('companies')->insertGetId([
            'name'               => 'Test Company',
            'owner_name'         => 'Test Owner',
            'product_type'       => $productType,
            'email'              => $email,
            'status'             => 'approved',
            'company_status'     => 'approved',
            'tables_first_flow'  => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    // ─── POS product type ─────────────────────────────────────────────────────

    /**
     * AdminCompanyController::update() for a POS company must NOT touch
     * tables_first_flow even when the request explicitly sends 0.
     *
     * If a developer adds 'tables_first_flow' to the $fields array in
     * AdminCompanyController::update(), this test will fail.
     */
    public function test_admin_update_pos_company_does_not_reset_tables_first_flow(): void
    {
        $id = $this->seedCompany('pos', 'pos-update@admintest.com');

        $uri     = "/saas-admin/companies/{$id}";
        $payload = array_merge($this->basePayload(), ['tables_first_flow' => 0]);
        $request = $this->makeRequest($uri, $payload);

        // Exceptions after $company->update() (redirect, missing relations)
        // are silenced — the row is written before any of those calls.
        try {
            app(AdminCompanyController::class)->update($request, $id);
        } catch (\Throwable) {
            // intentional
        }

        $company = DB::table('companies')->find($id);

        $this->assertNotNull(
            $company,
            'The company row must still exist after update() is called.'
        );
        $this->assertSame(
            1,
            (int) $company->tables_first_flow,
            'AdminCompanyController::update() must NOT overwrite tables_first_flow; ' .
            'the POS company row should still have tables_first_flow = 1 even when ' .
            'the request body sends tables_first_flow = 0.'
        );
    }

    // ─── FBR POS product type ─────────────────────────────────────────────────

    /**
     * AdminCompanyController::update() for an FBR POS company must NOT touch
     * tables_first_flow even when the request explicitly sends 0.
     */
    public function test_admin_update_fbrpos_company_does_not_reset_tables_first_flow(): void
    {
        $id = $this->seedCompany('fbrpos', 'fbrpos-update@admintest.com');

        $uri     = "/saas-admin/companies/{$id}";
        $payload = array_merge($this->basePayload(), ['tables_first_flow' => 0]);
        $request = $this->makeRequest($uri, $payload);

        try {
            app(AdminCompanyController::class)->update($request, $id);
        } catch (\Throwable) {
            // intentional
        }

        $company = DB::table('companies')->find($id);

        $this->assertNotNull(
            $company,
            'The FBR POS company row must still exist after update() is called.'
        );
        $this->assertSame(
            1,
            (int) $company->tables_first_flow,
            'AdminCompanyController::update() must NOT overwrite tables_first_flow; ' .
            'the FBR POS company row should still have tables_first_flow = 1 even when ' .
            'the request body sends tables_first_flow = 0.'
        );
    }
}
