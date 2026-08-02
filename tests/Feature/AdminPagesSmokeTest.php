<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * SMOKE TEST — Remaining admin panel pages must never 500.
 *
 * Task #7 locked /admin/plans and /admin/subscriptions (see
 * AdminBillingPagesSmokeTest). These tests cover the other key /admin/* GET
 * pages — dashboard, companies (list + create), sales, franchises,
 * payment-proofs, audit-logs, company-usage, system-control — with the same
 * minimal-schema pattern, both with data present and against the classic
 * prod schema-drift scenario (optional tables missing entirely).
 */
class AdminPagesSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('franchises', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('di');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->integer('invoice_limit')->default(0);
            $table->boolean('is_trial')->default(false);
            $table->text('features')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->string('billing_cycle')->nullable();
            $table->decimal('final_price', 12, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamp('auto_access_until')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('scope')->default('all');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('system_controls', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        DB::table('admin_users')->insert([
            'name' => 'Smoke Admin',
            'email' => 'smoke-admin@taxnest.test',
            'password' => Hash::make('Smoke@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    /** Seed one company of each product type with subscription + activity rows. */
    private function seedData(): void
    {
        $franchiseId = DB::table('franchises')->insertGetId([
            'name' => 'Smoke Franchise', 'email' => 'franchise@taxnest.test',
            'commission_rate' => 10, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Smoke Plan', 'product_type' => 'di', 'price' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['di' => 'Smoke DI Co', 'pos' => 'Smoke POS Co', 'fbrpos' => 'Smoke FBR Co'] as $type => $name) {
            $companyId = DB::table('companies')->insertGetId([
                'name' => $name, 'owner_name' => 'Owner', 'product_type' => $type,
                'status' => 'approved', 'company_status' => 'active',
                'franchise_id' => $franchiseId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('subscriptions')->insert([
                'company_id' => $companyId, 'pricing_plan_id' => $planId,
                'billing_cycle' => 'monthly', 'active' => true,
                'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('users')->insert([
                'name' => "$name Admin", 'email' => "$type-admin@taxnest.test",
                'password' => Hash::make('Secret@123'), 'company_id' => $companyId,
                'role' => 'company_admin', 'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($type === 'di') {
                DB::table('invoices')->insert([
                    'company_id' => $companyId, 'status' => 'locked', 'total_amount' => 500,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('payment_proofs')->insert([
                    'company_id' => $companyId, 'pricing_plan_id' => $planId,
                    'status' => 'pending', 'file_path' => 'proofs/smoke.jpg',
                    'original_name' => 'smoke.jpg',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif ($type === 'pos') {
                DB::table('pos_transactions')->insert([
                    'company_id' => $companyId, 'status' => 'completed', 'total_amount' => 750,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('fbr_pos_transactions')->insert([
                    'company_id' => $companyId, 'status' => 'completed', 'total_amount' => 900,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        DB::table('sale_campaigns')->insert([
            'name' => 'Smoke Sale', 'scope' => 'all', 'discount_percent' => 25,
            'starts_at' => now()->subDay(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** /admin/dashboard renders 200 with data across all three products. */
    public function test_admin_dashboard_returns_200_with_data(): void
    {
        $this->seedData();

        $response = $this->actingAsAdmin()->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Smoke DI Co');
        $response->assertSee('Smoke POS Co');
        $response->assertSee('Smoke FBR Co');
    }

    /** /admin/dashboard renders 200 even on a completely empty database. */
    public function test_admin_dashboard_returns_200_with_no_data(): void
    {
        $this->actingAsAdmin()->get('/admin/dashboard')->assertStatus(200);
    }

    /** /admin/companies list renders 200 with and without filters. */
    public function test_admin_companies_page_returns_200(): void
    {
        $this->seedData();

        $response = $this->actingAsAdmin()->get('/admin/companies');
        $response->assertStatus(200);
        $response->assertSee('Smoke DI Co');

        $this->actingAsAdmin()
            ->get('/admin/companies?product_type=pos&status=approved&search=Smoke')
            ->assertStatus(200);
    }

    /** /admin/companies/create form renders 200. */
    public function test_admin_companies_create_page_returns_200(): void
    {
        $this->seedData();

        $this->actingAsAdmin()->get('/admin/companies/create')->assertStatus(200);
    }

    /** /admin/sales renders 200 with a campaign listed. */
    public function test_admin_sales_page_returns_200(): void
    {
        $this->seedData();

        $response = $this->actingAsAdmin()->get('/admin/sales');
        $response->assertStatus(200);
        $response->assertSee('Smoke Sale');
    }

    /** /admin/sales survives a missing sale_campaigns table (prod drift). */
    public function test_admin_sales_page_survives_missing_table(): void
    {
        Schema::dropIfExists('sale_campaigns');

        $this->actingAsAdmin()->get('/admin/sales')->assertStatus(200);
    }

    /** /admin/franchises renders 200 with a franchise listed. */
    public function test_admin_franchises_page_returns_200(): void
    {
        $this->seedData();

        $response = $this->actingAsAdmin()->get('/admin/franchises');
        $response->assertStatus(200);
        $response->assertSee('Smoke Franchise');
    }

    /** /admin/payment-proofs renders 200 with a pending proof + status filters. */
    public function test_admin_payment_proofs_page_returns_200(): void
    {
        $this->seedData();

        $response = $this->actingAsAdmin()->get('/admin/payment-proofs');
        $response->assertStatus(200);
        $response->assertSee('Smoke DI Co');

        $this->actingAsAdmin()->get('/admin/payment-proofs?status=verified')->assertStatus(200);
        $this->actingAsAdmin()->get('/admin/payment-proofs?status=rejected')->assertStatus(200);
    }

    /** /admin/payment-proofs survives a missing payment_proofs table (prod drift). */
    public function test_admin_payment_proofs_page_survives_missing_table(): void
    {
        Schema::dropIfExists('payment_proofs');

        $this->actingAsAdmin()->get('/admin/payment-proofs')->assertStatus(200);
    }

    /** /admin/dashboard also survives missing optional tables (prod drift). */
    public function test_admin_dashboard_survives_missing_optional_tables(): void
    {
        Schema::dropIfExists('payment_proofs');
        Schema::dropIfExists('system_controls');

        $this->actingAsAdmin()->get('/admin/dashboard')->assertStatus(200);
    }

    /** /admin/audit-logs renders 200 with and without filters. */
    public function test_admin_audit_logs_page_returns_200(): void
    {
        DB::table('admin_audit_logs')->insert([
            'admin_user_id' => 1, 'action' => 'Smoke action',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAsAdmin()->get('/admin/audit-logs');
        $response->assertStatus(200);
        $response->assertSee('Smoke action');

        $this->actingAsAdmin()
            ->get('/admin/audit-logs?action=Smoke&date_from=2020-01-01&date_to=2030-01-01')
            ->assertStatus(200);
    }

    /** /admin/company-usage survives a missing company_usage_stats table (prod drift). */
    public function test_admin_company_usage_survives_missing_table(): void
    {
        $this->actingAsAdmin()->get('/admin/company-usage')->assertStatus(200);
    }

    /** /admin/system-control renders 200. */
    public function test_admin_system_control_page_returns_200(): void
    {
        DB::table('system_controls')->insert([
            'key' => 'pos_enabled', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsAdmin()->get('/admin/system-control')->assertStatus(200);
    }

    /** Unauthenticated visitors are redirected to admin login, never shown pages. */
    public function test_admin_pages_redirect_guests_to_admin_login(): void
    {
        foreach ([
            '/admin/dashboard',
            '/admin/companies',
            '/admin/companies/create',
            '/admin/sales',
            '/admin/franchises',
            '/admin/payment-proofs',
            '/admin/audit-logs',
            '/admin/company-usage',
            '/admin/system-control',
        ] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
    }
}
