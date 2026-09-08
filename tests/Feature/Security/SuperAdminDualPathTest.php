<?php

namespace Tests\Feature\Security;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pins the intentional separation between the two "super admin" identities:
 *
 *  - AdminUser (guard `admin`, table admin_users) — the SaaS platform operator.
 *    Reaches /admin/* and Live Ops; reaches tenant panels ONLY via impersonation.
 *  - User with users.role = 'super_admin' (guard `web`) — a legacy platform-level
 *    DI account with no company. It is NOT a SaaS admin: it can never satisfy
 *    the `admin` guard, never reach /admin/* or Live Ops.
 *
 * Neither identity may be minted through an unauthenticated path (see
 * DemoLoginFailClosedTest / SetupRoutesRemovedTest). These tests prove the
 * boundaries so a future change cannot silently bridge the two.
 */
class SuperAdminDualPathTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $ntn): Company
    {
        return Company::create([
            'name' => "Co {$ntn}",
            'ntn' => $ntn,
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
        ]);
    }

    private function saasAdmin(string $role = 'super_admin'): AdminUser
    {
        return AdminUser::create([
            'name' => 'SaaS Admin',
            'email' => "saas-{$role}@example.test",
            'password' => Hash::make('Passw0rd!2026'),
            'role' => $role,
        ]);
    }

    public function test_the_two_identities_are_distinct_models_with_their_own_is_super_admin(): void
    {
        $admin = $this->saasAdmin();
        $legacy = User::factory()->create(['role' => 'super_admin', 'company_id' => null]);
        $tenant = User::factory()->create(['role' => 'company_admin', 'company_id' => $this->company('T-1')->id]);

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertTrue($legacy->isSuperAdmin());
        $this->assertFalse($tenant->isSuperAdmin());
        $this->assertNotSame(get_class($admin), get_class($legacy));
    }

    public function test_legacy_web_super_admin_cannot_reach_saas_admin_or_live_ops(): void
    {
        $legacy = User::factory()->create(['role' => 'super_admin', 'company_id' => null]);

        $this->actingAs($legacy, 'web')->get('/admin/dashboard')->assertRedirect('/admin/login');
        $this->actingAs($legacy, 'web')->get('/admin/live-ops')->assertRedirect('/admin/login');
        $this->actingAs($legacy, 'web')->get('/admin/companies')->assertRedirect('/admin/login');

        $this->assertFalse(auth('admin')->check());
    }

    public function test_saas_admin_session_does_not_satisfy_tenant_guards(): void
    {
        $admin = $this->saasAdmin();

        $this->actingAs($admin, 'admin');

        $this->assertTrue(auth('admin')->check());
        $this->assertFalse(auth('web')->check());
        $this->assertFalse(auth('pos')->check());
        $this->assertFalse(auth('fbrpos')->check());
        $this->assertFalse(auth('health')->check());

        // DI dashboard (web guard) sends the SaaS admin to the DI login, not in.
        $this->get('/dashboard')->assertRedirect();
        $this->assertFalse(auth('web')->check());

        // POS dashboard (pos guard) likewise.
        $response = $this->get('/pos/dashboard');
        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
        $this->assertFalse(auth('pos')->check());
    }

    public function test_saas_admin_with_non_super_role_is_still_not_a_tenant_super_admin(): void
    {
        $support = $this->saasAdmin('admin');
        $this->assertFalse($support->isSuperAdmin());

        $this->actingAs($support, 'admin')->get('/dashboard')->assertRedirect();
        $this->assertFalse(auth('web')->check());
    }

    public function test_company_admin_cannot_read_another_companys_invoice(): void
    {
        $a = $this->company('A-100');
        $b = $this->company('B-200');
        $adminB = User::factory()->create(['role' => 'company_admin', 'company_id' => $b->id, 'is_active' => true]);

        $invoiceA = Invoice::create([
            'company_id' => $a->id,
            'invoice_number' => 'INV-A-1',
            'buyer_name' => 'Buyer A',
            'status' => 'draft',
            'total_amount' => 100,
        ]);

        // Cross-company read: CompanyScope hides the row (404) or the explicit
        // company check refuses it (403) — the invoice is never rendered.
        $response = $this->actingAs($adminB, 'web')->getJson("/invoice/{$invoiceA->id}");
        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('Buyer A', $response->getContent());

        $ownInvoice = Invoice::create([
            'company_id' => $b->id,
            'invoice_number' => 'INV-B-1',
            'buyer_name' => 'Buyer B',
            'status' => 'draft',
            'total_amount' => 50,
        ]);
        $this->actingAs($adminB, 'web')->get("/invoice/{$ownInvoice->id}")->assertOk();
    }

    public function test_role_string_on_a_tenant_user_cannot_be_used_to_reach_admin_routes(): void
    {
        // A tenant user whose role column is tampered to super_admin (e.g. via a
        // bad seeder or manual DB edit) still cannot cross into the SaaS panel.
        $tampered = User::factory()->create(['role' => 'super_admin', 'company_id' => $this->company('X-9')->id]);

        $this->actingAs($tampered, 'web')->get('/admin/dashboard')->assertRedirect('/admin/login');
        $this->actingAs($tampered, 'web')->post('/admin/live-ops/diagnose', ['company_id' => 1])->assertRedirect('/admin/login');
    }
}
