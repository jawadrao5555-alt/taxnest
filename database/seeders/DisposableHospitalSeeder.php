<?php

namespace Database\Seeders;

use App\Models\{AdminUser, Branch, Company, HealthBed, HealthDepartment, HealthDoctor, HealthOperationTheatre, HealthRoom, HealthWard, PricingPlan, Subscription, User};
use App\Services\{HealthAccessService, HealthChartOfAccountsService, HealthModuleService, HealthPatientService};
use App\Support\{DevStagingGuard, NestErps};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use RuntimeException;

final class DisposableHospitalSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local', 'testing') || (string) env('HOSPITAL_DEMO_ALLOW') !== '1'
            || DevStagingGuard::problems() || DB::table('companies')->exists() || DB::table('users')->exists()) {
            throw new RuntimeException('Hospital demo requires an empty, opted-in local disposable MariaDB.');
        }

        DB::transaction(function () {
            AdminUser::create(['name' => 'Demo SaaS Owner', 'email' => 'demo.saas@example.test',
                'role' => 'super_admin', 'password' => env('HOSPITAL_DEMO_PASSWORD') ?: Str::random(40)]);
            $plan = PricingPlan::create([
                'name' => 'Disposable hospital acceptance', 'product_type' => NestErps::PRODUCT_TYPE,
                'health_modules' => HealthModuleService::MODULES, 'user_limit' => 50, 'branch_limit' => 5,
                'invoice_limit' => 0, 'price' => 1000, 'is_trial' => false,
            ]);
            $all = HealthModuleService::MODULES;
            foreach ([
                'A' => ['TaxNest Demo General Hospital', 'hospital', $all],
                'B' => ['Demo Independent Services Hospital', 'hospital', $all],
                'C' => ['Demo Ward Hospital', 'hospital', ['opd', 'ipd', 'accounts', 'hr']],
                'D' => ['Demo OPD Clinic', 'clinic', ['opd']],
                'E' => ['Demo Maternity Home', 'maternity', ['opd', 'ipd', 'accounts', 'hr', 'maternity']],
                'F' => ['Demo Multispecialty Hospital', 'hospital', $all],
                'BP' => ['Demo Independent Pharmacy', 'pharmacy', ['pharmacy', 'accounts']],
                'BL' => ['Demo Independent Laboratory', 'lab', ['lab', 'accounts']],
            ] as $code => [$name, $type, $modules]) {
                $company = Company::create([
                    'name' => $name, 'ntn' => 'DEMO-344-'.$code, 'product_type' => NestErps::PRODUCT_TYPE,
                    NestErps::VERTICAL_COLUMN => NestErps::HEALTH, 'status' => 'approved',
                    'company_status' => 'active', 'health_org_type' => $type, 'health_modules' => $modules,
                    'health_setup_completed' => true, 'fbr_reporting_enabled' => false,
                ]);
                if ($code === 'B') {
                    $company->forceFill(['health_service_modes' => ['pharmacy' => 'independent', 'lab' => 'independent']])->save();
                }
                Subscription::create([
                    'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'active' => true,
                    'start_date' => today(), 'end_date' => today()->addYear(),
                ]);
                $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ',
                    'is_active' => true, 'is_head_office' => true]);
                if ($code === 'F') {
                    Branch::create(['company_id' => $company->id, 'name' => 'Satellite', 'code' => 'SAT', 'is_active' => true]);
                }
                $owner = null;
                foreach (($code === 'A' ? HealthAccessService::ROLES : [HealthAccessService::ROLE_OWNER]) as $role) {
                    $user = User::create([
                        'company_id' => $company->id, 'name' => 'Demo '.$role, 'email' => strtolower($code).'.'.$role.'@example.test',
                        'role' => $role === HealthAccessService::ROLE_OWNER ? 'company_admin' : 'user',
                        'health_role' => $role, 'is_active' => true, 'password' => Hash::make(
                            $role === HealthAccessService::ROLE_OWNER ? env('HOSPITAL_DEMO_PASSWORD') ?: Str::random(40) : Str::random(40)
                        ),
                    ]);
                    if ($role === HealthAccessService::ROLE_OWNER) {
                        $owner = $user;
                    }
                }
                foreach (['General Medicine', 'Obstetrics', 'Surgery'] as $specialty) {
                    HealthDoctor::create(['company_id' => $company->id, 'name' => 'Demo Dr '.$specialty,
                        'specialty' => $specialty, 'consultation_fee' => 1500, 'is_active' => true]);
                }
                HealthPatientService::register($company->id, ['name' => 'Demo Patient '.$code,
                    'gender' => 'female', 'age_years' => 30, 'branch_id' => $branch->id]);
                if (in_array('ipd', $modules, true)) {
                    $ward = HealthWard::create(['company_id' => $company->id, 'branch_id' => $branch->id,
                        'name' => 'Demo Ward', 'code' => 'GW', 'type' => 'general', 'daily_rate' => 3000]);
                    $room = HealthRoom::create(['company_id' => $company->id, 'health_ward_id' => $ward->id,
                        'name' => 'Room 1', 'room_type' => 'general']);
                    HealthBed::create(['company_id' => $company->id, 'health_ward_id' => $ward->id,
                        'health_room_id' => $room->id, 'code' => 'GW-01', 'status' => HealthBed::STATUS_AVAILABLE]);
                    HealthOperationTheatre::create(['company_id' => $company->id, 'branch_id' => $branch->id,
                        'name' => 'Demo Theatre', 'code' => 'OT-01']);
                }
                if ($code === 'A') {
                    foreach (['Reception'=>'opd','Emergency'=>'other','Maternity'=>'ipd','Radiology'=>'radiology',
                        'Pathology'=>'lab','Pharmacy'=>'pharmacy','Accounts'=>'admin'] as $dept => $kind) {
                        HealthDepartment::create(['company_id' => $company->id, 'branch_id' => $branch->id,
                            'name' => $dept, 'type' => $kind, 'is_active' => true]);
                    }
                }
                if (in_array('accounts', $modules, true)) {
                    HealthChartOfAccountsService::seed($company->id, $owner);
                }
            }
        });
    }
}
