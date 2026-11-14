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
}
