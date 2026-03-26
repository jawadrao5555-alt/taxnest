<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('companies')->where('ntn', '3620344337269')->where('name', 'RAO BROTHERS')->exists();
        if ($exists) {
            return;
        }

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'RAO BROTHERS',
            'owner_name' => 'MUHAMMAD JAWAD SAEED',
            'ntn' => '3620344337269',
            'cnic' => '3620344337269',
            'email' => 'jawadrao5555@gmail.com',
            'phone' => '00923070768585',
            'address' => 'OLD GHALLA MANDI, Lodhran, Lodhran',
            'city' => 'Lodhran',
            'province' => 'Punjab',
            'business_activity' => 'Non-specialized wholesale trade',
            'company_status' => 'active',
            'fbr_environment' => 'production',
            'fbr_registration_no' => '3620344337269',
            'fbr_business_name' => 'RAO BROTHERS',
            'product_type' => 'di',
            'is_internal_account' => true,
            'onboarding_completed' => true,
            'standard_tax_rate' => 18.0,
            'force_watermark' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userExists = DB::table('users')->where('email', 'jawadrao5555@gmail.com')->exists();
        if (!$userExists) {
            DB::table('users')->insert([
                'name' => 'MUHAMMAD JAWAD SAEED',
                'email' => 'jawadrao5555@gmail.com',
                'password' => Hash::make('Admin@12345'),
                'company_id' => $companyId,
                'role' => 'company_admin',
                'is_active' => true,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $enterprisePlan = DB::table('pricing_plans')->where('name', 'Enterprise')->where('product_type', 'di')->value('id');
        if ($enterprisePlan) {
            DB::table('subscriptions')->insert([
                'company_id' => $companyId,
                'pricing_plan_id' => $enterprisePlan,
                'billing_cycle' => 'lifetime',
                'discount_percent' => 100,
                'final_price' => 0,
                'start_date' => now()->toDateString(),
                'end_date' => '2099-12-31',
                'trial_ends_at' => null,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $company = DB::table('companies')->where('ntn', '3620344337269')->where('name', 'RAO BROTHERS')->first();
        if ($company) {
            DB::table('subscriptions')->where('company_id', $company->id)->delete();
            DB::table('users')->where('email', 'jawadrao5555@gmail.com')->where('company_id', $company->id)->delete();
            DB::table('companies')->where('id', $company->id)->delete();
        }
    }
};
