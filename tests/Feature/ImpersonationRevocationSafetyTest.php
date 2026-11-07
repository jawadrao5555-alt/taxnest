<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Tests\TestCase;

class ImpersonationRevocationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function sessionFor(AdminUser $admin, Company $company, bool $readonly = false): array
    {
        return ['impersonation' => ['admin_id' => $admin->id, 'company_id' => $company->id,
            'company_name' => $company->name, 'guard' => 'web', 'readonly' => $readonly]];
    }

    private function fixtures(): array
    {
        Mail::fake(); Http::preventStrayRequests();
        $company = Company::create(['name' => 'Fictional impersonation tenant', 'ntn' => 'RCIMP1',
            'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active', 'onboarding_completed' => false]);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
        $admin = AdminUser::create(['name' => 'Fictional operator', 'email' => 'operator@example.test',
            'password' => 'SyntheticOnly123!', 'role' => 'super_admin']);
        $this->actingAs($admin, 'admin')->actingAs($owner, 'web');
        return [$admin, $company, $owner];
    }

    public function test_revoked_admin_role_cannot_continue_an_existing_manage_session(): void
    {
        [$admin, $company, $owner] = $this->fixtures();
        $ownerBefore = $owner->fresh()->getAttributes();
        AdminUser::whereKey($admin->id)->update(['role' => 'viewer']);
        $response = $this->withSession($this->sessionFor($admin, $company))->postJson('/onboarding/skip');
        $this->assertFalse((bool) $company->fresh()->onboarding_completed);
        $response->assertForbidden();
        $this->assertGuest('web');
        $this->assertNull(session('impersonation'));
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertSame($ownerBefore, $owner->fresh()->getAttributes());
    }

    public function test_another_admin_login_cannot_inherit_the_original_operators_session(): void
    {
        [$admin, $company] = $this->fixtures();
        $other = AdminUser::create(['name' => 'Other operator', 'email' => 'other@example.test',
            'password' => 'SyntheticOnly123!', 'role' => 'super_admin']);
        $this->actingAs($other, 'admin')->withSession($this->sessionFor($admin, $company))
            ->postJson('/onboarding/skip')->assertForbidden();
        $this->assertFalse((bool) $company->fresh()->onboarding_completed);
        $this->assertGuest('web');
    }

    public function test_a_swapped_panel_identity_cannot_write_under_the_old_company_audit_context(): void
    {
        [$admin, $company] = $this->fixtures();
        $other = Company::create(['name' => 'Other fictional tenant', 'ntn' => 'RCIMP2',
            'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active', 'onboarding_completed' => false]);
        $user = User::factory()->create(['company_id' => $other->id, 'role' => 'company_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession($this->sessionFor($admin, $company))
            ->postJson('/onboarding/skip')->assertForbidden();
        $this->assertFalse((bool) $other->fresh()->onboarding_completed);
        $this->assertFalse((bool) $company->fresh()->onboarding_completed);
        $this->assertGuest('web');
    }

    public function test_valid_manage_mode_keeps_its_existing_write_and_audit_path(): void
    {
        [$admin, $company] = $this->fixtures();
        $this->withSession($this->sessionFor($admin, $company))->post('/onboarding/skip')->assertRedirect('/dashboard');
        $this->assertTrue((bool) $company->fresh()->onboarding_completed);
        $this->assertDatabaseHas('admin_audit_logs', ['admin_id' => $admin->id, 'target_id' => $company->id,
            'action' => 'Full-access change while impersonating']);
    }

    public function test_disabled_company_user_cannot_continue_an_existing_manage_session(): void
    {
        [$admin, $company, $owner] = $this->fixtures();
        User::whereKey($owner->id)->update(['is_active' => false]);
        $this->withSession($this->sessionFor($admin, $company))->postJson('/onboarding/skip')->assertForbidden();
        $this->assertFalse((bool) $company->fresh()->onboarding_completed);
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_readonly_still_blocks_writes_and_exit_remains_available_after_revocation(): void
    {
        [$admin, $company] = $this->fixtures();
        $this->withSession($this->sessionFor($admin, $company, true))->postJson('/onboarding/skip')->assertForbidden();
        $this->assertFalse((bool) $company->fresh()->onboarding_completed);
        AdminUser::whereKey($admin->id)->update(['role' => 'viewer']);
        $this->post('/admin/impersonation/stop')->assertRedirect();
        $this->assertGuest('web');
        $this->assertNull(session('impersonation'));
    }
}
