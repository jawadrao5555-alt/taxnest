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

    public function test_pra_usage_compares_stored_business_dates_for_the_selected_company_and_branch(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->date('business_date')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
        });
        $this->travelTo(now()->setDate(2024, 2, 29)->startOfDay());
        foreach ([
            [1, 11, '2024-02-29', 1200, false],
            [1, 11, '2023-02-28', 450, false],
            [1, 11, '2023-03-01', 800, false],
            [1, 11, '2024-02-29', 900, true],
            [1, 11, null, 100, false],
            [1, 12, '2024-02-29', 200, false],
            [2, 11, '2024-02-29', 300, false],
            [1, 11, '2024-03-01', 321, false],
        ] as [$company, $branch, $date, $amount, $archived]) {
            DB::table('pos_transactions')->insert([
                'company_id' => $company, 'branch_id' => $branch, 'business_date' => $date,
                'status' => 'completed', 'total_amount' => $amount, 'is_archived' => $archived,
                'created_at' => now()->addDay(),
            ]);
        }

        $admin = AdminUser::create([
            'name' => 'Example Usage Reviewer', 'email' => 'example-usage@example.test',
            'password' => 'local-fixture-password', 'role' => 'super_admin',
        ]);
        $this->actingAs($admin, 'admin')
            ->get('/admin/company-usage?company_id=1&branch_id=11&period=two_year')
            ->assertOk()
            ->assertSee('Last year: 450.00')
            ->assertSee('Download PRA bill totals (CSV)')
            ->assertViewHas('usageStats', fn ($rows) => $rows->count() === 1
                && $rows->first()->total_pos_transactions === 1
                && $rows->first()->total_sales_amount === 1200.0
                && $rows->first()->pra_comparison === ['bills' => 1, 'amount' => 450.0]);

        $this->travel(1)->day();
        $csv = $this->get('/admin/company-usage?company_id=1&branch_id=11&period=two_year&as_of=2024-02-29&export=pra_csv')
            ->assertOk()
            ->assertDownload('pra-bills-1.csv')
            ->streamedContent();
        $this->assertSame([
            'company_id,branch_id,business_date_from,business_date_to,completed_bills,total_pkr',
            '1,11,2024-01-01,2024-02-29,1,1200.00',
            '1,11,2023-01-01,2023-02-28,1,450.00',
        ], explode("\n", trim($csv)));
        $unsupportedExport = '/admin/company-usage?company_id=3&export=pra_csv';
        $this->get($unsupportedExport)->assertRedirect('/admin/dashboard');
        $this->getJson($unsupportedExport)->assertNotFound();

        $this->assertSame(3071.0, CompanyUsageStat::refreshForCompany(1)->total_sales_amount);
    }

    public function test_fbr_pos_usage_and_csv_share_completed_shop_bills_and_business_dates(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->string('pos_type')->nullable());
        DB::table('companies')->insert([
            ['id' => 4, 'name' => 'Example FBR Shop', 'product_type' => 'fbrpos', 'pos_type' => null],
            ['id' => 5, 'name' => 'Example FBR Neighbor', 'product_type' => 'fbrpos', 'pos_type' => null],
            ['id' => 6, 'name' => 'Example School', 'product_type' => 'fbrpos', 'pos_type' => 'education'],
        ]);
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('status');
            $table->date('business_date')->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->timestamps();
        });
        $this->travelTo(now()->setDate(2024, 2, 29)->startOfDay());
        foreach ([
            [4, 11, '2024-02-29', 'completed', 1200],
            [4, 11, '2024-02-15', 'completed', 100],
            [4, 11, '2024-01-15', 'completed', 250],
            [4, 11, '2023-02-28', 'completed', 450],
            [4, 11, '2024-03-01', 'completed', 900],
            [4, 11, '2024-02-29', 'voided', 800],
            [4, 11, null, 'completed', 100],
            [4, 12, '2024-02-29', 'completed', 200],
            [5, 11, '2024-02-29', 'completed', 300],
            [6, 11, '2024-02-29', 'completed', 400],
        ] as [$company, $branch, $day, $status, $amount]) {
            DB::table('fbr_pos_transactions')->insert([
                'company_id' => $company, 'branch_id' => $branch, 'status' => $status,
                'business_date' => $day, 'total_amount' => $amount, 'created_at' => now()->addDay(),
            ]);
        }

        $this->actingAs(AdminUser::create([
            'name' => 'Example FBR Usage Reviewer', 'email' => 'example-fbr-review@example.test',
            'password' => 'local-fixture-password', 'role' => 'super_admin',
        ]), 'admin');
        $url = '/admin/company-usage?product_scope=fbrpos&company_id=4&branch_id=11&period=two_year';
        $this->get($url)->assertOk()->assertSee('Download FBR POS bill totals (CSV)')
            ->assertSee('Last year: 450.00')
            ->assertViewHas('usageStats', fn ($rows) => $rows->count() === 1
                && $rows->first()->fbr_sales === ['bills' => 3, 'amount' => 1550.0]
                && $rows->first()->fbr_comparison === ['bills' => 1, 'amount' => 450.0]);

        $rangeUrl = '/admin/company-usage?product_scope=fbrpos&company_id=4&branch_id=11';
        foreach ([
            ['period=daily', '2024-02-29', '2024-02-29', 1, 1200.0],
            ['period=monthly', '2024-02-01', '2024-02-29', 2, 1300.0],
            ['period=yearly', '2024-01-01', '2024-02-29', 3, 1550.0],
            ['period=custom&date_from=2024-01-15&date_to=2024-02-15', '2024-01-15', '2024-02-15', 2, 350.0],
        ] as [$periodQuery, $from, $to, $bills, $amount]) {
            $reportUrl = $rangeUrl.'&'.$periodQuery;
            $this->get($reportUrl)->assertOk()
                ->assertViewHas('usageStats', fn ($rows) => $rows->count() === 1
                    && $rows->first()->fbr_sales === ['bills' => $bills, 'amount' => $amount]);
            $csv = $this->get($reportUrl.'&export=fbr_csv&as_of='.$to)
                ->assertOk()->assertDownload('fbr-bills-4.csv')->streamedContent();
            $this->assertSame([
                'company_id,branch_id,business_date_from,business_date_to,completed_bills,total_pkr',
                implode(',', [4, 11, $from, $to, $bills, number_format($amount, 2, '.', '')]),
            ], explode("\n", trim($csv)), $periodQuery);
        }

        $this->travel(1)->day();
        $csv = $this->get($url.'&as_of=2024-02-29&export=fbr_csv')->assertOk()
            ->assertDownload('fbr-bills-4.csv')->streamedContent();
        $this->assertSame([
            'company_id,branch_id,business_date_from,business_date_to,completed_bills,total_pkr',
            '4,11,2024-01-01,2024-02-29,3,1550.00',
            '4,11,2023-01-01,2023-02-28,1,450.00',
        ], explode("\n", trim($csv)));

        $this->assertSame(['bills' => 7, 'amount' => 3200.0], CompanyUsageStat::refreshForCompany(4)->fbr_sales);
        $this->getJson('/admin/company-usage?company_id=6&export=fbr_csv')->assertNotFound();
        $this->getJson('/admin/company-usage?company_id=1&export=fbr_csv')->assertNotFound();
    }

    public function test_di_usage_exports_only_stored_production_documents_for_the_selected_company_and_branch(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('status');
            $table->string('fbr_status');
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fiscal_submission_environment')->nullable();
            $table->string('document_type')->nullable();
            $table->decimal('total_amount', 15, 2);
        });
        DB::table('companies')->insert(['id' => 4, 'name' => 'Fictional Neighbor DI', 'product_type' => 'di']);
        $this->travelTo(now()->setDate(2024, 2, 29)->startOfDay());
        foreach ([
            [3, 11, '2024-02-29', 'locked', 'production', 'EXAMPLE-01', 'production', 'Sale Invoice', 700],
            [3, 11, '2024-02-29', 'locked', 'production', 'EXAMPLE-02', 'production', 'Credit Note', 180],
            [3, 11, '2023-02-28', 'locked', 'production', 'EXAMPLE-03', null, null, 200],
            [3, 11, '2024-03-01', 'locked', 'production', 'EXAMPLE-04', 'production', 'Sale Invoice', 400],
            [3, 11, '2024-02-29', 'locked', 'sandbox', 'EXAMPLE-05', 'sandbox', 'Sale Invoice', 900],
            [3, 11, '2024-02-29', 'locked', 'production', 'EXAMPLE-06', 'sandbox', 'Sale Invoice', 999],
            [3, 11, '2024-02-29', 'locked', 'production', ' ', 'production', 'Sale Invoice', 700],
            [3, 11, '2024-02-29', 'draft', 'production', 'EXAMPLE-08', 'production', 'Sale Invoice', 800],
            [3, 12, '2024-02-29', 'locked', 'production', 'EXAMPLE-09', 'production', 'Sale Invoice', 300],
            [4, 11, '2024-02-29', 'locked', 'production', 'EXAMPLE-10', 'production', 'Sale Invoice', 600],
        ] as [$company, $branch, $date, $status, $fbrStatus, $number, $environment, $type, $amount]) {
            DB::table('invoices')->insert([
                'company_id' => $company, 'branch_id' => $branch, 'invoice_date' => $date, 'status' => $status,
                'fbr_status' => $fbrStatus, 'fbr_invoice_number' => $number, 'fiscal_submission_environment' => $environment,
                'document_type' => $type, 'total_amount' => $amount,
            ]);
        }

        $this->actingAs(AdminUser::create([
            'name' => 'Example DI Usage Reviewer', 'email' => 'example-di-review@example.test',
            'password' => 'local-fixture-password', 'role' => 'super_admin',
        ]), 'admin');
        $url = '/admin/company-usage?product_scope=di&company_id=3&branch_id=11&period=two_year';
        $this->get($url)->assertOk()
            ->assertSee('Net document value after credit notes: PKR 520.00')
            ->assertSee('Download DI production document totals (CSV)')
            ->assertViewHas('usageStats', fn ($rows) => $rows->count() === 1
                && $rows->first()->di_documents === ['bills' => 2, 'amount' => 520.0]
                && $rows->first()->di_comparison === ['bills' => 1, 'amount' => 200.0]);

        $this->travel(1)->day();
        $csv = $this->get($url.'&export=di_csv&as_of=2024-02-29')->assertOk()
            ->assertDownload('di-production-3.csv')->streamedContent();
        $this->assertSame([
            'company_id,branch_id,invoice_date_from,invoice_date_to,reported_documents,net_document_value_pkr',
            '3,11,2024-01-01,2024-02-29,2,520.00',
            '3,11,2023-01-01,2023-02-28,1,200.00',
        ], explode("\n", trim($csv)));
        $this->getJson('/admin/company-usage?export=di_csv&company_id=1')->assertNotFound();
    }

    public function test_company_usage_filters_products_and_standalone_collection_categories(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('pos_type')->nullable();
            $table->string('business_category')->nullable();
        });
        DB::table('companies')->insert([
            ['id' => 4, 'name' => 'Example School', 'product_type' => 'fbrpos', 'pos_type' => null, 'business_category' => 'education'],
            ['id' => 5, 'name' => 'Example Hospital', 'product_type' => 'fbrpos', 'pos_type' => 'clinic', 'business_category' => null],
            ['id' => 6, 'name' => 'Example Mixed', 'product_type' => 'fbrpos', 'pos_type' => 'clinic', 'business_category' => 'education'],
            ['id' => 7, 'name' => 'Example Legacy ERP', 'product_type' => 'health', 'pos_type' => null, 'business_category' => 'hospital'],
            ['id' => 8, 'name' => 'Example ERP', 'product_type' => 'erps', 'pos_type' => null, 'business_category' => null],
        ]);
        $this->actingAs(AdminUser::create([
            'name' => 'Example Reviewer', 'email' => 'example-product-review@example.test',
            'password' => 'local-fixture-password', 'role' => 'super_admin',
        ]), 'admin');
        foreach (['pos' => [1, 2], 'school' => [6, 4], 'hospital' => [5], 'erps' => [8, 7]] as $scope => $expected) {
            $this->get('/admin/company-usage?product_scope='.$scope)
                ->assertOk()
                ->assertViewHas('usageStats', fn ($rows) => $rows->pluck('company_id')->all() === $expected);
        }
        $this->get('/admin/company-usage?product_scope=hospital&company_id=4')
            ->assertOk()
            ->assertViewHas('usageStats', fn ($rows) => $rows->isEmpty());
        $this->get('/admin/company-usage?product_scope=unknown')->assertSessionHasErrors('product_scope');
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
