<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosPackageEntitlementMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type');
            $table->boolean('riders_enabled')->default(false);
            $table->boolean('qr_menu_enabled')->default(false);
            $table->boolean('hazri_enabled')->default(false);
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->boolean('active')->default(true);
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_addons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('addon_code');
            $table->boolean('active')->default(true);
            $table->date('ends_at')->nullable();
            $table->timestamps();
        });

        foreach (['Trial', 'Starter', 'Business', 'Pro', 'Pro Max', 'Unlimited'] as $name) {
            DB::table('pricing_plans')->insert([
                'name' => $name,
                'product_type' => 'pos',
                'riders_enabled' => true,
                'qr_menu_enabled' => true,
                'hazri_enabled' => true,
            ]);
        }
    }

    public function test_it_applies_the_new_package_matrix(): void
    {
        $this->runMigration();
        $plans = DB::table('pricing_plans')->get()->keyBy('name');

        foreach (['Trial', 'Starter'] as $name) {
            $this->assertSame(0, (int) $plans[$name]->riders_enabled);
            $this->assertSame(0, (int) $plans[$name]->qr_menu_enabled);
            $this->assertSame(0, (int) $plans[$name]->hazri_enabled);
        }
        $this->assertSame(1, (int) $plans['Business']->riders_enabled);
        $this->assertSame(1, (int) $plans['Business']->qr_menu_enabled);
        $this->assertSame(0, (int) $plans['Business']->hazri_enabled);

        foreach (['Pro', 'Pro Max', 'Unlimited'] as $name) {
            $this->assertSame(1, (int) $plans[$name]->riders_enabled);
            $this->assertSame(1, (int) $plans[$name]->qr_menu_enabled);
            $this->assertSame(1, (int) $plans[$name]->hazri_enabled);
        }
    }

    public function test_live_business_hazri_buyers_move_to_pro_and_retired_rows_are_kept_inactive(): void
    {
        $businessId = DB::table('pricing_plans')->where('name', 'Business')->value('id');
        $proId = DB::table('pricing_plans')->where('name', 'Pro')->value('id');

        $subscriptionIds = [];
        foreach ([101, 102, 103] as $companyId) {
            $subscriptionIds[$companyId] = DB::table('subscriptions')->insertGetId([
                'company_id' => $companyId,
                'pricing_plan_id' => $businessId,
                'active' => true,
                'end_date' => now()->addYear()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $historicalSubscriptionId = DB::table('subscriptions')->insertGetId([
            'company_id' => 101,
            'pricing_plan_id' => $businessId,
            'active' => true,
            'end_date' => now()->subMonth()->toDateString(),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);
        DB::table('pos_addons')->insert([
            ['company_id' => 101, 'addon_code' => 'staff_attendance', 'active' => true, 'ends_at' => now()->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 102, 'addon_code' => 'staff_attendance', 'active' => true, 'ends_at' => now()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 103, 'addon_code' => 'delivery_riders', 'active' => true, 'ends_at' => now()->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 103, 'addon_code' => 'caller_id', 'active' => true, 'ends_at' => now()->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->runMigration();

        $this->assertSame($proId, DB::table('subscriptions')->where('id', $subscriptionIds[101])->value('pricing_plan_id'));
        $this->assertSame($businessId, DB::table('subscriptions')->where('id', $historicalSubscriptionId)->value('pricing_plan_id'));
        $this->assertSame($businessId, DB::table('subscriptions')->where('id', $subscriptionIds[102])->value('pricing_plan_id'));
        $this->assertSame($businessId, DB::table('subscriptions')->where('id', $subscriptionIds[103])->value('pricing_plan_id'));
        $this->assertSame(
            0,
            DB::table('pos_addons')->whereIn('addon_code', ['delivery_riders', 'qr_menu', 'staff_attendance'])->where('active', 1)->count()
        );
        $this->assertSame(1, (int) DB::table('pos_addons')->where('addon_code', 'caller_id')->value('active'));
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_12_130000_include_riders_qr_and_hazri_in_pos_packages.php');
        $migration->up();
    }
}