<?php

namespace Database\Seeders;

use App\Models\{Branch, HealthProcedure, HealthShift, HealthRosterEntry, HealthDoctorShareRule, Supplier, Company, HealthBed, HealthDepartment, HealthDoctor, HealthOperationTheatre, HealthRoom, HealthWard, PricingPlan, Subscription, User};
use App\Services\{HealthAccessService, HealthChartOfAccountsService, HealthAttendanceService, HealthHrService, HealthPharmacyService, HealthModuleService, HealthPatientService};
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
                        'specialty' => $specialty, 'consultation_fee' => 1500, 'is_active' => true,
                        'user_id' => $code === 'A' && $specialty === 'General Medicine'
                            ? User::where('company_id', $company->id)->where('health_role', 'health_doctor')->value('id') : null]);
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
                    $this->configureGeneralHospital($company, $branch);
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

    private function configureGeneralHospital(Company $hospital, Branch $branch): void
    {
        $id = $hospital->id;
        Supplier::create(['company_id'=>$id, 'name'=>'Demo Medical Supplies', 'is_active'=>true]);
        HealthPharmacyService::createMedicine($id, ['name'=>'Demo pack','code'=>'DEMO-MED','unit_uom'=>'unit',
            'sale_price'=>20, 'cost_price'=>12, 'is_active'=>true]);
        HealthProcedure::create(['company_id'=>$id,'name'=>'Demo C-section','code'=>'CS-DEMO','base_price'=>4000,
            'estimated_minutes'=>60,'is_active'=>true]);
        HealthDoctorShareRule::create(['company_id'=>$id,'name'=>'Demo consultation share','basis'=>'percent',
            'value'=>40,'base'=>'net','priority'=>0,'is_active'=>true]);
        foreach (['lab'=>'Demo blood count', 'radiology'=>'Demo ultrasound'] as $service => $name) {
            DB::table('health_diagnostic_tests')->insert(['company_id'=>$id,'service'=>$service,'name'=>$name,
                'code'=>'DEMO','price'=>650,'created_at'=>now(),'updated_at'=>now()]);
        }
        $nurse = User::where('company_id',$id)->where('health_role','health_nurse')->firstOrFail();
        $profile = HealthHrService::profile($id, $nurse->id);
        $profile->update(['basic_salary'=>30000]);
        $date = today()->subDay();
        $shift = HealthShift::create(['company_id'=>$id,'name'=>'Demo morning shift','start_time'=>'09:00:00',
            'end_time'=>'17:00:00','crosses_midnight'=>false,'break_minutes'=>0,'is_active'=>true]);
        HealthRosterEntry::create(['company_id'=>$id,'user_id'=>$nurse->id,'duty_date'=>$date,'entry_type'=>'shift',
            'health_shift_id'=>$shift->id]);
        foreach (['09:00:00'=>'in','17:00:00'=>'out'] as $time=>$direction) {
            HealthAttendanceService::recordPunch(['company_id'=>$id,'branch_id'=>$branch->id,'user_id'=>$nurse->id,
                'punched_at'=>$date->copy()->setTimeFromTimeString($time), 'direction'=>$direction,'source'=>'biometric']);
        }
        HealthAttendanceService::recomputeDay($id, $nurse->id, $date);
    }

}
