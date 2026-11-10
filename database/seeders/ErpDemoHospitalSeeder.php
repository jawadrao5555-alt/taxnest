<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\HealthDoctor;
use App\Models\HealthPatient;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Support\HealthPanel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ErpDemoHospitalSeeder extends Seeder
{
    public function run(): void
    {
        $connection = DB::connection();
        if (!app()->environment('local')
            || !in_array($connection->getConfig('host'), ['127.0.0.1', '::1', 'localhost'], true)
            || $connection->getDatabaseName() !== 'taxnest_erp_demo') {
            throw new RuntimeException('Use a local, disposable MariaDB database named taxnest_erp_demo.');
        }

        $password = getenv('ERP_DEMO_PASSWORD');
        if (!is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('Set ERP_DEMO_PASSWORD locally to at least 16 characters.');
        }

        DB::transaction(function () use ($password): void {
            if (Company::withoutGlobalScopes()->exists() || User::withoutGlobalScopes()->exists()) {
                throw new RuntimeException('The demo fixture requires an empty company and user database.');
            }

            $company = Company::create([
                'name' => 'TaxNest Demo General Hospital',
                'ntn' => 'ERP-DEMO-1',
                'product_type' => HealthPanel::PRODUCT_TYPE,
                'status' => 'approved',
                'company_status' => 'active',
                'health_org_type' => 'hospital',
                'health_modules' => HealthModuleService::MODULES,
                'is_internal_account' => true,
            ]);

            User::create([
                'name' => 'Demo Hospital Owner',
                'email' => 'erp.owner@example.test',
                'password' => Hash::make($password),
                'company_id' => $company->id,
                'role' => 'company_admin',
                'health_role' => HealthAccessService::ROLE_OWNER,
                'is_active' => true,
            ]);

            HealthDoctor::create([
                'company_id' => $company->id,
                'name' => 'Demo Doctor',
                'consultation_fee' => 1000,
                'is_active' => true,
            ]);

            HealthPatient::create([
                'company_id' => $company->id,
                'mrn' => 'DEMO-0001',
                'name' => 'Demo Patient',
                'gender' => 'male',
                'age_years' => 40,
                'is_active' => true,
            ]);
        });
    }
}
