<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /demo-login/{role} must never be a privileged or production-reachable
 * authenticator. It is a local-development shortcut only, and even then it
 * may only sign in a seeded COMPANY user — never a platform super_admin.
 */
class DemoLoginFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemoAccounts(): array
    {
        $company = Company::create(['name' => 'Demo Co', 'ntn' => 'DEMO-' . uniqid(), 'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active']);
        $companyAdmin = User::factory()->create([
            'email' => 'company_admin@test.com',
            'role' => 'company_admin',
            'company_id' => $company->id,
        ]);
        $platformUser = User::factory()->create([
            'email' => 'admin@test.com',
            'role' => 'super_admin',
            'company_id' => null,
        ]);

        return [$company, $companyAdmin, $platformUser];
    }

    private function setEnvironment(string $env): void
    {
        $this->app->detectEnvironment(fn () => $env);
    }

    public function test_production_like_environment_never_exposes_demo_login(): void
    {
        $this->seedDemoAccounts();
        $this->setEnvironment('production');
        config(['app.demo_login_enabled' => true]);

        foreach (['company_admin', 'demo', 'super_admin', 'anything'] as $role) {
            $this->getJson("/demo-login/{$role}")->assertNotFound();
            $this->assertGuest('web');

            // Browser (HTML) requests: the app renders 404 as a redirect to the
            // public landing page — still no session may be established.
            $html = $this->get("/demo-login/{$role}");
            $this->assertContains($html->getStatusCode(), [302, 404]);
            $this->assertGuest('web');
        }
    }

    public function test_testing_environment_is_closed_by_default(): void
    {
        $this->seedDemoAccounts();
        $this->assertFalse(AuthenticatedSessionController::demoLoginEnabled());

        $this->getJson('/demo-login/company_admin')->assertNotFound();
        $this->assertGuest('web');
    }

    public function test_local_environment_without_the_switch_is_closed(): void
    {
        $this->seedDemoAccounts();
        $this->setEnvironment('local');
        config(['app.demo_login_enabled' => false]);

        $this->getJson('/demo-login/company_admin')->assertNotFound();
        $this->assertGuest('web');
    }

    public function test_local_environment_with_the_switch_signs_in_only_the_seeded_company_user(): void
    {
        [$company, $companyAdmin] = $this->seedDemoAccounts();
        $this->setEnvironment('local');
        config(['app.demo_login_enabled' => true]);

        $response = $this->get('/demo-login/company_admin');

        $response->assertRedirect();
        $this->assertAuthenticatedAs($companyAdmin, 'web');
        $this->assertSame($company->id, auth('web')->user()->company_id);
    }

    public function test_super_admin_role_is_never_offered_even_when_enabled(): void
    {
        $this->seedDemoAccounts();
        $this->setEnvironment('local');
        config(['app.demo_login_enabled' => true]);

        $this->getJson('/demo-login/super_admin')->assertNotFound();
        $this->assertGuest('web');
        $this->assertArrayNotHasKey('super_admin', AuthenticatedSessionController::DEMO_ROLES);
    }

    public function test_a_seeded_demo_account_that_was_promoted_to_super_admin_is_refused(): void
    {
        $this->seedDemoAccounts();
        User::where('email', 'company_admin@test.com')->update(['role' => 'super_admin', 'company_id' => null]);
        $this->setEnvironment('local');
        config(['app.demo_login_enabled' => true]);

        $this->getJson('/demo-login/company_admin')->assertNotFound();
        $this->assertGuest('web');
    }

    public function test_demo_login_cannot_escalate_an_authenticated_company_user(): void
    {
        [, $companyAdmin] = $this->seedDemoAccounts();
        $other = Company::create(['name' => 'Demo Co', 'ntn' => 'DEMO-' . uniqid(), 'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active']);
        $employee = User::factory()->create(['role' => 'employee', 'company_id' => $other->id]);
        $this->setEnvironment('production');
        config(['app.demo_login_enabled' => true]);

        $this->actingAs($employee, 'web')->getJson('/demo-login/company_admin')->assertNotFound();

        $this->assertAuthenticatedAs($employee, 'web');
        $this->assertNotSame($companyAdmin->id, auth('web')->id());
    }
}
