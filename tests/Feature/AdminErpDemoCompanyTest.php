<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthModuleService;
use App\Services\PlanSellabilityService;
use App\Support\HealthPanel;
use App\Support\NestErps;
use App\Support\SchoolErpPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminErpDemoCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_creates_retained_isolated_school_hospital_and_lab_demos(): void
    {
        $admin = $this->admin('super_admin');
        $this->actingAs($admin, 'admin')
            ->get(route('saas.admin.companies.create'))->assertOk()->assertSee('Create an ERP demo');

        foreach (['school', 'hospital', 'lab'] as $type) {
            $data = $this->payload($type);
            $response = $this->actingAs($admin, 'admin')->post(route('saas.admin.companies.store'), $data);
            $company = Company::where('ntn', 'ERP-DEMO-'.strtoupper($data['demo_request_key']))->sole();
            $owner = User::where('company_id', $company->id)->sole();
            $plan = Subscription::where('company_id', $company->id)->sole()->pricingPlan;
            $school = $type === 'school';
            $this->assertSame(NestErps::PRODUCT_TYPE, $company->product_type);
            $this->assertSame($school ? NestErps::SCHOOL : NestErps::HEALTH, $company->erps_vertical);
            $this->assertSame($school ? null : $type, $company->health_org_type);
            $this->assertStringEndsWith('@example.test', $owner->email);
            $this->assertTrue(Hash::check($data['password'], $owner->password));
            $this->assertFalse($company->fbr_reporting_enabled);
            $this->assertFalse($company->fbr_universal_enabled);
            $this->assertFalse($company->fbr_pos_enabled);
            $this->assertFalse($company->pra_reporting_enabled);
            $this->assertSame('0.00', $plan->price);
            $this->assertFalse($plan->is_public);
            $this->assertFalse($plan->erp_website_price_visible);
            $this->assertTrue(PlanSellabilityService::isRetired($plan));

            $loginPath = $school ? SchoolErpPanel::LOGIN_PATH : HealthPanel::LOGIN_PATH;
            $response->assertRedirect(route('saas.admin.companies.show', $company->id))
                ->assertSessionHas('success', fn ($value) => str_contains($value, "company #{$company->id}")
                    && str_contains($value, $loginPath) && str_contains($value, $owner->email)
                    && !str_contains($value, $data['password']));

            $retryPassword = Str::random(24);
            $this->post(route('saas.admin.companies.store'), array_replace($data, [
                'password' => $retryPassword, 'password_confirmation' => $retryPassword,
            ]))->assertRedirect(route('saas.admin.companies.show', $company->id));
            $this->assertSame(1, AdminAuditLog::where('action', 'ERP demo company created')
                ->where('target_id', $company->id)->count());
            $this->assertSame($owner->getAuthPassword(), $owner->fresh()->getAuthPassword());

            if ($school) {
                $this->post($loginPath, ['email' => $owner->email, 'password' => $data['password']])
                    ->assertRedirect(SchoolErpPanel::DASHBOARD_PATH);
                $this->assertAuthenticated(SchoolErpPanel::GUARD);
                $this->get(SchoolErpPanel::DASHBOARD_PATH)->assertOk()
                    ->assertSee('Demo Campus')->assertSee('Fictional student');
                $this->assertDatabaseHas('school_erp_enrollments', ['company_id' => $company->id]);
                $this->post('/school-erp/logout');
            } else {
                $this->post($loginPath, ['login' => $owner->email, 'password' => $data['password']])->assertRedirect();
                $this->assertAuthenticated(HealthPanel::GUARD);
                $this->assertSame(HealthModuleService::defaultsForOrgType($type), HealthModuleService::enabled($company));
                $this->get('/health/lab/catalogue')->assertOk()->assertSee('Demo service');
                $this->post('/health/logout');
            }
        }
    }

    public function test_demo_creation_requires_super_admin_and_validation_does_not_flash_password(): void
    {
        $create = route('saas.admin.companies.create');
        $store = route('saas.admin.companies.store');
        $this->actingAs($this->admin('admin'), 'admin')
            ->get($create)->assertOk()->assertDontSee('Create an ERP demo');
        $this->post($store, $this->payload('school'))->assertForbidden();

        $payload = $this->payload('school');
        $this->actingAs($this->admin('super_admin'), 'admin')->from($create)
            ->post($store, array_replace($payload, ['password_confirmation' => 'Mismatch']))
            ->assertRedirect($create)->assertSessionHasErrorsIn('erpDemo', ['password'])
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseMissing('companies', ['ntn' => 'ERP-DEMO-'.strtoupper($payload['demo_request_key'])]);
    }

    private function admin(string $role): AdminUser
    {
        return AdminUser::create([
            'name' => 'Test administrator',
            'email' => Str::uuid().'@example.test',
            'password' => Str::random(24),
            'role' => $role,
        ]);
    }

    private function payload(string $type): array
    {
        $password = Str::random(24);

        return [
            'create_erp_demo' => '1',
            'demo_type' => $type,
            'demo_request_key' => (string) Str::uuid(),
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }
}
