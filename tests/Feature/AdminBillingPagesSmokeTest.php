<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * SMOKE TEST — Admin billing pages must never 500.
 *
 * The /admin/plans and /admin/subscriptions 500 was only caught manually on
 * the owner's production server. These tests authenticate with the `admin`
 * guard and assert both pages return HTTP 200 — including against a database
 * that is missing the optional `pricing_plans.product_type` column (the
 * classic prod schema-drift scenario the controller degrades gracefully for).
 */
class AdminBillingPagesSmokeTest extends TestCase
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
            $table->string('product_type')->nullable();
            $table->string('company_status')->default('approved');
            $table->softDeletes();
            $table->timestamps();
        });

        $this->createPricingPlansTable(withProductType: true);

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->string('billing_cycle')->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
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

        DB::table('admin_users')->insert([
            'name' => 'Smoke Admin',
            'email' => 'smoke-admin@taxnest.test',
            'password' => Hash::make('Smoke@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPricingPlansTable(bool $withProductType): void
    {
        Schema::dropIfExists('pricing_plans');
        Schema::create('pricing_plans', function (Blueprint $table) use ($withProductType) {
            $table->id();
            $table->string('name');
            if ($withProductType) {
                $table->string('product_type')->default('di');
            }
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->decimal('compare_at', 12, 2)->nullable();
            $table->integer('invoice_limit')->default(0);
            $table->integer('user_limit')->nullable();
            $table->integer('max_terminals')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_products')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('reports_enabled')->default(false);
            $table->boolean('is_trial')->default(false);
            $table->text('features')->nullable();
            $table->timestamps();
        });
    }

    private function seedPlans(bool $withProductType = true): void
    {
        foreach (['di' => 'DI Basic', 'pos' => 'Starter', 'fbrpos' => 'FBR POS Lite'] as $type => $name) {
            $row = [
                'name' => $name,
                'price' => 1000,
                'invoice_limit' => 100,
                'inventory_enabled' => false,
                'reports_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($withProductType) {
                $row['product_type'] = $type;
            }
            DB::table('pricing_plans')->insert($row);
        }
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    /** /admin/plans renders 200 with plans in all three tabs (di / pos / fbrpos). */
    public function test_admin_plans_page_returns_200_with_all_three_tabs(): void
    {
        $this->seedPlans();

        $response = $this->actingAsAdmin()->get('/admin/plans');

        $response->assertStatus(200);
        // Each product tab exists and each seeded plan is rendered in its bucket.
        $response->assertSee('DI Basic');
        $response->assertSee('Starter');
        $response->assertSee('FBR POS Lite');
        $response->assertViewHas('diPlans', fn ($p) => $p->count() === 1 && $p->first()->name === 'DI Basic');
        $response->assertViewHas('posPlans', fn ($p) => $p->count() === 1 && $p->first()->name === 'Starter');
        $response->assertViewHas('fbrposPlans', fn ($p) => $p->count() === 1 && $p->first()->name === 'FBR POS Lite');
    }

    /** /admin/subscriptions renders 200 with data present. */
    public function test_admin_subscriptions_page_returns_200(): void
    {
        $this->seedPlans();
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Smoke Co',
            'product_type' => 'di',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => DB::table('pricing_plans')->value('id'),
            'billing_cycle' => 'monthly',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAsAdmin()->get('/admin/subscriptions');

        $response->assertStatus(200);
        $response->assertSee('Smoke Co');
    }

    /** Subscriptions status filter must not break the page either. */
    public function test_admin_subscriptions_page_returns_200_with_status_filter(): void
    {
        $this->seedPlans();

        $this->actingAsAdmin()->get('/admin/subscriptions?status=active')->assertStatus(200);
    }

    /**
     * GRACEFUL DEGRADE — a database missing the optional
     * pricing_plans.product_type column (prod schema drift) must still
     * render /admin/plans with every plan bucketed under DI, not 500.
     */
    public function test_admin_plans_page_survives_missing_product_type_column(): void
    {
        $this->createPricingPlansTable(withProductType: false);
        $this->seedPlans(withProductType: false);

        $response = $this->actingAsAdmin()->get('/admin/plans');

        $response->assertStatus(200);
        // All plans fall back into the DI bucket; POS/FBR buckets are empty.
        $response->assertViewHas('diPlans', fn ($p) => $p->count() === 3);
        $response->assertViewHas('posPlans', fn ($p) => $p->count() === 0);
        $response->assertViewHas('fbrposPlans', fn ($p) => $p->count() === 0);
    }

    /** Unauthenticated visitors are redirected, never shown the pages. */
    public function test_billing_pages_redirect_guests_to_admin_login(): void
    {
        $this->get('/admin/plans')->assertRedirect('/admin/login');
        $this->get('/admin/subscriptions')->assertRedirect('/admin/login');
    }
}
