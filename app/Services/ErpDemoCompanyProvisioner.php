<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthDoctor;
use App\Models\HealthLabTest;
use App\Models\HealthPatient;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\HealthPanel;
use App\Support\NestErps;
use App\Support\SchoolErpPanel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ErpDemoCompanyProvisioner
{
    public const TYPES = ['hospital' => 'Hospital', 'lab' => 'Lab', 'school' => 'School'];

    public function provision(AdminUser $admin, string $type, string $requestKey, string $password): Company
    {
        abort_unless($admin->isSuperAdmin(), 403);
        $id = Str::lower($requestKey);
        $reference = 'ERP-DEMO-'.strtoupper($id);
        $email = $type.'.'.$id.'@example.test';
        $name = 'TaxNest '.self::TYPES[$type].' ERP Demo '.strtoupper(substr($id, 0, 8));
        $vertical = $type === 'school' ? NestErps::SCHOOL : NestErps::HEALTH;

        $existing = static function () use ($reference, $email, $vertical, $type): ?Company {
            $company = Company::withTrashed()->where('product_type', NestErps::PRODUCT_TYPE)
                ->where('ntn', $reference)->first();
            if ($company && ($company->email !== $email || $company->erps_vertical !== $vertical
                || ($vertical === NestErps::HEALTH && $company->health_org_type !== $type))) {
                throw ValidationException::withMessages([
                    'demo_type' => 'This demo form was already used. Open a fresh form to create another company.',
                ]);
            }

            return $company;
        };
        if ($company = $existing()) {
            return $company;
        }

        try {
            return DB::transaction(function () use ($admin, $type, $reference, $email, $name, $vertical, $password): Company {
                $modules = $vertical === NestErps::HEALTH ? HealthModuleService::defaultsForOrgType($type) : null;
                $company = Company::create([
                    'name' => $name,
                    'owner_name' => 'Demo owner',
                    'email' => $email,
                    'ntn' => $reference,
                    'product_type' => NestErps::PRODUCT_TYPE,
                    NestErps::VERTICAL_COLUMN => $vertical,
                    'status' => 'approved',
                    'company_status' => 'active',
                    'health_org_type' => $vertical === NestErps::HEALTH ? $type : null,
                    'health_modules' => $modules,
                    'fbr_reporting_enabled' => false,
                    'fbr_universal_enabled' => false,
                    'fbr_pos_enabled' => false,
                    'pra_reporting_enabled' => false,
                ]);
                $owner = User::create([
                    'company_id' => $company->id,
                    'name' => 'Demo owner',
                    'email' => $email,
                    'password' => $password,
                    'role' => 'company_admin',
                    'is_active' => true,
                    'language' => 'en',
                ]);

                // Private zero-rate entitlement supplies the matching capabilities
                // without entering the public package list or default trial selection.
                $plan = PricingPlan::create([
                    'name' => $name.' Access',
                    'product_type' => NestErps::PRODUCT_TYPE,
                    NestErps::VERTICAL_COLUMN => $vertical,
                    'health_modules' => $modules,
                    'health_department_limit' => 5,
                    'is_trial' => false,
                    'is_public' => false,
                    'erp_website_price_visible' => false,
                    'features' => ['erp_demo_access' => true],
                    'price' => 0,
                    'invoice_limit' => 0,
                    'user_limit' => 10,
                    'branch_limit' => 5,
                ]);
                $expires = now()->addDays(14);
                Subscription::create([
                    'company_id' => $company->id,
                    'pricing_plan_id' => $plan->id,
                    'active' => true,
                    'billing_cycle' => 'monthly',
                    'final_price' => 0,
                    'start_date' => now(),
                    'end_date' => $expires,
                    'trial_ends_at' => $expires,
                ]);

                if ($vertical === NestErps::SCHOOL) {
                    $this->seedSchool($company, $owner);
                } else {
                    $this->seedHealthcare($company, $type);
                }

                CredentialLedgerService::record(['email' => $email, 'ntn' => $reference], $company->id, NestErps::PRODUCT_TYPE);
                AdminAuditLog::log($admin->id, 'ERP demo company created', 'Company', $company->id, [
                    'demo_type' => $type,
                    'vertical' => $vertical,
                    'owner_email' => $email,
                ]);

                return $company;
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            return $existing() ?? throw $error;
        }
    }

    public static function loginPath(Company $company): string
    {
        return SchoolErpPanel::ownsCompany($company) ? SchoolErpPanel::LOGIN_PATH : HealthPanel::LOGIN_PATH;
    }

    private function seedSchool(Company $company, User $owner): void
    {
        $workspace = app(SchoolErpWorkspace::class);
        $workspace->addCampus($owner, 'Demo Campus');
        $campus = $workspace->campuses($owner)->sole();
        $workspace->addStudent($owner, [
            'student_reference' => 'DEMO-001',
            'display_name' => 'Fictional student',
            'campus_id' => $campus->id,
        ]);
        $workspace->addTerm($owner, [
            'code' => 'DEMO-TERM',
            'name' => 'Demo Term',
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->addMonths(3)->toDateString(),
        ]);
        $student = DB::table('school_erp_students')->where('company_id', $company->id)->sole();
        $term = DB::table('school_erp_terms')->where('company_id', $company->id)->sole();
        $workspace->admit($owner, [
            'student_id' => $student->id,
            'term_id' => $term->id,
            'admitted_on' => today()->toDateString(),
        ]);
    }

    private function seedHealthcare(Company $company, string $type): void
    {
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Demo Branch',
            'is_active' => true,
            'is_head_office' => true,
        ]);
        HealthPatient::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'mrn' => 'DEMO-001',
            'name' => 'Fictional patient',
            'is_active' => true,
        ]);
        if ($type === 'hospital') {
            HealthDoctor::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'name' => 'Demo doctor',
                'is_active' => true,
            ]);
        }
        HealthLabTest::create([
            'company_id' => $company->id,
            'code' => 'DEMO',
            'name' => 'Demo service',
            'price_paisa' => 0,
            'is_active' => true,
        ]);
    }
}
