<?php

namespace Tests\Feature\LiveOps;

use App\Models\AdminUser;
use App\Models\User;
use App\Services\LiveOps\LiveOpsDiagnosticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Live Ops authorization boundaries must match existing TaxNest architecture:
 * - SaaS AdminUser super_admin → global NestPOS Live Ops (like Live Activity)
 * - Non-super AdminUser → 403
 * - POS company_admin / pos_manager / viewer (users table) → cannot use /admin/live-ops
 * - No multi-company staff pivot exists; one users.company_id only
 * - Diagnostics remain company-scoped (no cross-tenant leakage)
 */
class LiveOpsAuthorizationTest extends LiveOpsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Company panel users table (separate from admin_users).
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('employee');
            $table->string('pos_role')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    private function makeAdmin(string $email, string $role): AdminUser
    {
        $id = DB::table('admin_users')->insertGetId([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('Secret#12345'),
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AdminUser::findOrFail($id);
    }

    private function makePosUser(int $companyId, string $email, string $role, ?string $posRole = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('Secret#12345'),
            'role' => $role,
            'pos_role' => $posRole,
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    /** A) Super Admin may diagnose any NestPOS company (cross-company SaaS access). */
    public function test_a_super_admin_can_access_another_company(): void
    {
        $a = $this->makeCompany('Alpha Auth Co');
        $b = $this->makeCompany('Beta Auth Co');
        $admin = $this->makeAdmin('sa@taxnest.test', 'super_admin');

        $this->actingAs($admin, 'admin')->get('/admin/live-ops/company/'.$b)
            ->assertOk()
            ->assertSee('Beta Auth Co')
            ->assertDontSee('Alpha Auth Co');

        $report = app(LiveOpsDiagnosticsService::class)->run('COMPANY_HEALTH', [
            'company_id' => $a,
            'requester' => 'sa',
            'admin_id' => $admin->id,
        ]);
        $this->assertSame($a, $report['data']['company']['id']);
    }

    /**
     * B) Manager on authorized company — existing architecture: POS managers
     * authenticate on users/pos guard and cannot open SaaS /admin/live-ops at all.
     * (They manage their shop via /pos/*, not Live Ops.)
     */
    public function test_b_pos_manager_authorized_company_cannot_use_saas_live_ops(): void
    {
        $companyId = $this->makeCompany('Manager Shop');
        $manager = $this->makePosUser($companyId, 'mgr@shop.test', 'employee', 'pos_manager');

        // No admin session → admin.auth redirects to login (not Live Ops).
        $this->actingAs($manager, 'pos')->get('/admin/live-ops')
            ->assertRedirect('/admin/login');
        $this->actingAs($manager, 'web')->get('/admin/live-ops/company/'.$companyId)
            ->assertRedirect('/admin/login');
    }

    /** C) Manager cannot reach Live Ops for an unauthorized (other) company either. */
    public function test_c_pos_manager_unauthorized_company_denied(): void
    {
        $own = $this->makeCompany('Own Shop');
        $other = $this->makeCompany('Other Shop');
        $manager = $this->makePosUser($own, 'mgr2@shop.test', 'employee', 'pos_manager');

        $this->actingAs($manager, 'pos')->get('/admin/live-ops/company/'.$other)
            ->assertRedirect('/admin/login');
        $this->actingAs($manager, 'pos')->post('/admin/live-ops/propose', [
            'action' => 'REFRESH_OPERATIONAL_STATE',
            'company_id' => $other,
        ])->assertRedirect('/admin/login');
    }

    /**
     * D) Multi-company: TaxNest has no users↔companies staff pivot.
     * A company_admin is bound to a single company_id; they still cannot use
     * SaaS Live Ops. Prove two different company admins remain denied and
     * diagnostics stay isolated when run by super_admin.
     */
    public function test_d_no_multi_company_staff_pivot_and_admins_stay_isolated(): void
    {
        $c1 = $this->makeCompany('Multi A');
        $c2 = $this->makeCompany('Multi B');
        $admin1 = $this->makePosUser($c1, 'a1@shop.test', 'company_admin', 'pos_admin');
        $admin2 = $this->makePosUser($c2, 'a2@shop.test', 'company_admin', 'pos_admin');

        $this->assertSame($c1, (int) $admin1->company_id);
        $this->assertSame($c2, (int) $admin2->company_id);
        $this->assertFalse(Schema::hasTable('company_user'));
        $this->assertFalse(Schema::hasTable('company_users'));
        $this->assertFalse(Schema::hasTable('managed_companies'));

        $this->actingAs($admin1, 'web')->get('/admin/live-ops')->assertRedirect('/admin/login');
        $this->actingAs($admin2, 'web')->get('/admin/live-ops')->assertRedirect('/admin/login');

        // Super admin can operate on each authorized NestPOS company independently.
        $sa = $this->makeAdmin('multi-sa@taxnest.test', 'super_admin');
        $this->actingAs($sa, 'admin')->get('/admin/live-ops/company/'.$c1)->assertOk()->assertSee('Multi A');
        $this->actingAs($sa, 'admin')->get('/admin/live-ops/company/'.$c2)->assertOk()->assertSee('Multi B');
    }

    /** E) Viewer / ordinary company user denied Live Ops management. */
    public function test_e_viewer_and_ordinary_user_denied_live_ops(): void
    {
        $companyId = $this->makeCompany('Viewer Shop');
        $viewer = $this->makePosUser($companyId, 'viewer@shop.test', 'viewer', null);
        $cashier = $this->makePosUser($companyId, 'cash@shop.test', 'employee', 'pos_cashier');
        $archive = $this->makePosUser($companyId, 'arch@shop.test', 'employee', 'archive_viewer');

        foreach ([$viewer, $cashier, $archive] as $user) {
            $this->actingAs($user, 'web')->get('/admin/live-ops')->assertRedirect('/admin/login');
            $this->actingAs($user, 'pos')->post('/admin/live-ops/propose', [
                'action' => 'ENQUEUE_TEST_PRINT',
                'company_id' => $companyId,
            ])->assertRedirect('/admin/login');
        }

        // Non-super SaaS admin roles also denied (support/viewer admin).
        $support = $this->makeAdmin('support@taxnest.test', 'support');
        $adminViewer = $this->makeAdmin('adminviewer@taxnest.test', 'viewer');
        $this->actingAs($support, 'admin')->get('/admin/live-ops')->assertStatus(403);
        $this->actingAs($adminViewer, 'admin')->get('/admin/live-ops')->assertStatus(403);
        $this->actingAs($support, 'admin')->post('/admin/live-ops/propose', [
            'action' => 'REFRESH_OPERATIONAL_STATE',
            'company_id' => $companyId,
        ])->assertStatus(403);
    }

    /** F) Cross-company data leakage prevention in diagnostics + search. */
    public function test_f_cross_company_leakage_prevention(): void
    {
        $a = $this->makeCompany('Leak Alpha', ['ntn' => '1111111-1']);
        $b = $this->makeCompany('Leak Beta', ['ntn' => '2222222-2']);
        $this->makeTxn($b, [
            'pra_status' => 'failed',
            'pra_invoice_number' => null,
            'pra_error_message' => 'BETA-ONLY-SECRET-ERROR',
            'total_amount' => 7777,
        ]);

        $sa = $this->makeAdmin('leak-sa@taxnest.test', 'super_admin');
        $this->actingAs($sa, 'admin')
            ->get('/admin/live-ops?search=Leak+Alpha')
            ->assertOk()
            ->assertSee('Leak Alpha')
            ->assertDontSee('Leak Beta');

        $report = app(LiveOpsDiagnosticsService::class)->run('COMPANY_DIAGNOSTIC', [
            'company_id' => $a,
            'requester' => 'sa',
        ]);
        $json = json_encode($report);
        $this->assertStringNotContainsString('Leak Beta', $json);
        $this->assertStringNotContainsString('BETA-ONLY-SECRET-ERROR', $json);
        $this->assertStringNotContainsString('7777', $json);
    }
}
