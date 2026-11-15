<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthDoctor;
use App\Models\HealthPatient;
use App\Services\HealthModuleService;
use App\Support\HealthPanel;
use Database\Seeders\ErpDemoHospitalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpDemoHospitalSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Legacy migrations insert sample tenants. This test operates only in
        // the already-asserted SQLite :memory: database, never a saved DB.
        foreach (['health_patients', 'health_doctors', 'users', 'companies'] as $table) {
            \Illuminate\Support\Facades\DB::table($table)->delete();
        }
    }


    public function test_disposable_demo_has_all_modules_and_a_working_owner_login(): void
    {
        $password = bin2hex(random_bytes(16));
        $previous = getenv('ERP_DEMO_PASSWORD');
        putenv('ERP_DEMO_PASSWORD=' . $password);

        try {
            $this->seed(ErpDemoHospitalSeeder::class);
        } finally {
            putenv($previous === false ? 'ERP_DEMO_PASSWORD' : 'ERP_DEMO_PASSWORD=' . $previous);
        }

        $company = Company::where('name', 'TaxNest Demo General Hospital')->sole();
        HealthModuleService::forget($company->id);
        $this->assertSame(HealthModuleService::MODULES, HealthModuleService::enabled($company));
        $this->assertSame(1, HealthDoctor::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(1, HealthPatient::withoutGlobalScopes()->where('company_id', $company->id)->count());

        $this->post('/health/login', [
            'login' => 'erp.owner@example.test',
            'password' => $password,
        ])->assertRedirect();

        $this->assertAuthenticated(HealthPanel::GUARD);
        $this->get('/health/dashboard')->assertOk();
    }
    public function test_production_environment_is_rejected_before_any_write(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        try {
            (new ErpDemoHospitalSeeder)->run();
            $this->fail('Production demo seed must never run.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('local, disposable', $error->getMessage());
            $this->assertDatabaseCount('companies', 0);
            $this->assertDatabaseCount('users', 0);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_missing_local_password_fails_before_provisioning(): void
    {
        $previous = getenv('ERP_DEMO_PASSWORD');
        putenv('ERP_DEMO_PASSWORD');
        try {
            (new ErpDemoHospitalSeeder)->run();
            $this->fail('No implicit demo credential is allowed.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('ERP_DEMO_PASSWORD', $error->getMessage());
            $this->assertDatabaseCount('companies', 0);
        } finally {
            putenv($previous === false ? 'ERP_DEMO_PASSWORD' : 'ERP_DEMO_PASSWORD=' . $previous);
        }
    }

    public function test_existing_demo_cannot_be_seeded_twice_or_rewrite_history(): void
    {
        $previous = getenv('ERP_DEMO_PASSWORD');
        putenv('ERP_DEMO_PASSWORD=' . bin2hex(random_bytes(16)));
        try {
            $this->seed(ErpDemoHospitalSeeder::class);
            $company = Company::sole();
            $before = $company->getAttributes();
            try {
                (new ErpDemoHospitalSeeder)->run();
                $this->fail('Existing tenant data must reject the fixture.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('empty company', $error->getMessage());
                $this->assertSame($before, $company->fresh()->getAttributes());
                $this->assertDatabaseCount('companies', 1);
                $this->assertDatabaseCount('users', 1);
            }
        } finally {
            putenv($previous === false ? 'ERP_DEMO_PASSWORD' : 'ERP_DEMO_PASSWORD=' . $previous);
        }
    }
}
