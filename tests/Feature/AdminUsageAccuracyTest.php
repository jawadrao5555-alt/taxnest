<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\CompanyUsageStat;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminUsageAccuracyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('product_type');
            $table->softDeletes(); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id');
            $table->timestamp('last_login_at')->nullable();
        });
        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id');
            $table->timestamp('last_activity_at')->nullable();
        });
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('status');
            $table->decimal('total_amount', 15, 2); $table->timestamps();
            $table->boolean('is_archived')->nullable()->default(false);
        });
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->boolean('is_active');
        });
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id');
        });
        Schema::create('company_usage_stats', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id');
            $table->integer('total_pos_transactions')->default(0);
            $table->decimal('total_sales_amount', 15, 2)->default(0);
            $table->integer('active_terminals')->default(0);
            $table->integer('active_users')->default(0);
            $table->integer('inventory_items')->default(0);
            $table->timestamp('last_activity_at')->nullable(); $table->timestamps();
        });
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email');
            $table->string('password'); $table->string('role'); $table->timestamps();
        });
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Alpha POS', 'product_type' => 'pos'],
            ['id' => 2, 'name' => 'Beta POS', 'product_type' => 'pos'],
            ['id' => 3, 'name' => 'Gamma DI', 'product_type' => 'di'],
        ]);
    }

    public function test_reading_company_usage_does_not_forge_activity_or_write_statistics(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->startOfDay());
        DB::table('users')->insert(['company_id' => 1, 'last_login_at' => now()->subDays(5)]);
        DB::table('pos_user_sessions')->insert(['company_id' => 1, 'last_activity_at' => now()->subDays(2)]);
        DB::table('company_usage_stats')->insert(['company_id' => 1, 'last_activity_at' => now()]);
        $before = DB::table('company_usage_stats')->get()->toJson();

        $snapshot = CompanyUsageStat::refreshForCompany(1);
        $this->assertSame(now()->subDays(2)->toDateTimeString(), $snapshot->last_activity_at->toDateTimeString());
        $this->travel(1)->hours();
        $again = CompanyUsageStat::refreshForCompany(1);
        $this->assertTrue($snapshot->last_activity_at->equalTo($again->last_activity_at));
        $this->assertSame($before, DB::table('company_usage_stats')->get()->toJson());
        $this->assertNull(CompanyUsageStat::refreshForCompany(2)->last_activity_at);
        $this->assertSame(1, DB::table('company_usage_stats')->count());
    }

    public function test_counts_and_activity_are_company_scoped_and_non_pra_values_are_unavailable(): void
    {
        DB::table('pos_transactions')->insert([
            ['company_id' => 1, 'status' => 'completed', 'total_amount' => 12.34],
            ['company_id' => 1, 'status' => 'draft', 'total_amount' => 999],
            ['company_id' => 2, 'status' => 'completed', 'total_amount' => 500],
            ['company_id' => 3, 'status' => 'completed', 'total_amount' => 900],
        ]);
        DB::table('users')->insert(['company_id' => 1]);
        DB::table('pos_transactions')->insert([
            'company_id' => 1, 'status' => 'completed', 'total_amount' => 800, 'is_archived' => true,
        ]);
        DB::table('pos_user_sessions')->insert(['company_id' => 2, 'last_activity_at' => now()]);
        DB::table('pos_terminals')->insert([
            ['company_id' => 1, 'is_active' => true], ['company_id' => 1, 'is_active' => false],
            ['company_id' => 2, 'is_active' => true],
        ]);
        $rows = CompanyUsageStat::snapshotsForCompanies(Company::orderBy('id')->get())->keyBy('company_id');
        $this->assertSame(1, $rows[1]->total_pos_transactions);
        $this->assertSame(12.34, $rows[1]->total_sales_amount);
        $this->assertSame(1, $rows[1]->active_terminals);
        $this->assertSame(1, $rows[1]->active_users);
        $this->assertNull($rows[1]->last_activity_at);
        $this->assertSame(500.0, $rows[2]->total_sales_amount);
        $this->assertNull($rows[3]->total_pos_transactions);
        $this->assertNull($rows[3]->total_sales_amount);
        $this->assertSame(0, DB::table('company_usage_stats')->count());
    }

    public function test_missing_source_is_unavailable_and_a_real_empty_source_is_zero(): void
    {
        Schema::drop('pos_transactions');
        Schema::drop('pos_terminals');
        $row = CompanyUsageStat::refreshForCompany(1);
        $this->assertNull($row->total_pos_transactions);
        $this->assertNull($row->total_sales_amount);
        $this->assertNull($row->active_terminals);
        $this->assertSame(0, $row->active_users);
        $this->assertSame(0, $row->inventory_items);
    }

    public function test_page_is_paginated_and_does_not_require_a_cached_usage_table(): void
    {
        Schema::drop('company_usage_stats');
        $extra = [];
        for ($i = 4; $i <= 55; $i++) {
            $extra[] = ['id' => $i, 'name' => sprintf('Shop %03d', $i), 'product_type' => 'pos'];
        }
        DB::table('companies')->insert($extra);
        $admin = AdminUser::create([
            'name' => 'Usage Test Admin', 'email' => 'usage-admin@example.test',
            'password' => 'local-fixture-password', 'role' => 'super_admin',
        ]);
        $this->actingAs($admin, 'admin')->get('/admin/company-usage')->assertOk()
            ->assertViewHas('companies', fn ($page) => $page->total() === 55 && $page->count() === 50)
            ->assertViewHas('usageStats', fn ($rows) => $rows->count() === 50)
            ->assertSee('Last Staff Activity');
        $this->get('/admin/company-usage?page=2')->assertOk()
            ->assertViewHas('usageStats', fn ($rows) => $rows->count() === 5);
    }
}
