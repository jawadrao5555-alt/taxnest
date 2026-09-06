<?php

namespace Tests\Feature;

use App\Http\Middleware\FeatureEnabled;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Category profiles (Task 1582) — HIDDEN MEANS UNREACHABLE.
 *
 * Hiding a module from nav is not enough: a bookmarked URL, a legacy company
 * switch (companies.inventory_enabled) or an old feature flag left ON must not
 * let a shop use a module its business category does not carry. These tests
 * pin the URL half of the predicate on BOTH panels:
 *
 *   1. Every services / inventory / stock route carries the category gate
 *      (route inspection — a new route added to those families without the
 *      middleware fails here, not on a customer's bookmark).
 *   2. PRA restaurant with the legacy inventory switch ON and the service_jobs
 *      flag ON: /pos/services and /pos/inventory* are refused (redirect home
 *      with the "not for your business" message), reads AND writes.
 *   3. FBR pharmacy: /fbr-pos/services refused; PRA salon with the legacy
 *      inventory switch ON: /pos/inventory refused.
 *   4. The relevance gate lets a shop through once the module is an admin
 *      extra / grandfathered — and the goods family gets stock by profile.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/CategoryModuleUrlGateTest.php --testdox
 */
class CategoryModuleUrlGateTest extends TestCase
{
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        PosFeatureService::flushGateCaches();
        PosFeatureService::assumeExtrasColumn(true);
        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        PosFeatureService::assumeExtrasColumn(null);
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── 1. Route wiring ──────────────────────────────────────────────────────

    public function test_every_services_and_stock_route_carries_the_category_gate(): void
    {
        $expected = [
            'pos.services' => 'service_jobs', 'pos.services.store' => 'service_jobs',
            'pos.services.update' => 'service_jobs', 'pos.services.delete' => 'service_jobs',
            'fbrpos.services' => 'service_jobs', 'fbrpos.services.store' => 'service_jobs',
            'fbrpos.services.update' => 'service_jobs', 'fbrpos.services.delete' => 'service_jobs',
            'pos.products.labels' => 'barcode', 'fbrpos.products.labels' => 'barcode',
        ];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (!$name) {
                continue;
            }
            if (str_starts_with($name, 'pos.inventory.') || str_starts_with($name, 'fbrpos.stock') || $name === 'fbrpos.munafa') {
                $expected[$name] = 'inventory';
            }
        }
        $this->assertGreaterThan(30, count($expected), 'route families under test went missing');

        foreach ($expected as $name => $module) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "route {$name} missing");
            $this->assertContains(
                "feature:{$module},relevant",
                $route->gatherMiddleware(),
                "{$name} is reachable without the {$module} category gate"
            );
        }
    }

    // ── 2. PRA legacy switches ON, still refused ─────────────────────────────

    public function test_pra_shops_cannot_reach_off_category_modules_even_with_legacy_switches_on(): void
    {
        // A restaurant (food family: kitchen + stock, never service jobs) with
        // the service_jobs flag left ON from before the profiles landed.
        $restaurant = $this->company('pos', 'restaurant', [
            'feature_flags' => json_encode(['inventory' => true, 'service_jobs' => true]),
        ]);
        $chef = $this->owner($restaurant);
        $this->actingAs($chef, 'pos')->get('/pos/services')
            ->assertRedirect('/pos/dashboard')
            ->assertSessionHas('error', __('pos.feature_not_for_business'));
        // Writes are refused too — the gate sits in front of the controller.
        $this->actingAs($chef, 'pos')
            ->post('/pos/services', ['name' => 'Home delivery', 'price' => 100])
            ->assertRedirect('/pos/dashboard')
            ->assertSessionHas('error', __('pos.feature_not_for_business'));

        // A salon (services family: no stock) whose legacy inventory column is
        // still ON — the column used to be the only thing the pages checked.
        $salon = $this->company('pos', 'salon', [
            'inventory_enabled' => true,
            'feature_flags' => json_encode(['inventory' => true]),
        ]);
        $stylist = $this->owner($salon);
        foreach (['/pos/inventory', '/pos/inventory/stock', '/pos/inventory/stock-check', '/pos/inventory/transfer'] as $url) {
            $this->actingAs($stylist, 'pos')->get($url)
                ->assertRedirect('/pos/dashboard')
                ->assertSessionHas('error', __('pos.feature_not_for_business'));
        }
        $this->actingAs($stylist, 'pos')->post('/pos/inventory/toggle')->assertRedirect('/pos/dashboard');
        $this->actingAs($stylist, 'pos')->post('/pos/inventory/adjust', ['product_id' => 1, 'quantity' => 5])
            ->assertRedirect('/pos/dashboard');

        // JSON callers get a 403, never a redirect they cannot follow.
        $this->actingAs($stylist, 'pos')->getJson('/pos/inventory/low-stock')->assertStatus(403);
    }

    // ── 3. FBR pharmacy → no services; PRA salon → no stock ──────────────────

    public function test_fbr_pharmacy_cannot_reach_services_and_pra_salon_cannot_reach_stock(): void
    {
        $pharmacy = $this->company('fbrpos', 'pharmacy', [
            'feature_flags' => json_encode(['service_jobs' => true]),
        ]);
        $chemist = $this->owner($pharmacy, 'company_admin');
        $this->actingAs($chemist, 'fbrpos')->get('/fbr-pos/services')
            ->assertRedirect('/fbr-pos/dashboard')
            ->assertSessionHas('error', __('pos.feature_not_for_business'));
        $this->actingAs($chemist, 'fbrpos')
            ->post('/fbr-pos/services', ['name' => 'Consultation', 'price' => 500])
            ->assertRedirect('/fbr-pos/dashboard');

        $salon = $this->company('pos', 'salon', ['inventory_enabled' => true]);
        $stylist = $this->owner($salon);
        $this->actingAs($stylist, 'pos')->get('/pos/inventory')
            ->assertRedirect('/pos/dashboard')
            ->assertSessionHas('error', __('pos.feature_not_for_business'));
        // Barcode labels are a goods-shop page — a salon never prints them.
        $this->actingAs($stylist, 'pos')->get('/pos/products/labels')
            ->assertRedirect('/pos/dashboard')
            ->assertSessionHas('error', __('pos.feature_not_for_business'));
    }

    // ── 4. Profile / extra / grandfathered shops pass the relevance gate ─────

    public function test_relevance_gate_lets_profile_members_extras_and_grandfathered_shops_through(): void
    {
        $grocery = $this->company('fbrpos', 'grocery');
        $this->assertSame('passed', $this->runGate($grocery, 'inventory'), 'goods shop must reach /stock by profile');
        $this->assertNotSame('passed', $this->runGate($grocery, 'service_jobs'));

        $salon = $this->company('pos', 'salon', ['inventory_enabled' => true]);
        $this->assertNotSame('passed', $this->runGate($salon, 'inventory'));

        PosFeatureService::grantExtra($salon->fresh(), 'inventory', 'admin', 'Sells retail products too', 'admin@test');
        $this->assertSame('passed', $this->runGate($salon->fresh(), 'inventory'), 'admin extra must open the URL');

        PosFeatureService::revokeExtra($salon->fresh(), 'inventory');
        $this->assertNotSame('passed', $this->runGate($salon->fresh(), 'inventory'), 'revoke must close it again');

        $restaurant = $this->company('pos', 'restaurant');
        PosFeatureService::grantExtra($restaurant->fresh(), 'service_jobs', 'grandfathered', 'flag stored ON');
        $this->assertSame('passed', $this->runGate($restaurant->fresh(), 'service_jobs'), 'grandfathered shop keeps its URL');
    }

    public function test_rollout_backfill_grandfathers_the_legacy_inventory_switch_and_existing_services(): void
    {
        Schema::create('pos_services', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->timestamps();
        });
        $salon = $this->company('pos', 'salon', ['inventory_enabled' => true]);
        $restaurant = $this->company('pos', 'restaurant');
        DB::table('pos_services')->insert(['company_id' => $restaurant->id, 'name' => 'Birthday setup', 'created_at' => now(), 'updated_at' => now()]);

        $salonOut = \App\Services\PosCategoryRolloutService::outsidersFor($salon->fresh());
        $this->assertArrayHasKey('inventory', $salonOut, 'inventory_enabled column alone must count as evidence');

        $restOut = \App\Services\PosCategoryRolloutService::outsidersFor($restaurant->fresh());
        $this->assertArrayHasKey('service_jobs', $restOut, 'a shop with services rows must keep its services page');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Run the relevance-mode gate for one company, returning 'passed' or the refusal. */
    private function runGate(Company $company, string $module): string
    {
        PosFeatureService::flushGateCaches();
        app()->instance('currentCompanyId', $company->id);
        $prefix = $company->product_type === 'fbrpos' ? '/fbr-pos/x' : '/pos/x';
        $result = (new FeatureEnabled())->handle(Request::create($prefix, 'GET'), fn () => 'passed', $module, 'relevant');
        return $result === 'passed' ? 'passed' : 'refused';
    }

    private function company(string $product, string $category, array $attrs = []): Company
    {
        $id = DB::table('companies')->insertGetId(array_merge([
            'name' => ucfirst($category) . ' ' . ++self::$seq,
            'product_type' => $product,
            'status' => 'active',
            'company_status' => 'active',
            'business_category' => $category,
            'fbr_pos_enabled' => $product === 'fbrpos',
            'inventory_enabled' => false,
            'feature_flags' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
        $plan = DB::table('pricing_plans')->insertGetId([
            'name' => 'Business', 'product_type' => $product, 'inventory_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $id, 'pricing_plan_id' => $plan, 'active' => 1,
            'ends_at' => now()->addYear(), 'end_date' => now()->addYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Company::find($id);
    }

    private function owner(Company $company, string $role = 'user'): User
    {
        return User::create([
            'name' => 'Owner', 'email' => 'owner' . ++self::$seq . '@catgate.test',
            'password' => bcrypt('Secret@12345'), 'company_id' => $company->id,
            'role' => $role, 'pos_role' => 'pos_admin', 'is_active' => true,
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->string('status')->nullable();
            $t->string('company_status')->nullable();
            $t->string('business_category')->nullable();
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('agent_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('restaurant_mode')->default(false);
            $t->boolean('pharmacy_mode')->default(false);
            $t->boolean('kitchen_printer_enabled')->default(false);
            $t->boolean('caller_id_enabled')->default(false);
            $t->text('feature_flags')->nullable();
            $t->text('pos_module_extras')->nullable();
            $t->text('public_profile_settings')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('restaurant_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->integer('invoice_limit')->nullable();
            $t->integer('user_limit')->nullable();
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamps();
        });
    }
}
