<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\HealthDoctor;
use App\Models\HealthPatient;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Support\HealthPanel;
use App\Support\NestErps;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ErpDemoHospitalSeeder extends Seeder
{
    public const DEMO_ROLES = [
        'reception' => 'health_receptionist', 'doctor' => 'health_doctor',
        'cashier' => 'health_cashier', 'pharmacy' => 'health_pharmacist',
        'lab' => 'health_lab_tech', 'nursing' => 'health_nurse',
        'accounts' => 'health_accountant', 'auditor' => 'health_auditor',
        'hr' => 'health_hr',
    ];

    public function run(): void
    {
        $connection = DB::connection();
        $localDemo = app()->environment('local')
            && $connection->getDriverName() === 'mysql'
            && in_array($connection->getConfig('host'), ['127.0.0.1', '::1', 'localhost'], true)
            && $connection->getDatabaseName() === 'taxnest_erp_demo';
        $isolatedTest = app()->environment('testing')
            && $connection->getDriverName() === 'sqlite'
            && $connection->getDatabaseName() === ':memory:';
        if (!$localDemo && !$isolatedTest) {
            throw new RuntimeException('Use a local, disposable MariaDB database named taxnest_erp_demo.');
        }

        if ($localDemo) {
            if (getenv('ERP_DEMO_ALLOW_LOCAL') !== '1') {
                throw new RuntimeException('Explicit ERP_DEMO_ALLOW_LOCAL=1 is required for the disposable demo.');
            }
            $version = (string) ($connection->selectOne('select version() as version')->version ?? '');
            if (!str_contains($version, 'MariaDB')) {
                throw new RuntimeException('The disposable local Hospital demo requires MariaDB.');
            }
        }

        $password = getenv('ERP_DEMO_PASSWORD');
        if (!is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('Set ERP_DEMO_PASSWORD locally to at least 16 characters.');
        }

        DB::transaction(function () use ($password): void {
            if (Company::withoutGlobalScopes()->exists() || User::withoutGlobalScopes()->exists()
                || HealthDoctor::withoutGlobalScopes()->exists() || HealthPatient::withoutGlobalScopes()->exists()) {
                throw new RuntimeException('The demo fixture requires an empty company and user database.');
            }

            $company = Company::create([
                'name' => 'TaxNest Demo General Hospital',
                'ntn' => 'ERP-DEMO-1',
                'product_type' => HealthPanel::PRODUCT_TYPE,
                NestErps::VERTICAL_COLUMN => NestErps::HEALTH,
                'status' => 'approved',
                'company_status' => 'active',
                'health_org_type' => 'hospital',
                'health_modules' => HealthModuleService::MODULES,
                'fbr_reporting_enabled' => false,
                'fbr_universal_enabled' => false,
                'fbr_pos_enabled' => false,
            ]);

            $plan = PricingPlan::create([
                'name' => 'Demo Hospital Plan',
                'product_type' => HealthPanel::PRODUCT_TYPE,
                'price' => 99999,
                'is_trial' => false,
                'health_modules' => json_encode(HealthModuleService::MODULES),
                'user_limit' => 50,
                'branch_limit' => 5,
                'invoice_limit' => 0,
            ]);

            Subscription::create([
                'company_id' => $company->id,
                'pricing_plan_id' => $plan->id,
                'billing_cycle' => 'annual',
                'active' => true,
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
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

            // Fictional, least-privilege logins for the acceptance checklist only.
            // Same explicit local secret; never committed or printed by this seed.
            $staff = [];
            foreach (self::DEMO_ROLES as $alias => $role) {
                $staff[$alias] = User::create([
                    'name' => 'Demo '.ucfirst($alias),
                    'email' => 'erp.'.$alias.'@example.test',
                    'password' => Hash::make($password),
                    'company_id' => $company->id,
                    'role' => 'employee', 'health_role' => $role, 'is_active' => true,
                ]);
            }

            HealthDoctor::create([
                'user_id' => $staff['doctor']->id,
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
