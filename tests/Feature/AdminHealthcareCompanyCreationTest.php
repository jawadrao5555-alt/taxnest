<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthModuleService;
use App\Support\HealthPanel;
use App\Support\NestErps;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AdminHealthcareCompanyCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The native MODIFY in the existing July migration is skipped by SQLite.
        Schema::table('companies', fn (Blueprint $table) => $table->string('ntn')->nullable()->change());
    }

    public function test_admin_can_create_a_healthcare_trial_without_a_package_or_optional_fields(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->admin(), 'admin');

        $response = $this->post('/admin/companies', $this->companyPayload('Legacy Clinic', 'clinic', [
            'product_type' => 'health',
        ]));

        $company = Company::where('name', 'Legacy Clinic')->sole();
        $response->assertRedirect(route('saas.admin.companies.show', $company->id));
        $this->assertSame(NestErps::PRODUCT_TYPE, $company->product_type);
        $this->assertSame(NestErps::HEALTH, $company->erps_vertical);
        $this->assertSame(HealthPanel::DEFAULT_ORG_TYPE, $company->health_org_type);
        $this->assertSame(HealthModuleService::ORG_TYPE_DEFAULTS['clinic'], $company->health_modules);
        $this->assertFalse($company->fbr_reporting_enabled);
        $this->assertTrue(Subscription::where('company_id', $company->id)->sole()->trial_ends_at->isFuture());
    }

    public function test_admin_can_provision_separate_hospital_and_lab_company_owners(): void
    {
        $this->actingAs($this->admin(), 'admin');

        foreach (['hospital' => 'Synthetic Hospital', 'lab' => 'Synthetic Lab'] as $type => $name) {
            $payload = $this->companyPayload($name, $type, ['health_org_type' => $type]);
            $response = $this->post('/admin/companies', $payload);

            $company = Company::where('name', $name)->sole();
            $response->assertRedirect(route('saas.admin.companies.show', $company->id));
            $this->assertSame($type, $company->health_org_type);
            $this->assertSame(HealthModuleService::ORG_TYPE_DEFAULTS[$type], $company->health_modules);
            $owner = User::where('email', $payload['admin_email'])->sole();
            $this->assertSame($company->id, $owner->company_id);
            $this->assertSame('company_admin', $owner->role);
            $this->assertDatabaseHas('registered_credentials', [
                'company_id' => $company->id,
                'credential_type' => 'email',
                'credential_value' => $owner->email,
            ]);
        }
    }

    public function test_invalid_healthcare_organisation_type_is_rejected_before_provisioning(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->from('/admin/companies/create')
            ->post('/admin/companies', $this->companyPayload('Invalid School', 'invalid', ['health_org_type' => 'school']))
            ->assertRedirect('/admin/companies/create')
            ->assertSessionHasErrors('health_org_type');

        $this->assertDatabaseMissing('companies', ['name' => 'Invalid School']);
    }

    public function test_failed_provisioning_rolls_back_the_company_owner_trial_and_ledger(): void
    {
        $admin = $this->admin();
        $payload = $this->companyPayload('Incomplete Lab', 'incomplete', ['health_org_type' => 'lab']);
        AdminAuditLog::creating(function (AdminAuditLog $log): void {
            if ($log->action === 'Company created') {
                throw new RuntimeException('Simulated final provisioning write failure.');
            }
        });

        $this->actingAs($admin, 'admin')->post('/admin/companies', $payload)->assertServerError();

        $this->assertDatabaseMissing('companies', ['name' => $payload['name']]);
        $this->assertDatabaseMissing('users', ['email' => $payload['admin_email']]);
        $this->assertDatabaseMissing('registered_credentials', ['credential_value' => $payload['admin_email']]);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Synthetic platform admin',
            'email' => 'platform-admin@example.test',
            'password' => Hash::make('SyntheticSetup123!'),
            'role' => 'super_admin',
        ]);
    }

    private function companyPayload(string $name, string $key, array $extra = []): array
    {
        return array_replace([
            'name' => $name,
            'owner_name' => 'Synthetic owner',
            'product_type' => NestErps::PRODUCT_TYPE,
            'erps_vertical' => NestErps::HEALTH,
            'email' => $key . '-company@example.test',
            'status' => 'approved',
            'admin_name' => 'Synthetic company admin',
            'admin_email' => $key . '-admin@example.test',
            'admin_password' => 'SyntheticSetup123!',
        ], $extra);
    }
}
